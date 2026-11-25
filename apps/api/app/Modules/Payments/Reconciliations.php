<?php

namespace App\Modules\Payments;

use App\Modules\Foundation\Audit\Journal;
use App\Modules\Foundation\Audit\Subject;
use App\Modules\Foundation\Files\StoredFiles;
use App\Modules\Foundation\Outbox\Outbox;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class Reconciliations
{
    public static function rows(int $actor): Builder
    {
        return DB::table('payment_reconciliations as r')->join('payments as p', 'p.id', '=', 'r.payment_id')->join('reason_codes as c', 'c.id', '=', 'r.reason_id')
            ->select('r.id', 'p.number as payment_number', 'r.amount', 'r.status', 'r.bank_tx_ref', 'c.code as reason_code', 'r.refund_transfer_ref', 'r.review_note', 'r.decision_note')
            ->selectRaw('r.handled_by = ? as opened_by_me,coalesce(r.reviewed_by = ?,false) as reviewed_by_me', [$actor, $actor]);
    }

    private static function reason(string $code): int
    {
        return DB::table('reason_codes')->where(['domain' => 'payments', 'code' => $code, 'active' => true])->sharedLock()->value('id') ?? throw PaymentFlow::invalid();
    }

    /** @param array<string,mixed> $input */
    public static function open(string $number, int $actor, array $input): int
    {
        [$p,$orders] = PaymentFlow::lock($number, null);
        DB::table('bank_accounts')->where('id', $p->bank_account_id)->lockForUpdate()->firstOrFail();
        $ref = ['bank_account_id' => $p->bank_account_id, 'bank_tx_ref' => mb_strtoupper(trim($input['bank_tx_ref']))];
        Settlement::guardReceipt($p, $ref['bank_tx_ref']);
        $id = DB::table('payment_reconciliations')->insertGetId($ref + ['payment_id' => $p->id, 'order_id' => $orders->firstOrFail()->id, 'handled_by' => $actor, 'amount' => $input['received_amount'], 'reason_id' => self::reason($input['reason_code'])]);
        self::record($id, null, 'OPEN', $actor, 'PaymentReconciliationOpened');

        return $id;
    }

    /** @param array<string,mixed> $input */
    public static function act(int $id, int $actor, array $input): void
    {
        $r = DB::table('payment_reconciliations')->where('id', $id)->lockForUpdate()->first() ?? abort(404);
        $action = $input['decision'];
        if (in_array($action, ['APPROVE', 'REJECT'], true)) {
            abort_if($actor === $r->handled_by, 403);
            if ($r->status !== 'OPEN') {
                throw PaymentFlow::invalid();
            }
            $status = $action === 'APPROVE' ? 'APPROVED' : 'REJECTED';
            $columns = ['reviewed_by' => $actor, 'review_note' => $input['note'], 'review_reason_id' => isset($input['reason_code']) ? self::reason($input['reason_code']) : null];
            $event = 'ReconciliationReviewed';
        } else {
            abort_if($actor === $r->reviewed_by, 403);
            if ($r->status !== 'APPROVED') {
                throw PaymentFlow::invalid();
            }
            $columns = ['finalized_by' => $actor, 'decision_note' => $input['note']];
            if ($action === 'APPLY') {
                Settlement::capture(DB::table('payments')->where('id', $r->payment_id)->value('number'), $actor, $r->bank_tx_ref, $r->amount, $r);
                $status = 'APPLIED';
                $event = 'ReconciliationApplied';
            } else {
                $proof = app(StoredFiles::class)->requireReady($input['proof_file_id'], 'payment_reconciliation', $id, 'reconciliation_proof');
                if ($proof->visibility !== 'PRIVATE' || $proof->uploaded_by !== $actor) {
                    throw PaymentFlow::invalid();
                }
                $ref = mb_strtoupper(trim($input['refund_transfer_ref']));
                DB::select('SELECT pg_advisory_xact_lock(hashtextextended(?,0))', ["return:$ref"]);
                if (DB::table('refund_transfers')->where('transfer_ref', $ref)->exists() || DB::table('payment_reconciliations')->where('refund_transfer_ref', $ref)->exists()) {
                    throw Settlement::conflict();
                }
                $columns += ['refund_transfer_ref' => $ref, 'proof_file_id' => $proof->id];
                $status = 'RETURNED';
                $event = 'ReconciliationReturned';
            }
        }
        DB::table('payment_reconciliations')->where('id', $id)->update($columns + ['status' => $status]);
        self::record($id, $r->status, $status, $actor, $event);
    }

    private static function record(int $id, ?string $from, string $to, int $actor, string $event): void
    {
        app(Journal::class)->transition(Subject::Reconciliation, $id, 'status', $from, $to, (string) Str::ulid(), $actor);
        app(Journal::class)->audit($event, Subject::Reconciliation, $id, $actor, $from ? ['status' => $from] : null, ['status' => $to]);
        app(Outbox::class)->record($event, 'payment_reconciliation', $id, ['status' => $to]);
    }
}
