<?php

namespace App\Modules\Invoicing;

use App\Modules\Foundation\Audit\Journal;
use App\Modules\Foundation\Audit\Subject;
use App\Modules\Foundation\Outbox\Outbox;
use Illuminate\Support\Facades\DB;
use LogicException;

final class InvoiceIssuer
{
    /**
     * The source module supplies an approved snapshot, never raw amounts from an HTTP request.
     *
     * @param  array{subtotal_amount:int,tax_amount:int,shipping_amount:int,total_amount:int,discount_amount?:int,currency?:string}  $amounts
     * @param  list<array<string,mixed>>  $lines
     */
    public function issue(int $order, int $actor, string $source, string $purpose, string $due, array $amounts, array $lines, ?int $replaces = null): string
    {
        if (! DB::transactionLevel() || ! $lines) {
            throw new LogicException('Issue a populated invoice inside its source transaction.');
        }
        DB::table('orders')->where('id', $order)->lockForUpdate()->firstOrFail();
        $existing = DB::table('invoices')->where(['order_id' => $order, 'source_ref' => $source])->value('number');
        if ($existing !== null) {
            return $existing;
        }
        $number = sprintf('INV-%s-%06d', now()->format('Ymd'), DB::scalar("SELECT nextval('invoice_number_seq')"));
        $id = DB::table('invoices')->insertGetId(['order_id' => $order, 'number' => $number, 'source_ref' => $source, 'purpose' => $purpose,
            'status' => 'DRAFT', 'due_at' => $due, 'replaces_invoice_id' => $replaces] + $amounts);
        DB::table('invoice_items')->insert(array_map(fn ($line) => ['invoice_id' => $id] + $line + ['order_item_id' => null, 'covered_qty' => null], $lines));
        DB::table('invoices')->where('id', $id)->update(['status' => 'ISSUED']);
        $journal = app(Journal::class);
        $journal->transition(Subject::Invoice, $id, 'status', null, 'ISSUED', "invoice-issued:$id", $actor);
        $journal->audit('InvoiceIssued', Subject::Invoice, $id, $actor, null, ['status' => 'ISSUED', 'amount_idr' => $amounts['total_amount']]);
        app(Outbox::class)->record('InvoiceIssued', 'invoice', $id, ['number' => $number]);

        return $number;
    }
}
