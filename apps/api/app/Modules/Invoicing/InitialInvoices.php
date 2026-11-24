<?php

namespace App\Modules\Invoicing;

use App\Modules\Foundation\Audit\Journal;
use App\Modules\Foundation\Audit\Subject;
use App\Modules\Foundation\Outbox\Outbox;
use Illuminate\Support\Facades\DB;

final class InitialInvoices
{
    /** @param array<string,mixed> $group
     * @param list<array<string,mixed>> $lines */
    public function issue(int $order, int $owner, array $group, array $lines, string $due): void
    {
        $number = sprintf('INV-%s-%06d', now()->format('Ymd'), DB::scalar("SELECT nextval('invoice_number_seq')"));
        $shipping = $group['shipping_amount'];
        $id = DB::table('invoices')->insertGetId(['order_id' => $order, 'number' => $number, 'source_ref' => 'checkout:initial', 'status' => 'ISSUED',
            'purpose' => $group['term'] === 'DEPOSIT' ? 'DEPOSIT' : 'FULL_PAYMENT', 'due_at' => $due,
            'subtotal_amount' => $group['due_now_amount'] - $shipping - $group['initial_tax'], 'tax_amount' => $group['initial_tax'],
            'shipping_amount' => $shipping, 'total_amount' => $group['due_now_amount']]);
        foreach ($lines as $line) {
            DB::table('invoice_items')->insert(['invoice_id' => $id, 'order_item_id' => $line['id'], 'line_type' => 'PRODUCT',
                'description' => $line['product_snapshot'], 'qty' => $line['qty'], 'unit_amount' => $line['initial_unit_amount'],
                'amount' => $line['qty'] * $line['initial_unit_amount'], 'covered_qty' => $line['qty']]);
        }
        if ($shipping) {
            DB::table('invoice_items')->insert(['invoice_id' => $id, 'line_type' => 'DOMESTIC_SHIPPING', 'description' => 'Domestic shipping',
                'qty' => 1, 'unit_amount' => $shipping, 'amount' => $shipping]);
        }
        $journal = app(Journal::class);
        $journal->transition(Subject::Invoice, $id, 'status', null, 'ISSUED', "invoice-issued:$id", $owner);
        $journal->audit('InvoiceIssued', Subject::Invoice, $id, $owner, null, ['status' => 'ISSUED', 'amount_idr' => $group['due_now_amount']]);
        app(Outbox::class)->record('InvoiceIssued', 'invoice', $id, ['number' => $number]);
    }
}
