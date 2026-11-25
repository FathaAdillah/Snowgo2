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
            (SELECT json_agg(json_build_object('id',i.public_id,'sku',i.sku_snapshot,'name',i.product_snapshot,'variant',coalesce(i.quotation_options,i.variant_snapshot),
                'qty',i.qty,'unit_amount',i.unit_amount,'received_qty',i.received_qty,'shipped_qty',i.shipped_qty,'cancelled_qty',i.cancelled_qty) ORDER BY i.id)
                FROM order_items i WHERE i.order_id=o.id) AS items,
            (SELECT json_agg(json_build_object('dimension',h.dimension,'to_status',h.to_status,'occurred_at',h.occurred_at) ORDER BY h.id)
                FROM status_histories h WHERE h.subject_type='order' AND h.subject_id=o.id) AS history,
            (SELECT json_agg(jsonb_strip_nulls(jsonb_build_object('id',c.public_id,'status',c.status,'revision',c.revision,'expires_at',c.expires_at,'lines',c.lines,'amount',c.amount,'invoice_number',v.number)) ORDER BY c.revision) FROM order_charge_consents c LEFT JOIN invoices v ON v.id=c.invoice_id WHERE c.order_id=o.id) AS charge_consents,
            (SELECT json_agg(x.number ORDER BY x.number) FROM (SELECT DISTINCT p.number FROM payments p JOIN payment_allocations a ON a.payment_id=p.id JOIN invoices i ON i.id=a.invoice_id WHERE i.order_id=o.id) x) AS payment_numbers,
            (SELECT json_agg(v.number ORDER BY v.id) FROM invoices v WHERE v.order_id=o.id) AS invoice_numbers,
            (SELECT json_build_object('quote_id',q.public_id,'carrier_label',q.carrier_label_snapshot,'service_code',q.service_code_snapshot,
                'amount',q.amount,'estimated_days_from',q.estimated_days_from,'estimated_days_to',q.estimated_days_to)
                FROM checkouts c JOIN shipping_quotes q ON q.cart_id=c.cart_id AND q.used_at IS NOT NULL
                WHERE o.selling_method='READY_TO_SHIP' AND c.id=o.checkout_id) AS checkout_shipping
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
        foreach (['items', 'history', 'invoice_numbers', 'payment_numbers', 'charge_consents'] as $field) {
            $data[$field] = json_decode($data[$field] ?? '[]', false, flags: JSON_THROW_ON_ERROR);
        }
        $data['address'] = json_decode($data['address'] ?? 'null', false, flags: JSON_THROW_ON_ERROR);
        if (isset($data['checkout_shipping'])) {
            $data['checkout_shipping'] = json_decode($data['checkout_shipping'], false, flags: JSON_THROW_ON_ERROR);
        } else {
            unset($data['checkout_shipping']);
        }

        return $data + ['shipment_numbers' => [], 'payment_numbers' => [], 'charge_consents' => []];
    }
}
