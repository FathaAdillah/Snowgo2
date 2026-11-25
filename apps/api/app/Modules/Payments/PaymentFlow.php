<?php

namespace App\Modules\Payments;

use App\Modules\Foundation\Audit\Journal;
use App\Modules\Foundation\Audit\Subject;
use App\Modules\Foundation\Files\StoredFiles;
use App\Modules\Foundation\Outbox\Outbox;
use App\Modules\Inventory\StockReservations;
use App\Support\Http\ApiProblem;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class PaymentFlow
{
    public const LIVE = ['UNPAID', 'WAITING_CONFIRMATION', 'UNDER_REVIEW'];

    /** @param list<string> $numbers */
    public static function create(int $owner, array $numbers, string $bankCode): string
    {
        $query = DB::table('invoices as i')->join('orders as o', 'o.id', '=', 'i.order_id')->where('o.user_id', $owner)->whereIn('i.number', $numbers);
        $orders = DB::table('orders')->whereIn('id', (clone $query)->select('o.id'))->orderBy('id')->lockForUpdate()->get()->keyBy('id');
        $invoices = $query->select('i.*')->orderBy('i.id')->lock('FOR UPDATE OF i')->get();
        abort_unless($invoices->count() === count($numbers), 404);
        $deadline = null;
        foreach ($invoices as $invoice) {
            $order = $orders[$invoice->order_id];
            $due = $invoice->due_at;
            if ($order->status === 'PENDING_PAYMENT') {
                $extended = DB::table('payment_hold_extensions')->where('order_id', $order->id)->value('expires_at');
                $due = $extended ?? min($due, $order->payment_due_at);
            }
            if ($invoice->status !== 'ISSUED' || $invoice->currency !== 'IDR' || ! in_array($order->status, ['PENDING_PAYMENT', 'CONFIRMED'], true)
                || self::due($due) || DB::table('payment_allocations as a')->join('payments as p', 'p.id', '=', 'a.payment_id')
                    ->where('a.invoice_id', $invoice->id)->where(fn ($q) => $q->whereIn('p.status', self::LIVE)->orWhereNotNull('a.applied_at'))->exists()) {
                throw new ApiProblem(409, 'INVOICE_NOT_PAYABLE', 'Invoice is not available for a new payment.');
            }
            $deadline = $deadline === null ? $due : min($deadline, $due);
        }
        $bank = DB::table('bank_accounts')->where(['code' => $bankCode, 'active' => true])->sharedLock()->first() ?? abort(404);
        $instruction = ['bank_code' => $bank->bank_code, 'holder_name' => $bank->holder_name, 'account_number' => Crypt::decryptString($bank->account_ref_ciphertext)];
        $number = 'PAY-'.Str::ulid();
        $id = DB::table('payments')->insertGetId(['number' => $number, 'user_id' => $owner, 'bank_account_id' => $bank->id,
            'amount' => $invoices->sum('total_amount'), 'expires_at' => $deadline, 'instruction_snapshot_ciphertext' => Crypt::encryptString(json_encode($instruction, JSON_THROW_ON_ERROR))]);
        foreach ($invoices as $invoice) {
            DB::table('payment_allocations')->insert(['payment_id' => $id, 'invoice_id' => $invoice->id, 'amount' => $invoice->total_amount]);
        }
        self::record($id, null, 'UNPAID', 'PaymentIntentCreated', $owner);

        return $number;
    }

    /** @return array{\stdClass,Collection<int,\stdClass>} */
    public static function lock(string $number, ?int $owner): array
    {
        $query = DB::table('payments')->where('number', $number)->when($owner !== null, fn ($q) => $q->where('user_id', $owner));
        $id = (clone $query)->value('id') ?? abort(404);
        $orderIds = DB::table('payment_allocations as a')->join('invoices as i', 'i.id', '=', 'a.invoice_id')->where('payment_id', $id)->select('i.order_id');
        $orders = DB::table('orders')->whereIn('id', $orderIds)->orderBy('id')->lockForUpdate()->get();
        DB::table('invoices')->whereIn('id', DB::table('payment_allocations')->where('payment_id', $id)->select('invoice_id'))->orderBy('id')->lockForUpdate()->get();

        return [$query->lockForUpdate()->firstOrFail(), $orders];
    }

    public static function submit(string $number, int $actor, string $file): void
    {
        [$p,$orders] = self::lock($number, $actor);
        if (! in_array($p->status, ['UNPAID', 'WAITING_CONFIRMATION'], true) || self::due($p->expires_at)
            || DB::table('invoices as i')->join('payment_allocations as a', 'i.id', '=', 'a.invoice_id')->where('a.payment_id', $p->id)->where('i.status', '!=', 'ISSUED')->exists()) {
            throw self::invalid();
        }
        $ready = app(StoredFiles::class)->requireReady($file, 'payment', $p->id, 'payment_proof');
        if ($ready->visibility !== 'PRIVATE' || $ready->uploaded_by !== $actor) {
            throw self::invalid();
        }
        if (DB::table('payment_proofs')->where('file_id', $ready->id)->exists()) {
            return;
        }
        $proof = DB::table('payment_proofs')->insertGetId(['payment_id' => $p->id, 'file_id' => $ready->id, 'submitted_by' => $actor]);
        if ($p->submitted_at !== null) {
            app(Journal::class)->audit('PaymentProofAdded', Subject::Payment, $p->id, $actor);
            app(Outbox::class)->record('PaymentProofAdded', 'payment', $p->id, ['proof_id' => $proof]);

            return;
        }
        $submitted = now();
        $newDue = $submitted->copy()->addDay()->toISOString();
        $deadline = $newDue;
        foreach ($orders as $order) {
            if (! in_array($order->status, ['CONFIRMED', 'PENDING_PAYMENT'], true)) {
                throw self::invalid();
            }
            if ($order->status === 'PENDING_PAYMENT') {
                $oldDue = DB::table('payment_hold_extensions')->where('order_id', $order->id)->value('expires_at');
                if ($oldDue === null) {
                    if (self::due($order->payment_due_at)) {
                        throw self::invalid();
                    }
                    if ($order->selling_method === 'READY_TO_SHIP') {
                        app(StockReservations::class)->extendForProof($order->id, $proof);
                    } elseif ($order->selling_method === 'PREORDER') {
                        $holds = DB::table('preorder_allocations')->whereIn('order_item_id', DB::table('order_items')->where('order_id', $order->id)->select('id'));
                        if (! (clone $holds)->exists() || (clone $holds)->where(fn ($q) => $q->where('status', '!=', 'RESERVED')->orWhereRaw('expires_at <= clock_timestamp()'))->exists()) {
                            throw self::invalid();
                        }
                        $holds->update(['expires_at' => $newDue]);
                    }
                    DB::table('payment_hold_extensions')->insert(['order_id' => $order->id, 'proof_id' => $proof, 'expires_at' => $newDue]);
                } else {
                    if (self::due($oldDue)) {
                        throw self::invalid();
                    }
                    $deadline = min($deadline, Carbon::parse($oldDue)->toISOString());
                }
            }
            DB::table('order_transition_holds')->insert(['order_id' => $order->id, 'kind' => 'PROOF_REVIEW', 'source_ref' => $number]);
        }
        self::change($p, 'WAITING_CONFIRMATION', 'PaymentSubmitted', $actor, ['expires_at' => $deadline, 'submitted_at' => $submitted]);
    }

    public static function claim(string $number, int $actor, string $action): void
    {
        [$p] = self::lock($number, null);
        if (self::due($p->expires_at)) {
            throw self::invalid();
        }
        if ($action === 'RELEASE') {
            self::requireClaim($p, $actor);
            self::change($p, 'WAITING_CONFIRMATION', 'PaymentReviewReleased', $actor, ['claim_by' => null, 'claim_until' => null]);
        } else {
            if ($p->status === 'UNDER_REVIEW' && ! self::due($p->claim_until)) {
                if ($p->claim_by !== $actor) {
                    throw self::invalid();
                }

                return;
            }
            if (! in_array($p->status, ['WAITING_CONFIRMATION', 'UNDER_REVIEW'], true)) {
                throw self::invalid();
            }
            self::change($p, 'UNDER_REVIEW', 'PaymentReviewClaimed', $actor, ['claim_by' => $actor, 'claim_until' => now()->addMinutes(15)]);
        }
    }

    public static function requireClaim(object $payment, int $actor): void
    {
        if ($payment->status !== 'UNDER_REVIEW' || $payment->claim_by !== $actor || self::due($payment->claim_until) || self::due($payment->expires_at)) {
            throw self::invalid();
        }
    }

    public static function finish(string $number, int $actor, ?string $reason): void
    {
        [$p] = self::lock($number, $reason === null ? $actor : null);
        if ($reason !== null) {
            self::requireClaim($p, $actor);
            $id = DB::table('reason_codes')->where(['domain' => 'payments', 'code' => $reason, 'active' => true])->sharedLock()->value('id') ?? throw self::invalid();
            self::change($p, 'REJECTED', 'PaymentRejected', $actor, ['rejection_reason_id' => $id, 'verified_by' => $actor, 'verified_at' => now(), 'claim_by' => null, 'claim_until' => null]);
        } else {
            if ($p->status !== 'UNPAID') {
                throw self::invalid();
            }
            self::change($p, 'CANCELLED', 'PaymentIntentCancelled', $actor);
        }
        self::clearHolds($p);
    }

    public static function clearHolds(object $payment): void
    {
        DB::table('order_transition_holds')->where(['kind' => 'PROOF_REVIEW', 'source_ref' => $payment->number])->delete();
        DB::table('stock_expiry_work')->whereIn('proof_id', DB::table('payment_proofs')->where('payment_id', $payment->id)->select('id'))->update(['proof_id' => null]);
    }

    public static function expireDue(): int
    {
        $count = 0;
        foreach (DB::table('payments')->whereIn('status', self::LIVE)->whereRaw('expires_at <= clock_timestamp()')->orderBy('expires_at')->limit(100)->pluck('number') as $number) {
            $count += DB::transaction(function () use ($number) {
                [$p] = self::lock($number, null);
                if (! in_array($p->status, self::LIVE, true) || ! self::due($p->expires_at)) {
                    return 0;
                }
                self::change($p, 'EXPIRED', 'PaymentExpired', null, ['claim_by' => null, 'claim_until' => null]);
                self::clearHolds($p);

                return 1;
            });
        }

        return $count;
    }

    /** @param array<string,mixed> $columns */
    public static function change(object $p, string $to, string $event, ?int $actor, array $columns = []): void
    {
        DB::table('payments')->where('id', $p->id)->update(['status' => $to] + $columns);
        self::record($p->id, $p->status, $to, $event, $actor);
    }

    private static function record(int $id, ?string $from, string $to, string $event, ?int $actor): void
    {
        app(Journal::class)->transition(Subject::Payment, $id, 'status', $from, $to, (string) Str::ulid(), $actor);
        app(Journal::class)->audit($event, Subject::Payment, $id, $actor, $from ? ['status' => $from] : null, ['status' => $to]);
        app(Outbox::class)->record($event,'payment',$id,['status' => $to]);
    }

    public static function due(string $time): bool
    {
        return DB::scalar('SELECT ?::timestamptz <= clock_timestamp()',[$time]);
    }

    public static function invalid(): ApiProblem
    {
        return new ApiProblem(409,'INVALID_STATE_TRANSITION','Payment state or review lease does not allow this action.');
    }
}
