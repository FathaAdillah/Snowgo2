<?php

namespace App\Modules\Shipping;

use App\Modules\Foundation\Audit\Journal;
use App\Modules\Foundation\Audit\Subject;
use App\Modules\Foundation\Outbox\Outbox;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

final class Tracking
{
    public const TRANSITIONS = [
        'PICKED_UP' => ['IN_TRANSIT', 'OUT_FOR_DELIVERY', 'DELIVERED', 'FAILED', 'RETURNED'],
        'IN_TRANSIT' => ['OUT_FOR_DELIVERY', 'DELIVERED', 'FAILED', 'RETURNED'],
        'OUT_FOR_DELIVERY' => ['DELIVERED', 'FAILED', 'RETURNED'],
        'FAILED' => ['IN_TRANSIT', 'OUT_FOR_DELIVERY', 'DELIVERED', 'RETURNED'],
    ];

    public function append(string $number, string $key, string $status, Carbon $at, ?int $actor, ?string $note = null, ?string $providerStatus = null): void
    {
        DB::transaction(function () use ($number, $key, $status, $at, $actor, $note, $providerStatus) {
            $orderId = DB::table('shipments')->where('number', $number)->value('order_id') ?? abort(404);
            $order = DB::table('orders')->where('id', $orderId)->lockForUpdate()->firstOrFail();
            $shipment = DB::table('shipments')->where('number', $number)->lockForUpdate()->firstOrFail();
            $events = DB::table('tracking_events')->where('shipment_id', $shipment->id);
            if ((clone $events)->where('event_key', $key)->exists()) {
                return;
            }
            $last = (clone $events)->where('applied', true)->max('occurred_at');
            $valid = $last !== null && $at->gte(Carbon::parse($last)) && $at->lte(now())
                && in_array($status, self::TRANSITIONS[$shipment->status] ?? [], true);
            if (! $valid && $providerStatus === null) {
                throw Packages::invalid();
            }
            $id = DB::table('tracking_events')->insertGetId([
                'shipment_id' => $shipment->id, 'event_key' => $key, 'occurred_at' => $at->toISOString(),
                'normalized_status' => $status, 'source' => $providerStatus === null ? 'MANUAL' : 'PROVIDER',
                'raw_status' => $providerStatus, 'applied' => $valid, 'note' => $note,
            ]);
            if (! $valid) {
                return;
            }
            DB::table('shipments')->where('id', $shipment->id)->update(['status' => $status] + ($status === 'DELIVERED' ? ['delivered_at' => $at->toISOString()] : []));
            $journal = app(Journal::class);
            $journal->transition(Subject::Shipment, $shipment->id, 'status', $shipment->status, $status, "tracking:$id", $actor);
            $journal->audit('ShipmentStatusUpdated', Subject::Shipment, $shipment->id, $actor, ['status' => $shipment->status], ['status' => $status]);
            app(Outbox::class)->record('ShipmentStatusUpdated', 'shipment', $shipment->id, ['number' => $number, 'status' => $status]);
            if ($status === 'DELIVERED') {
                DB::update('UPDATE order_items i SET delivered_qty=i.delivered_qty+l.qty FROM shipment_items l WHERE i.id=l.order_item_id AND l.shipment_id=?', [$shipment->id]);
                if (! DB::table('order_items')->where('order_id', $orderId)->whereRaw('delivered_qty != qty-cancelled_qty')->exists()) {
                    DB::table('orders')->where('id', $orderId)->update(['fulfillment_status' => 'DELIVERED']);
                    $journal->transition(Subject::Order, $orderId, 'fulfillment_status', $order->fulfillment_status, 'DELIVERED', "tracking:$id", $actor);
                    app(Outbox::class)->record('OrderDelivered', 'order', $orderId, ['number' => $order->number]);
                }
            }
        });
    }
}
