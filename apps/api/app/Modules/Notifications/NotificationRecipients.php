<?php

namespace App\Modules\Notifications;

use App\Modules\Foundation\Outbox\RecordedEvent;
use Illuminate\Support\Facades\DB;
use stdClass;

final class NotificationRecipients
{
    /** @return iterable<stdClass> */
    public function customers(RecordedEvent $event): iterable
    {
        $id = $event->aggregateId;

        return match ($event->aggregate) {
            'user' => DB::table('users')->where('id', $id)->get(['id as user_id', DB::raw("'' as number"), DB::raw("'' as path_ref")]),
            'order' => DB::table('orders')->where('id', $id)->get(['user_id', 'number', 'number as path_ref']),
            'payment' => DB::table('payments')->where('id', $id)->get(['user_id', 'number', 'number as path_ref']),
            'request_order' => DB::table('request_orders')->where('id', $id)->get(['user_id', 'number', 'number as path_ref']),
            'quotation' => DB::table('quotations as q')->join('request_orders as r', 'r.id', '=', 'q.request_order_id')
                ->where('q.id', $id)->get(['r.user_id', 'q.number', 'r.number as path_ref']),
            'invoice', 'shipment', 'refund', 'return' => $this->orderSubject($event->aggregate, $id),
            'preorder_campaign' => DB::table('preorder_allocations as a')
                ->join('preorder_campaign_variants as v', 'v.id', '=', 'a.campaign_variant_id')
                ->join('preorder_campaigns as c', 'c.id', '=', 'v.campaign_id')
                ->join('order_items as i', 'i.id', '=', 'a.order_item_id')->join('orders as o', 'o.id', '=', 'i.order_id')
                ->where('c.id', $id)->whereIn('a.status', ['RESERVED', 'CONFIRMED'])
                ->select('o.user_id', 'c.number')->selectRaw('min(o.number) as path_ref')->groupBy('o.user_id', 'c.number')->cursor(),
            'procurement_batch' => DB::table('procurement_batch_items as b')
                ->join('procurement_batches as p', 'p.id', '=', 'b.batch_id')
                ->join('order_items as i', 'i.id', '=', 'b.order_item_id')->join('orders as o', 'o.id', '=', 'i.order_id')
                ->where('b.batch_id', $id)->select('o.user_id', 'p.number')
                ->selectRaw('min(o.number) as path_ref')->groupBy('o.user_id', 'p.number')->cursor(),
            default => [],
        };
    }

    public function link(string $aggregate, string $reference): string
    {
        $path = match ($aggregate) {
            'user' => '/akun',
            'payment' => '/pembayaran/',
            'request_order', 'quotation' => '/request-order/',
            'invoice' => '/invoice/',
            'shipment' => '/pengiriman/',
            'refund' => '/purnajual/refund/',
            'return' => '/purnajual/retur/',
            default => '/pesanan/',
        };

        return $path.rawurlencode($reference);
    }

    /** @return iterable<stdClass> */
    private function orderSubject(string $aggregate, int $id): iterable
    {
        $table = match ($aggregate) {
            'invoice' => 'invoices',
            'shipment' => 'shipments',
            'refund' => 'refunds',
            default => 'returns',
        };

        return DB::table($table.' as s')->join('orders as o', 'o.id', '=', 's.order_id')
            ->where('s.id', $id)->get(['o.user_id', 's.number', 's.number as path_ref']);
    }
}
