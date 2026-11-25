<?php

namespace App\Modules\Invoicing;

use App\Modules\Foundation\Audit\Journal;
use App\Modules\Foundation\Audit\Subject;
use App\Modules\Foundation\Outbox\Outbox;
use App\Modules\Payments\PaymentFlow;
use App\Support\Http\ApiProblem;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class BalanceInvoices
{
    /**
     * Create a new class instance.
     */
    public static function invalid(): ApiProblem
    {
        return new ApiProblem(409, 'QUANTITY_CONFLICT', 'No eligible unbilled quantity or unpaid balance.');
    }

    /** Every range is half open. Voiding an earlier tranche leaves a gap, not a credit on later units. */
    public static function issue(object $order, int $actor, string $due, ?object $original = null): string
    {
        return self::issueEligible($order, $actor, $due, $original) ?? throw self::invalid();
    }

    private static function issueEligible(object $order, int $actor, string $due, ?object $original): ?string
    {
        if ($order->status !== 'CONFIRMED' || $order->term !== 'DEPOSIT' || $order->selling_method === 'READY_TO_SHIP' || Carbon::parse($due)->lte(now())
            || ! DB::table('invoices')->where(['order_id' => $order->id, 'purpose' => 'DEPOSIT', 'status' => 'PAID'])->exists()) {
            throw self::invalid();
        }
        $lines = [];
        $subtotal = $tax = 0;
        foreach (DB::table('order_items')->where('order_id', $order->id)->orderBy('id')->lockForUpdate()->get() as $item) {
            $end = min($item->received_qty, $item->qty - $item->cancelled_qty);
            $covered = DB::table('invoice_items as l')->join('invoices as v', 'v.id', '=', 'l.invoice_id')->where(['l.order_item_id' => $item->id, 'v.purpose' => 'BALANCE'])
                ->whereIn('v.status', ['ISSUED', 'PAID'])->whereNotNull('l.coverage_start')->orderBy('l.coverage_start')->get(['l.coverage_start', 'l.covered_qty']);
            $ranges = $original ? DB::table('invoice_items')->where(['invoice_id' => $original->id, 'order_item_id' => $item->id])->whereNotNull('coverage_start')->orderBy('coverage_start')->get()
                : [(object) ['coverage_start' => 0, 'covered_qty' => $end]];
            $deposit = $item->initial_line_amount ?? $item->initial_unit_amount * $item->qty;
            foreach ($ranges as $range) {
                $cursor = $range->coverage_start;
                $last = min($cursor + $range->covered_qty, $end);
                foreach ([...$covered, (object) ['coverage_start' => $last, 'covered_qty' => 0]] as $block) {
                    $stop = min($last, $block->coverage_start);
                    // Split where the extra rupiah of deposit, discount or tax stops applying.
                    $cuts = [$cursor, $stop];
                    foreach ([$deposit, $item->discount_amount] as $sum) {
                        $cut = $sum % $item->qty;
                        if ($cut > $cursor && $cut < $stop) {
                            $cuts[] = $cut;
                        }
                    }
                    if ($stop > $cursor) {
                        sort($cuts);
                        foreach (array_slice(array_unique($cuts), 1) as $to) {
                            $qty = $to - $cursor;
                            $unit = $item->unit_amount - intdiv($deposit, $item->qty) - (int) ($cursor < $deposit % $item->qty) - intdiv($item->discount_amount, $item->qty) - (int) ($cursor < $item->discount_amount % $item->qty);
                            $amount = $unit * $qty;
                            $subtotal += $amount;
                            $tax += self::prefix($item->tax_amount - $item->initial_tax, $item->qty, $to) - self::prefix($item->tax_amount - $item->initial_tax, $item->qty, $cursor);
                            $lines[] = ['order_item_id' => $item->id, 'description' => $item->product_snapshot, 'line_type' => 'PRODUCT', 'qty' => $qty, 'covered_qty' => $qty, 'coverage_start' => $cursor, 'unit_amount' => $unit, 'amount' => $amount];
                            $cursor = $to;
                        }
                    }
                    $cursor = max($cursor, $block->coverage_start + $block->covered_qty);
                    if ($cursor >= $last) {
                        break;
                    }
                }
            }
        }
        if (! $lines || $subtotal + $tax <= 0) {
            return null;
        }

        return app(InvoiceIssuer::class)->issue($order->id, $actor, 'balance:'.Str::ulid(), 'BALANCE', $due, ['subtotal_amount' => $subtotal, 'tax_amount' => $tax, 'shipping_amount' => 0, 'total_amount' => $subtotal + $tax], $lines, $original?->id);
    }

    public static function prefix(int $sum, int $qty, int $n): int
    {
        return $n * intdiv($sum, $qty) + min($n, $sum % $qty);
    }

    public static function balanceForRange(object $item, int $start, int $end): int
    {
        $allocated = fn (int $sum): int => self::prefix($sum, $item->qty, $end) - self::prefix($sum, $item->qty, $start);

        return ($end - $start) * $item->unit_amount
            - $allocated($item->initial_line_amount ?? $item->initial_unit_amount * $item->qty)
            - $allocated($item->discount_amount)
            + $allocated($item->tax_amount - $item->initial_tax);
    }

    /** @param array<string,mixed> $input */
    public static function correct(object $order, int $actor, array $input): string
    {
        $v = DB::table('invoices')->where(['order_id' => $order->id, 'number' => $input['invoice_number']])->lockForUpdate()->first() ?? abort(404);
        $item = DB::table('order_items')->where(['order_id' => $order->id, 'public_id' => $input['order_item_id']])->lockForUpdate()->first() ?? abort(404);
        $reason = DB::table('reason_codes')->where(['domain' => 'invoicing', 'active' => true, 'code' => $input['reason_code']])->sharedLock()->value('id') ?? throw self::invalid();
        if ($v->status !== 'ISSUED' || $v->purpose !== 'BALANCE' || ! $item->cancelled_qty || ! DB::table('invoice_items')->where(['invoice_id' => $v->id, 'order_item_id' => $item->id])
            ->whereRaw('coverage_start+covered_qty > ?', [min($item->received_qty, $item->qty - $item->cancelled_qty)])->exists()
            || DB::table('payment_allocations as a')->join('payments as p', 'p.id', '=', 'a.payment_id')->where('a.invoice_id', $v->id)->where(fn ($q) => $q->whereIn('p.status', PaymentFlow::LIVE)->orWhereNotNull('a.applied_at'))->exists()) {
            throw self::invalid();
        }
        DB::table('invoices')->where('id', $v->id)->update(['status' => 'VOID']);
        app(Journal::class)->transition(Subject::Invoice, $v->id, 'status', 'ISSUED', 'VOID', 'shortage:'.$v->id, $actor, $reason);
        app(Journal::class)->audit('InvoiceVoided', Subject::Invoice, $v->id, $actor, ['status' => 'ISSUED'], ['status' => 'VOID']);
        app(Outbox::class)->record('InvoiceVoided', 'invoice', $v->id, ['number' => $v->number]);

        return self::issueEligible($order, $actor, max(Carbon::parse($v->due_at), now()->addDay())->toISOString(), $v) ?? $v->number;
    }
}
