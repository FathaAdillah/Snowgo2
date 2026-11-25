<?php

namespace App\Modules\Returns;

use App\Modules\Foundation\Audit\Journal;
use App\Modules\Foundation\Audit\Subject;
use App\Modules\Foundation\Outbox\Outbox;
use App\Support\Http\ApiProblem;
use Illuminate\Support\Facades\DB;

final class ReturnInspection
{
    /** @param array<string,mixed> $d */
    public static function apply(object $r, int $actor, array $d): void
    {
        if (! in_array($r->status, ['RECEIVED', 'INSPECTING'], true)) {
            throw ReturnClaims::invalid();
        }
        if ($r->status === 'RECEIVED') {
            ReturnClaims::change($r, 'INSPECTING', $actor, 'ReturnInspectionStarted');
            $r->status = 'INSPECTING';
        }
        $s = DB::table('shipments')->where('id', $r->shipment_id)->firstOrFail();
        $lines = DB::table('return_items as ri')->join('shipment_items as si', 'si.id', '=', 'ri.shipment_item_id')->join('order_items as i', 'i.id', '=', 'si.order_item_id')
            ->where('ri.return_id', $r->id)->select('ri.id', 'ri.qty', 'si.public_id', 'i.variant_id')->get()->keyBy('public_id');
        $accepted = $d['result'] === 'ACCEPT';
        if (count($d['items']) !== $lines->count() || ($accepted !== (array_sum(array_column($d['items'], 'accepted_qty')) > 0))) {
            throw ReturnClaims::invalid();
        }
        foreach ($d['items'] as $row) {
            $item = $lines->get($row['shipment_item_id']) ?? abort(404);
            $qty = $row['accepted_qty'];
            if ($qty > $item->qty || (! $r->received_at && $row['disposition'] !== 'NO_RECEIPT')) {
                throw ReturnClaims::invalid();
            }
            if ($row['disposition'] === 'RESTOCK') {
                if (! $qty || DB::table('orders')->where('id', $r->order_id)->value('selling_method') !== 'READY_TO_SHIP') {
                    throw ReturnClaims::invalid();
                }
                self::stock($s->warehouse_id, $item->variant_id, $qty, $actor, $r->reason_id, 'return:'.$item->id);
            }
            DB::table('return_items')->where('id', $item->id)->update(['accepted_qty' => $qty, 'disposition' => $row['disposition']]);
        }
        ReturnClaims::change($r, $accepted ? 'ACCEPTED' : 'INSPECTION_FAILED', $actor, 'ReturnInspected', ['inspection_note' => $d['note']]);
    }

    public static function stock(int $warehouse, int $variant, int $delta, int $actor, int $reason, string $source): void
    {
        $level = DB::table('inventory_levels')->where(['warehouse_id' => $warehouse, 'variant_id' => $variant])->lockForUpdate()->first();
        if (! $level || ($delta < 0 && $level->available < -$delta)) {
            throw new ApiProblem(409, 'QUANTITY_CONFLICT', 'Insufficient ready stock for this return movement.');
        }
        $type = $delta > 0 ? 'RETURN' : 'REPLACEMENT';
        $id = DB::table('inventory_movements')->insertGetId(['level_id' => $level->id, 'actor_id' => $actor, 'reason_id' => $reason, 'reason_code' => DB::table('reason_codes')->where('id', $reason)->value('code'), 'type' => $type, 'source_key' => $source, 'on_hand_delta' => $delta]);
        app(Journal::class)->audit('ReturnStockMoved', Subject::Inventory, $level->id, $actor, null, ['quantity' => $delta]);
        app(Outbox::class)->record('ReturnStockMoved', 'inventory', $level->id, ['movement_id' => $id, 'quantity' => $delta]);
    }
}
