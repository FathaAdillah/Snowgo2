<?php

namespace App\Modules\Refunds;

use App\Modules\Foundation\Audit\Journal;
use App\Modules\Foundation\Audit\Subject;
use App\Modules\Foundation\Outbox\Outbox;
use App\Support\Http\ApiProblem;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class Refunds
{
    public const HELD = ['APPROVED', 'PROCESSING', 'FAILED', 'COMPLETED'];

    public static function invalid(): ApiProblem
    {
        return new ApiProblem(409, 'INVALID_STATE_TRANSITION', 'Refund cannot make this transition.');
    }

    public static function limit(): ApiProblem
    {
        return new ApiProblem(409, 'REFUND_LIMIT', 'Amount or quantity exceeds the available capture or item.');
    }

    public static function reason(string $code): int
    {
        return DB::table('reason_codes')->where(['domain' => 'refunds', 'code' => $code, 'active' => true])->sharedLock()->value('id')
            ?? throw ValidationException::withMessages(['reason_code' => 'Select an active refund reason.']);
    }

    public static function captures(int $order): Builder
    {
        return DB::table('payment_allocations as a')->join('invoices as i', 'i.id', '=', 'a.invoice_id')
            ->where('i.order_id', $order)->whereNotNull('a.applied_at');
    }

    public static function available(int $order): int
    {
        return (int) self::captures($order)->sum('a.amount') - (int) DB::table('refunds')->where('order_id', $order)->whereIn('status', self::HELD)->sum('approved_amount');
    }

    /** @param array<string,mixed> $data */
    public static function request(int $actor, array $data, ?int $owner, ?int $reasonId = null): string
    {
        $o = DB::table('orders')->where('number', $data['order_number'])->when($owner !== null, fn ($q) => $q->where('user_id', $owner))->lockForUpdate()->first() ?? abort(404);
        $amount = (int) $data['requested_amount'];
        $free = self::available($o->id);
        $items = $data['items'] ?? [];
        if ($amount > $free || ($data['kind'] === 'FULL' && $amount !== $free)
            || ($data['kind'] === 'ITEM' && (! $items || array_sum(array_column($items, 'requested_amount')) !== $amount))
            || ($data['kind'] !== 'ITEM' && $items)) {
            throw self::limit();
        }
        if (($data['cancel_order'] ?? false) && ($o->status !== 'CONFIRMED' || DB::table('order_items')->where('order_id', $o->id)->where('shipped_qty', '>', 0)->exists())) {
            throw self::invalid();
        }
        $number = 'RFD-'.Str::ulid();
        $id = DB::table('refunds')->insertGetId(['number' => $number, 'order_id' => $o->id, 'requested_by' => $actor, 'kind' => $data['kind'],
            'requested_amount' => $amount, 'reason_id' => $reasonId ?? self::reason($data['reason_code']), 'cancel_order' => $data['cancel_order'] ?? false, 'note' => $data['note'] ?? null, 'status' => 'REQUESTED']);
        foreach ($items as $line) {
            $item = DB::table('order_items')->where(['public_id' => $line['order_item_id'], 'order_id' => $o->id])->first() ?? abort(404);
            $claims = DB::table('refund_items as ri')->join('refunds as r', 'r.id', '=', 'ri.refund_id')->where('ri.order_item_id', $item->id)
                ->whereNotIn('r.status', ['REJECTED', 'CANCELLED']);
            $paid = (int) self::captures($o->id)->join('invoice_items as l', 'l.invoice_id', '=', 'i.id')->where('l.order_item_id', $item->id)->sum('l.amount');
            if ($item->qty < $line['qty'] + (clone $claims)->sum('ri.qty')
                || $paid < $line['requested_amount'] + (clone $claims)->sum(DB::raw('coalesce(ri.approved_amount,ri.requested_amount)'))
                || $line['requested_amount'] > $line['qty'] * $item->unit_amount + intdiv($item->tax_amount * $line['qty'] + $item->qty - 1, $item->qty)) {
                throw self::limit();
            }
            DB::table('refund_items')->insert(['refund_id' => $id, 'order_item_id' => $item->id, 'qty' => $line['qty'], 'requested_amount' => $line['requested_amount']]);
        }
        DB::table('order_transition_holds')->insert(['order_id' => $o->id, 'kind' => 'DISPUTE', 'source_ref' => $number]);
        self::record($id, null, 'REQUESTED', $actor, 'RefundRequested');

        return $number;
    }

    public static function locked(string $number): object
    {
        $order = DB::table('refunds')->where('number', $number)->value('order_id') ?? abort(404);
        DB::table('orders')->where('id', $order)->lockForUpdate()->firstOrFail();

        return DB::table('refunds')->where('number', $number)->lockForUpdate()->firstOrFail();
    }

    /** @param array<string,mixed> $d */
    public static function review(object $r, int $actor, array $d): void
    {
        $action = $d['decision'];
        $change = ['decision_note' => $d['note']];
        if ($action === 'START' && $r->status === 'REQUESTED') {
            self::change($r, 'UNDER_REVIEW', $actor, 'RefundReviewStarted', $change);

            return;
        }
        if ($r->status !== 'UNDER_REVIEW' || $actor === $r->requested_by) {
            throw self::invalid();
        }
        if ($action === 'REJECT') {
            self::change($r, 'REJECTED', $actor, 'RefundRejected', $change + ['reviewed_by' => $actor, 'decision_reason_id' => self::reason($d['reason_code'])]);

            return;
        }
        if ($action !== 'APPROVE') {
            throw self::invalid();
        }
        $amount = (int) $d['approved_amount'];
        if ($amount > $r->requested_amount || $amount > self::available($r->order_id)) {
            throw self::limit();
        }
        $lines = DB::table('refund_items as ri')->join('order_items as i', 'i.id', '=', 'ri.order_item_id')->where('refund_id', $r->id)->get(['ri.id', 'ri.requested_amount', 'i.public_id'])->keyBy('public_id');
        $approved = $d['items'] ?? [];
        if (count($approved) !== $lines->count() || ($approved && array_sum(array_column($approved, 'approved_amount')) !== $amount)) {
            throw self::limit();
        }
        foreach ($approved as $line) {
            $item = $lines->get($line['order_item_id']) ?? throw self::limit();
            if ($line['approved_amount'] > $item->requested_amount) {
                throw self::limit();
            }
            DB::table('refund_items')->where('id', $item->id)->update(['approved_amount' => $line['approved_amount']]);
        }
        self::change($r, 'APPROVED', $actor, 'RefundApproved', $change + ['reviewed_by' => $actor, 'approved_amount' => $amount]);
    }

    /** @param array<string,mixed> $changes */
    public static function change(object $r, string $status, int $actor, string $event, array $changes = []): void
    {
        DB::table('refunds')->where('id', $r->id)->update($changes + ['status' => $status]);
        if (in_array($status, ['COMPLETED', 'CANCELLED', 'REJECTED'], true)) {
            DB::table('order_transition_holds')->where(['order_id' => $r->order_id, 'kind' => 'DISPUTE', 'source_ref' => $r->number])->delete();
        }
        self::record($r->id, $r->status, $status, $actor, $event);
    }

    private static function record(int $id, ?string $from, string $to, int $actor, string $event): void
    {
        app(Journal::class)->transition(Subject::Refund, $id, 'status', $from, $to, (string) Str::ulid(), $actor);
        app(Journal::class)->audit($event, Subject::Refund, $id, $actor, $from ? ['status' => $from] : null, ['status' => $to]);
        app(Outbox::class)->record($event, 'refund', $id, ['status' => $to]);
    }
}
