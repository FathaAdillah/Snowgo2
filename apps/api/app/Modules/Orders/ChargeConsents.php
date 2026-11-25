<?php

namespace App\Modules\Orders;

use App\Modules\Foundation\Audit\Journal;
use App\Modules\Foundation\Audit\Subject;
use App\Modules\Foundation\Outbox\Outbox;
use App\Modules\Invoicing\InvoiceIssuer;
use App\Support\Http\ApiProblem;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class ChargeConsents
{
    /**
     * Create a new class instance.
     */
    public static function invalid(): ApiProblem
    {
        return new ApiProblem(409, 'INVALID_STATE_TRANSITION', 'The charge, quantity or quote is not eligible.');
    }

    /** @param array<string,mixed> $input */
    public static function propose(object $order, int $actor, array $input): string
    {
        if ($order->status !== 'CONFIRMED' || $order->selling_method === 'READY_TO_SHIP') {
            throw self::invalid();
        }
        $quote = null;
        $until = Carbon::parse($input['expires_at']);
        if ($input['type'] === 'DOMESTIC_SHIPPING') {
            $quote = DB::table('shipping_quotes')->where(['order_id' => $order->id, 'user_id' => $order->user_id, 'public_id' => $input['quote_id']])->lockForUpdate()->first() ?? abort(404);
            if (($quote->shipment_id && ! DB::table('shipments')->where(['id' => $quote->shipment_id, 'status' => 'PENDING'])->exists()) || $quote->used_at || ! $quote->service_code_snapshot) {
                throw self::invalid();
            }
            $until = $until->min(Carbon::parse($quote->expires_at));
            $amount = $quote->amount;
            $scope = 'quote:'.$quote->public_id;
            $line = ['type' => $input['type'], 'description' => $quote->carrier_label_snapshot.' · '.$quote->service_code_snapshot, 'qty' => 1, 'unit_amount' => $amount, 'amount' => $amount];
        } else {
            $item = DB::table('order_items')->where(['order_id' => $order->id, 'public_id' => $input['order_item_id']])->lockForUpdate()->first() ?? abort(404);
            $scope = $input['type'].':'.$item->public_id;
            $used = DB::table('order_charge_consents')->where(['order_id' => $order->id, 'scope' => $scope, 'status' => 'ACCEPTED'])->selectRaw("coalesce(max((lines->0->>'start')::int+(lines->0->>'qty')::int),0) as n")->value('n');
            $start = max($item->shipped_qty, $used);
            if ($start + $input['qty'] > min($item->received_qty, $item->qty - $item->cancelled_qty)) {
                throw self::invalid();
            }
            $amount = (int) $input['qty'] * (int) $input['unit_amount'];
            $line = ['type' => $input['type'], 'description' => $input['description'], 'order_item_id' => $item->public_id, 'start' => $start, 'qty' => (int) $input['qty'], 'unit_amount' => (int) $input['unit_amount'], 'amount' => $amount];
        }
        if ($until->lte(now()) || $amount <= 0 || $amount > 1000000000000) {
            throw self::invalid();
        }
        $previous = DB::table('order_charge_consents')->where(['order_id' => $order->id, 'scope' => $scope, 'status' => 'PROPOSED'])->lockForUpdate()->first();
        if ($previous) {
            self::change($previous, 'EXPIRED', 'ChargeSuperseded', $actor);
        }
        $revision = 1 + (int) DB::table('order_charge_consents')->where('order_id', $order->id)->max('revision');
        $ref = (string) Str::ulid();
        $id = DB::table('order_charge_consents')->insertGetId(['public_id' => $ref, 'order_id' => $order->id, 'scope' => $scope, 'revision' => $revision, 'lines' => json_encode([$line], JSON_THROW_ON_ERROR), 'amount' => $amount, 'status' => 'PROPOSED', 'expires_at' => $until, 'created_by' => $actor, 'quote_id' => $quote?->id]);
        self::record($id, null, 'PROPOSED', 'ChargeProposed', $actor);

        return $ref;
    }

    public static function decide(object $order, string $ref, int $actor, string $decision): void
    {
        $c = DB::table('order_charge_consents')->where(['public_id' => $ref, 'order_id' => $order->id])->lockForUpdate()->first() ?? abort(404);
        if ($order->user_id !== $actor || $order->status !== 'CONFIRMED' || $c->status !== 'PROPOSED' || now()->gte($c->expires_at)) {
            throw self::invalid();
        }
        if ($decision === 'REJECT') {
            self::change($c, 'REJECTED', 'ChargeDeclined', $actor);

            return;
        }
        $line = json_decode($c->lines, true, flags: JSON_THROW_ON_ERROR)[0];
        $item = null;
        if ($c->quote_id) {
            if (DB::table('shipping_quotes as q')->join('shipments as s', 's.id', '=', 'q.shipment_id')->where('q.id', $c->quote_id)->where('s.status', '!=', 'PENDING')->exists()) {
                throw self::invalid();
            }
            if (! DB::table('shipping_quotes')->where(['id' => $c->quote_id, 'order_id' => $order->id])->whereNull('used_at')->whereRaw('expires_at>clock_timestamp()')->update(['used_at' => now()])) {
                throw self::invalid();
            }
        } else {
            $item = DB::table('order_items')->where(['order_id' => $order->id, 'public_id' => $line['order_item_id']])->lockForUpdate()->firstOrFail();
            if ($line['start'] < $item->shipped_qty || min($item->received_qty, $item->qty - $item->cancelled_qty) < $line['start'] + $line['qty']) {
                throw self::invalid();
            }
        }
        $shipping = $c->quote_id ? $c->amount : 0;
        $number = app(InvoiceIssuer::class)->issue($order->id, $actor, 'consent:'.$ref, 'ADDITIONAL_CHARGE', now()->addDays(2)->toISOString(),
            ['subtotal_amount' => $c->amount - $shipping, 'tax_amount' => 0, 'shipping_amount' => $shipping, 'total_amount' => $c->amount],
            [['order_item_id' => $item?->id, 'line_type' => $line['type'], 'description' => $line['description'], 'qty' => $line['qty'], 'unit_amount' => $line['unit_amount'], 'amount' => $line['amount']]]);
        self::change($c, 'ACCEPTED', 'ChargeAccepted', $actor, ['accepted_at' => now(), 'invoice_id' => DB::table('invoices')->where('number', $number)->value('id')]);

    }

    public static function expire(): void
    {
        foreach (DB::table('order_charge_consents')->where('status', 'PROPOSED')->whereRaw('expires_at<=clock_timestamp()')->limit(200)->get(['id', 'order_id']) as $candidate) {
            DB::transaction(function () use ($candidate) {
                DB::table('orders')->where('id', $candidate->order_id)->lockForUpdate()->firstOrFail();
                $c = DB::table('order_charge_consents')->where(['id' => $candidate->id, 'status' => 'PROPOSED'])->lockForUpdate()->first();
                if ($c) {
                    self::change($c, 'EXPIRED', 'ChargeExpired', null);
                }
            });
        }
    }

    /** @param array<string,mixed> $columns */
    private static function change(object $c, string $to, string $event, ?int $actor, array $columns = []): void
    {
        DB::table('order_charge_consents')->where('id', $c->id)->update(['status' => $to] + $columns);
        self::record($c->id, $c->status, $to, $event, $actor);
    }

    private static function record(int $id, ?string $from, string $to, string $event, ?int $actor): void
    {
        app(Journal::class)->transition(Subject::ChargeConsent, $id, 'status', $from, $to, $to, $actor);
        app(Journal::class)->audit($event, Subject::ChargeConsent, $id, $actor, $from ? ['status' => $from] : null, ['status' => $to]);
        app(Outbox::class)->record($event, 'charge_consent', $id, ['status' => $to]);
    }

    /** @return array<string,mixed> */
    public static function present(string $ref): array
    {
        $c = DB::table('order_charge_consents as c')->leftJoin('invoices as i', 'i.id', '=', 'c.invoice_id')->where('c.public_id', $ref)->firstOrFail(['c.public_id as id', 'c.status', 'c.revision', 'c.amount', 'c.expires_at', 'c.lines', 'i.number as invoice_number']);

        return array_filter(['id' => $c->id, 'status' => $c->status, 'revision' => $c->revision, 'amount' => $c->amount, 'expires_at' => Carbon::parse($c->expires_at)->toISOString(), 'lines' => json_decode($c->lines, true, flags: JSON_THROW_ON_ERROR), 'invoice_number' => $c->invoice_number], fn ($v) => $v !== null);
    }
}
