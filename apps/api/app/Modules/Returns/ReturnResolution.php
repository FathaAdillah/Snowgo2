<?php

namespace App\Modules\Returns;

use App\Modules\Foundation\Audit\Journal;
use App\Modules\Foundation\Audit\Subject;
use App\Modules\Foundation\Outbox\Outbox;
use App\Modules\Refunds\Refunds;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class ReturnResolution
{
    /** @param array<string,mixed> $d */
    public static function apply(object $r, int $actor, array $d): void
    {
        $kind = $d['resolution'];
        $abandonedRefund = $r->resolution === 'REFUND' && DB::table('refunds')->where('return_id', $r->id)
            ->whereIn('status', ['REJECTED', 'CANCELLED'])->exists();
        if (($kind === 'REJECTED' ? $r->status !== 'INSPECTION_FAILED' : $r->status !== 'ACCEPTED')
            || ($abandonedRefund ? $kind === 'REFUND' : ($r->resolution && $r->resolution !== $kind))) {
            throw ReturnClaims::invalid();
        }
        $done = match ($kind) {
            'REFUND' => self::refund($r, $actor),
            'REPLACEMENT','SHIP_MISSING_ITEM' => self::replacement($r, $actor),
            'REPAIR' => $r->resolution === 'REPAIR' && ($d['work_completed'] ?? false),
            'REJECTED' => true,
            default => throw ReturnClaims::invalid(),
        };
        if ($done) {
            ReturnClaims::change($r, 'RESOLVED', $actor, 'ReturnResolved', ['resolution' => $kind, 'resolved_at' => now(), 'outcome_note' => $d['note']]);
        } elseif (! $r->resolution || $abandonedRefund) {
            DB::table('returns')->where('id', $r->id)->update(['resolution' => $kind, 'outcome_note' => $d['note']]);
            app(Journal::class)->audit('ReturnResolutionStarted', Subject::ReturnCase, $r->id, $actor);
            app(Outbox::class)->record('ReturnResolutionStarted', 'return', $r->id, ['resolution' => $kind]);
        }
    }

    private static function refund(object $r, int $actor): bool
    {
        $existing = DB::table('refunds')->where('return_id', $r->id)->first();
        if ($existing) {
            return $existing->status === 'COMPLETED';
        }
        $items = [];
        foreach (DB::table('return_items as ri')->join('shipment_items as s', 's.id', '=', 'ri.shipment_item_id')
            ->join('order_items as i', 'i.id', '=', 's.order_item_id')->where('ri.return_id', $r->id)->where('ri.accepted_qty', '>', 0)
            ->select('i.*', 'ri.accepted_qty')->selectRaw('return_first_units(ri.unit_coverage,ri.accepted_qty) as accepted_coverage')->get() as $item) {
            $amount = 0;
            foreach (DB::select('SELECT lower(r) AS start, upper(r) AS stop FROM unnest(?::int8multirange) r', [$item->accepted_coverage]) as $span) {
                $amount += Refunds::paidForRange($item, $span->start, $span->stop);
            }
            if ($amount > 0) {
                $items[$item->public_id] ??= ['order_item_id' => $item->public_id, 'qty' => 0, 'requested_amount' => 0];
                $items[$item->public_id]['qty'] += $item->accepted_qty;
                $items[$item->public_id]['requested_amount'] += $amount;
            }
        }
        $number = Refunds::request($actor, ['kind' => 'ITEM', 'items' => $items, 'requested_amount' => array_sum(array_column($items, 'requested_amount')),
            'order_number' => DB::table('orders')->where('id', $r->order_id)->value('number')], null, $r->reason_id);
        DB::table('refunds')->where('number', $number)->update(['return_id' => $r->id]);

        return false;
    }

    private static function replacement(object $r, int $actor): bool
    {
        $existing = DB::table('shipments')->where('replacement_return_id', $r->id)->whereNotIn('status', ['CANCELLED', 'RETURNED'])->first();
        if ($existing) {
            return $existing->status === 'DELIVERED';
        }
        $source = DB::table('shipments')->where('id', $r->shipment_id)->firstOrFail(['order_id', 'warehouse_id', 'weight_g', 'rate_id', 'service_id', 'carrier_id', 'carrier_label', 'service_code', 'eta_min_days', 'eta_max_days']);
        $number = sprintf('SHP-%s-%06d', now()->format('Ymd'), DB::scalar("SELECT nextval('shipment_number_seq')"));
        $id = DB::table('shipments')->insertGetId((array) $source + ['number' => $number, 'shipping_amount' => 0, 'replacement_return_id' => $r->id]);
        foreach (DB::table('return_items as l')->join('shipment_items as s', 's.id', '=', 'l.shipment_item_id')->where('return_id', $r->id)->where('accepted_qty', '>', 0)
            ->select('s.order_item_id', 'l.accepted_qty')->selectRaw('lower(l.unit_coverage) as coverage_start, return_first_units(l.unit_coverage,l.accepted_qty) as replacement_coverage')->get() as $line) {
            DB::table('shipment_items')->insert(['shipment_id' => $id, 'order_item_id' => $line->order_item_id, 'coverage_start' => $line->coverage_start, 'replacement_coverage' => $line->replacement_coverage, 'qty' => $line->accepted_qty, 'public_id' => (string) Str::ulid()]);
        }
        app(Journal::class)->transition(Subject::Shipment, $id, 'status', null, 'PENDING', 'replacement:'.$r->id, $actor);
        app(Outbox::class)->record('ShipmentCreated', 'shipment', $id, ['number' => $number, 'return_number' => $r->number]);

        return false;
    }
}
