<?php

namespace App\Modules\Invoicing;

final class InitialInvoices
{
    /** @param array<string,mixed> $group
     * @param list<array<string,mixed>> $lines */
    public function issue(int $order, int $owner, array $group, array $lines, string $due, string $source = 'checkout:initial'): void
    {
        $shipping = $group['shipping_amount'];
        $items = [];
        foreach ($lines as $line) {
            // The first units carry the extra rupiah when a line deposit is not divisible by quantity.
            $extra = ($line['initial_line_amount'] ?? 0) % $line['qty'];
            foreach ([[$extra, $line['initial_unit_amount'] + 1], [$line['qty'] - $extra, $line['initial_unit_amount']]] as [$qty,$unit]) {
                if ($qty) {
                    $items[] = ['order_item_id' => $line['id'], 'line_type' => 'PRODUCT',
                        'description' => $line['product_snapshot'], 'qty' => $qty, 'unit_amount' => $unit,
                        'amount' => $qty * $unit, 'covered_qty' => $qty];
                }
            }
        }
        if ($shipping) {
            $items[] = ['line_type' => 'DOMESTIC_SHIPPING', 'description' => 'Domestic shipping', 'qty' => 1, 'unit_amount' => $shipping, 'amount' => $shipping];
        }
        app(InvoiceIssuer::class)->issue($order, $owner, $source, $group['term'] === 'DEPOSIT' ? 'DEPOSIT' : 'FULL_PAYMENT', $due, [
            'subtotal_amount' => $group['due_now_amount'] - $shipping - $group['initial_tax'], 'tax_amount' => $group['initial_tax'],
            'shipping_amount' => $shipping, 'total_amount' => $group['due_now_amount'],
        ], $items);
    }
}
