<?php

namespace App\Modules\Shipping;

use App\Modules\Foundation\Audit\Journal;
use App\Modules\Foundation\Audit\Subject;
use App\Modules\Foundation\Outbox\Outbox;
use App\Modules\Inventory\StockReservations;
use App\Support\Http\ApiProblem;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class Packages
{
    public static function invalid(): ApiProblem
    {
        return new ApiProblem(409, 'INVALID_STATE_TRANSITION', 'Package, packing stage or payment is not eligible.');
    }

    private static function quantity(): ApiProblem
    {
        return new ApiProblem(409, 'QUANTITY_CONFLICT', 'Unavailable package quantity or weight.');
    }

    /** @return array<string,mixed> */
    public static function detail(string $number, ?int $owner = null): array
    {
        $s = DB::table('shipments as s')->join('orders as o', 'o.id', '=', 's.order_id')->where('s.number', $number)->when($owner !== null, fn ($q) => $q->where('o.user_id', $owner))->first(['s.id', 's.number', 'o.number as order_number', 'o.selling_method as method', 's.status', 's.packing_status', 's.carrier_label', 's.service_code', 's.shipping_amount', 's.tracking_number', 's.shipped_at']) ?? abort(404);
        $id = $s->id;
        unset($s->id);
        $s->shipped_at = $s->shipped_at ? Carbon::parse($s->shipped_at)->toISOString() : null;
        $s->items = DB::table('shipment_items as l')->join('order_items as i', 'i.id', '=', 'l.order_item_id')->where('l.shipment_id', $id)->get(['l.public_id as id', 'i.public_id as order_item_id', 'l.qty', 'i.product_snapshot as name']);
        $s->tracking = DB::table('tracking_events')->where('shipment_id', $id)->orderBy('id')->get(['normalized_status as status', 'occurred_at'])->map(fn ($e) => ['status' => $e->status, 'occurred_at' => Carbon::parse($e->occurred_at)->toISOString()]);

        return (array) $s;
    }

    /** @param array<string,mixed> $d */
    public static function plan(array $d, int $actor): string
    {
        $o = DB::table('orders')->where('number', $d['order_number'])->lockForUpdate()->first() ?? abort(404);
        if ($o->status !== 'CONFIRMED') {
            throw self::invalid();
        }
        $warehouse = DB::table('warehouses')->where(['id' => $d['warehouse_id'], 'active' => true])->sharedLock()->first() ?? abort(404);
        $rts = $o->selling_method === 'READY_TO_SHIP';
        $lines = $d['items'];
        $items = DB::table('order_items')->where('order_id', $o->id)->whereIn('public_id', array_column($lines, 'order_item_id'))->orderBy('id')->lockForUpdate()->get()->keyBy('public_id');
        abort_unless($items->count() === count($lines), 404);
        $rows = [];
        $weight = 0;
        foreach ($lines as $line) {
            $i = $items[$line['order_item_id']];
            $qty = (int) $line['qty'];
            $end = min($i->qty - $i->cancelled_qty, $rts ? $i->qty : $i->received_qty);
            $start = 0;
            if ($rts && ! DB::table('stock_reservations as h')->join('inventory_levels as l', 'l.id', '=', 'h.level_id')->where(['h.order_item_id' => $i->id, 'l.warehouse_id' => $warehouse->id, 'h.status' => 'COMMITTED'])->whereRaw('h.qty-h.shipped_qty-h.released_qty>=?', [$qty])->exists()) {
                throw self::quantity();
            }
            foreach (DB::table('shipment_items as l')->join('shipments as s', 's.id', '=', 'l.shipment_id')->where('l.order_item_id', $i->id)->where('s.status', '!=', 'CANCELLED')->orderBy('l.coverage_start')->get(['l.coverage_start', 'l.qty']) as $c) {
                if ($start + $qty <= $c->coverage_start) {
                    break;
                }
                $start = max($start, $c->coverage_start + $c->qty);
            }
            if ($start + $qty > $end) {
                throw self::quantity();
            }
            $rows[] = ['order_item_id' => $i->id, 'public_id' => (string) Str::ulid(), 'coverage_start' => $start, 'qty' => $qty];
            if (! $rts && ! isset($d['measured_weight_g']) && (! $i->weight_g || $i->weight_g < 1)) {
                throw self::quantity();
            }
            $weight += $qty * $i->weight_g;
        }
        $selected = $rts ? DB::table('shipping_quotes as q')->join('checkouts as c', 'c.cart_id', '=', 'q.cart_id')->where('c.id', $o->checkout_id)->whereNotNull('q.used_at')->first(['q.*']) : null;
        $dest = DB::table('order_addresses')->where('order_id', $o->id)->value('district_id');
        $rates = DB::table('shipping_rates as r')->join('courier_services as v', 'v.id', '=', 'r.service_id')->join('couriers as c', 'c.id', '=', 'v.courier_id')->when($rts, fn ($q) => $q->where('r.id', $selected?->rate_id), fn ($q) => $q->where(['v.code' => $d['service_code'], 'r.active' => true, 'v.active' => true, 'c.active' => true, 'r.origin_region_id' => $warehouse->district_id, 'r.destination_region_id' => $dest])->when(isset($d['service_id']), fn ($q) => $q->where('v.id', $d['service_id'])))->sharedLock()->limit(2)->get(['r.*', 'v.code as service_code', 'c.name as carrier_label', 'c.id as carrier_id']);
        if ($rates->count() !== 1 || ($rts && $selected?->service_code_snapshot !== $d['service_code'])) {
            throw new ApiProblem(409, 'NO_SHIPPING_RATE', 'Select an unambiguous eligible service.');
        }
        $r = $rates->first();
        $kg = max(1, intdiv(($d['measured_weight_g'] ?? $weight) + 999, 1000));
        if (! $rts && $r->amount_per_kg > intdiv(1000000000000 - $r->base_amount, $kg)) {
            throw self::quantity();
        }
        $n = sprintf('SHP-%s-%06d', now()->format('Ymd'), DB::scalar("SELECT nextval('shipment_number_seq')"));
        $s = DB::table('shipments')->insertGetId(['number' => $n, 'order_id' => $o->id, 'warehouse_id' => $warehouse->id, 'weight_g' => $d['measured_weight_g'] ?? $weight, 'rate_id' => $r->id, 'service_id' => $r->service_id, 'carrier_id' => $r->carrier_id, 'carrier_label' => $selected->carrier_label_snapshot ?? $r->carrier_label, 'service_code' => $d['service_code'], 'shipping_amount' => $selected->amount ?? ($r->base_amount + $kg * $r->amount_per_kg), 'eta_min_days' => $selected->estimated_days_from ?? $r->eta_min_days, 'eta_max_days' => $selected->estimated_days_to ?? $r->eta_max_days]);
        foreach ($rows as $row) {
            DB::table('shipment_items')->insert(['shipment_id' => $s] + $row);
        }
        self::record($s, 'status', null, 'PENDING', 'ShipmentCreated', $actor);

        return $n;
    }

    /** @return array{object,object} */
    private static function lock(string $number): array
    {
        $o = DB::table('orders')->where('id', DB::table('shipments')->select('order_id')->where('number', $number))->lockForUpdate()->first() ?? abort(404);
        $s = DB::table('shipments')->where('number', $number)->lockForUpdate()->firstOrFail();
        if ($s->status !== 'PENDING' || $o->status !== 'CONFIRMED') {
            throw self::invalid();
        }

        return [$o, $s];
    }

    /** @return array<string,mixed> */
    public static function quote(string $order, string $number): array
    {
        [$o,$s] = self::lock($number);
        abort_unless($o->number === $order, 404);
        if ($o->selling_method === 'READY_TO_SHIP' || $s->shipping_amount === 0 || DB::table('shipping_quotes')->where('shipment_id', $s->id)->whereNotNull('used_at')->exists()) {
            throw self::invalid();
        }
        $q = DB::table('shipping_quotes')->where('shipment_id', $s->id)->whereRaw('expires_at>clock_timestamp()')->first();
        if (! $q) {
            $ref = (string) Str::ulid();
            $id = DB::table('shipping_quotes')->insertGetId(['public_id' => $ref, 'order_id' => $o->id, 'user_id' => $o->user_id, 'shipment_id' => $s->id, 'service_id' => $s->service_id, 'rate_id' => $s->rate_id, 'context_hash' => hash('sha256', $number), 'amount' => $s->shipping_amount, 'estimated_days_from' => $s->eta_min_days, 'estimated_days_to' => $s->eta_max_days, 'carrier_label_snapshot' => $s->carrier_label, 'service_code_snapshot' => $s->service_code, 'expires_at' => now()->addDay()]);
            $q = DB::table('shipping_quotes')->where('id', $id)->firstOrFail();
        }

        return ['id' => $q->public_id, 'carrier_label' => $s->carrier_label, 'service_code' => $s->service_code, 'amount' => $s->shipping_amount, 'expires_at' => Carbon::parse($q->expires_at)->toISOString()];
    }

    /** @param array<string,mixed> $d */
    public static function act(string $number, string $action, array $d, int $actor): string
    {
        [$o,$s] = self::lock($number);
        if (isset($d['order_number']) && $d['order_number'] !== $o->number) {
            abort(404);
        }
        if ($action === 'pack') {
            $next = ['PACK_START' => ['PENDING', 'PACKING'], 'PACK_FINISH' => ['PACKING', 'PACKED'], 'READY_FOR_DISPATCH' => ['PACKED', 'READY_FOR_DISPATCH']][$d['action']];
            if ($s->packing_status !== $next[0]) {
                throw self::invalid();
            }
            DB::table('shipments')->where('id', $s->id)->update(['packing_status' => $next[1]]);
            self::record($s->id, 'packing_status', $next[0], $next[1], 'ShipmentPackingChanged', $actor);

            return $number;
        }
        if ($action === 'cancel') {
            if (DB::table('shipping_quotes')->where('shipment_id', $s->id)->whereNotNull('used_at')->exists()) {
                throw self::invalid();
            }
            $reason = DB::table('reason_codes')->where(['domain' => 'shipping', 'code' => $d['reason_code'], 'active' => true])->sharedLock()->value('id') ?? throw self::invalid();
            DB::table('shipments')->where('id', $s->id)->update(['status' => 'CANCELLED']);
            self::record($s->id, 'status', 'PENDING', 'CANCELLED', 'ShipmentCancelled', $actor, $reason);

            return $number;
        }
        if ($s->packing_status !== 'READY_FOR_DISPATCH' || DB::table('order_transition_holds')->where('order_id', $o->id)->exists() || ! DB::table('invoices')->where(['order_id' => $o->id, 'status' => 'PAID'])->whereIn('purpose', ['FULL_PAYMENT', 'DEPOSIT'])->exists()) {
            throw self::invalid();
        }
        $rts = $o->selling_method === 'READY_TO_SHIP';
        if (! $rts && $s->shipping_amount > 0) {
            $invoice = DB::table('order_charge_consents as c')->join('shipping_quotes as q', 'q.id', '=', 'c.quote_id')->where(['q.shipment_id' => $s->id, 'c.status' => 'ACCEPTED'])->value('c.invoice_id');
            if (! $invoice || ! self::paid($invoice)) {
                throw self::invalid();
            }
        }
        $tracking = mb_strtoupper(trim($d['tracking_number']));
        if (! $tracking || $s->service_code !== $d['service_code']) {
            throw self::invalid();
        }
        DB::select('SELECT pg_advisory_xact_lock(hashtextextended(?,0))', ["tracking:$s->carrier_id:$tracking"]);
        if (DB::table('shipments')->where(['carrier_id' => $s->carrier_id, 'tracking_number' => $tracking])->exists()) {
            throw self::invalid();
        }
        foreach (DB::table('shipment_items')->where('shipment_id', $s->id)->orderBy('order_item_id')->get() as $l) {
            $i = DB::table('order_items')->where('id', $l->order_item_id)->lockForUpdate()->firstOrFail();
            $end = $l->coverage_start + $l->qty;
            if ($end > $i->qty - $i->cancelled_qty || (! $rts && $end > $i->received_qty)) {
                throw self::quantity();
            }
            if (! $rts && $i->term === 'DEPOSIT') {
                $cursor = $l->coverage_start;
                foreach (DB::table('invoice_items as l')->join('invoices as v', 'v.id', '=', 'l.invoice_id')->where(['l.order_item_id' => $i->id, 'v.purpose' => 'BALANCE', 'v.status' => 'PAID'])->whereNotNull('l.coverage_start')->orderBy('l.coverage_start')->get(['l.coverage_start', 'l.covered_qty']) as $b) {
                    if ($b->coverage_start > $cursor) {
                        break;
                    }
                    $cursor = max($cursor, $b->coverage_start + $b->covered_qty);
                }
                if ($cursor < $end) {
                    throw self::invalid();
                }
            }
            if (! $rts) {
                foreach (DB::table('order_charge_consents')->where('order_id', $o->id)->whereNull('quote_id')->whereIn('status', ['ACCEPTED', 'PROPOSED'])->whereRaw("lines->0->>'order_item_id'=? AND (lines->0->>'start')::int < ? AND (lines->0->>'start')::int+(lines->0->>'qty')::int > ?", [$i->public_id, $end, $l->coverage_start])->get() as $fee) {
                    if (! $fee->invoice_id || ! self::paid($fee->invoice_id)) {
                        throw self::invalid();
                    }
                }
            }
            if ($rts) {
                $hold = DB::table('stock_reservations')->where(['order_item_id' => $i->id, 'status' => 'COMMITTED'])->value('id') ?? throw self::quantity();
                app(StockReservations::class)->ship($o->id, $hold, $l->qty, $l->id);
            }
            DB::table('order_items')->where('id', $i->id)->increment('shipped_qty', $l->qty);
        }
        DB::table('shipments')->where('id', $s->id)->update(['status' => 'PICKED_UP', 'tracking_number' => $tracking, 'shipped_at' => now()]);
        DB::table('tracking_events')->insert(['shipment_id' => $s->id, 'event_key' => 'dispatch', 'normalized_status' => 'PICKED_UP']);
        self::record($s->id, 'status', 'PENDING', 'PICKED_UP', 'ShipmentStatusUpdated', $actor);
        $state = DB::table('order_items')->where('order_id', $o->id)->whereRaw('shipped_qty<qty-cancelled_qty')->exists() ? 'PARTIALLY_SHIPPED' : 'SHIPPED';
        if ($o->fulfillment_status !== $state) {
            DB::table('orders')->where('id', $o->id)->update(['fulfillment_status' => $state]);
            app(Journal::class)->transition(Subject::Order, $o->id, 'fulfillment_status', $o->fulfillment_status, $state, 'dispatch:'.$number, $actor);
        }

        return $number;
    }

    private static function paid(int $id): bool
    {
        return (bool) DB::scalar("WITH RECURSIVE chain AS (SELECT id,status FROM invoices WHERE id=? UNION ALL SELECT i.id,i.status FROM invoices i JOIN chain c ON i.replaces_invoice_id=c.id) SELECT EXISTS(SELECT FROM chain WHERE status='PAID')", [$id]);
    }

    private static function record(int $id, string $dim, ?string $from, string $to, string $event, int $actor, ?int $reason = null): void
    {
        app(Journal::class)->transition(Subject::Shipment, $id, $dim, $from, $to, $event.':'.$to, $actor, $reason);
        app(Journal::class)->audit($event, Subject::Shipment, $id, $actor, $from ? ['status' => $from] : null, ['status' => $to]);
        app(Outbox::class)->record($event, 'shipment', $id, ['status' => $to]);
    }
}
