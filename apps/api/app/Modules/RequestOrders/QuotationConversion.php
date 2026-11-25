<?php

namespace App\Modules\RequestOrders;

use App\Modules\Customers\Addresses\AddressSnapshot;
use App\Modules\Invoicing\InitialInvoices;
use App\Modules\Orders\CheckoutOrders;
use Illuminate\Support\Facades\DB;

final class QuotationConversion
{
    /** @return array{order_number:string,invoice_number:string} */
    public static function create(object $case, object $quote, string $addressId): array
    {
        $address = app(AddressSnapshot::class)->capture($case->user_id, $addressId);
        $amount = $quote->deposit_amount ?? $quote->total_amount;
        $lines = [];
        $previous = 0;
        $items = DB::table('quotation_items')->where('quotation_id', $quote->id)->orderBy('id')
            ->select('*')->selectRaw('div(sum(unit_amount::numeric*qty) OVER (ORDER BY id) * ?::numeric, ?::numeric)::bigint AS cumulative_due', [$amount, $quote->total_amount])->get();
        foreach ($items as $i) {
            $lineDue = $i->cumulative_due - $previous;
            $lines[] = ['quotation_item_id' => $i->id, 'product_snapshot' => $i->label, 'quotation_options' => $i->options, 'qty' => $i->qty,
                'term' => $quote->term, 'weight_g' => $i->weight_g, 'unit_amount' => $i->unit_amount, 'initial_unit_amount' => intdiv($lineDue, $i->qty),
                'initial_line_amount' => $lineDue, 'tax_amount' => 0, 'initial_tax' => 0];
            $previous = $i->cumulative_due;
        }
        $group = ['method' => 'REQUEST_ORDER', 'term' => $quote->term, 'subtotal_amount' => $quote->subtotal_amount, 'total_amount' => $quote->total_amount,
            'shipping_amount' => 0, 'tax_amount' => 0, 'discount_amount' => 0, 'due_now_amount' => $amount, 'initial_tax' => 0, 'campaign_terms' => null, 'lines' => $lines];
        $due = now()->addHours(24)->toIso8601String();
        $order = app(CheckoutOrders::class)->create(null, $case->user_id, $group, $address, $due, null, $case->id);
        app(InitialInvoices::class)->issue($order['id'], $case->user_id, $group, $order['lines'], $due, 'quotation:'.$quote->number);

        return self::result($case->id);
    }

    /** @return array{order_number:string,invoice_number:string} */
    public static function result(int $case): array
    {
        $row = DB::table('orders as o')->join('invoices as i', 'i.order_id', '=', 'o.id')->where('o.request_order_id', $case)
            ->where('i.source_ref', 'like', 'quotation:%')->firstOrFail(['o.number as order_number', 'i.number as invoice_number']);

        return ['order_number' => $row->order_number, 'invoice_number' => $row->invoice_number];
    }
}
