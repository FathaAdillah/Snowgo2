<?php

namespace App\Modules\Orders;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

final class OrderDirectory
{
    public static function scope(?int $owner): Builder
    {
        return DB::table('orders as o')->when($owner !== null, fn ($q) => $q->where('o.user_id', $owner));
    }

    public static function summaries(?int $owner): Builder
    {
        return self::scope($owner)->select('o.number', 'o.selling_method as method', 'o.term', 'o.status', 'o.payment_status', 'o.fulfillment_status',
            'o.total_amount', 'o.currency', 'o.created_at', 'o.payment_due_at');
    }

    public static function details(?int $owner, bool $address, bool $totals = false): Builder
    {
        $rows = self::summaries($owner)->selectRaw(<<<'SQL'
            (SELECT json_agg(json_build_object('id',i.public_id,'sku',i.sku_snapshot,'name',i.product_snapshot,'variant',i.variant_snapshot,
                'qty',i.qty,'unit_amount',i.unit_amount,'shipped_qty',i.shipped_qty,'cancelled_qty',i.cancelled_qty) ORDER BY i.id)
                FROM order_items i WHERE i.order_id=o.id) AS items,
            (SELECT json_agg(json_build_object('dimension',h.dimension,'to_status',h.to_status,'occurred_at',h.occurred_at) ORDER BY h.id)
                FROM status_histories h WHERE h.subject_type='order' AND h.subject_id=o.id) AS history,
            (SELECT json_agg(v.number ORDER BY v.id) FROM invoices v WHERE v.order_id=o.id) AS invoice_numbers
            SQL);
        if ($address) {
            $rows->selectRaw("(SELECT json_build_object('recipient',a.recipient,'phone',a.phone,'line1',a.line1,'line2',a.line2,'postal_code',a.postal_code,'regions',a.region_snapshot) FROM order_addresses a WHERE a.order_id=o.id) AS address");
        }
        if ($totals) {
            $rows->addSelect('o.subtotal_amount', 'o.discount_amount', 'o.tax_amount', 'o.shipping_amount');
        }

        return $rows;
    }

    /** @return array<string,mixed> */
    public static function responseData(object $row): array
    {
        $data = (array) $row;
        foreach (['created_at', 'payment_due_at'] as $field) {
            $data[$field] = Carbon::parse($data[$field])->toISOString();
        }
        if (! array_key_exists('items', $data)) {
            return $data;
        }
        foreach (['items', 'history', 'invoice_numbers'] as $field) {
            $data[$field] = json_decode($data[$field] ?? '[]', false, flags: JSON_THROW_ON_ERROR);
        }
        $data['address'] = json_decode($data['address'] ?? 'null', false, flags: JSON_THROW_ON_ERROR);

        return $data + ['shipment_numbers' => [], 'payment_numbers' => [], 'charge_consents' => []];
    }
}
