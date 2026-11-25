<?php

use App\Models\User;
use App\Modules\Cart\CustomerCart;
use App\Modules\Checkout\CheckoutPricing;
use App\Modules\Checkout\ConfirmCheckout;
use App\Modules\Inventory\StockLedger;
use App\Support\Http\ApiProblem;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\Concurrency;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Checkout\PreparesCheckout;

uses(DatabaseTruncation::class, PreparesCheckout::class);

beforeEach(function () {
    $this->prepareCheckout();
});

it('rejects guests, unverified buyers, empty carts, foreign addresses, inactive districts, coupons and missing rates', function () {
    ($this->preview)($this->body)->assertUnauthorized();
    ($this->login)(User::factory()->unverified()->create());
    ($this->preview)($this->body)->assertForbidden();
    ($this->login)($this->buyer);
    ($this->preview)(['cart_version' => 0] + $this->body)->assertConflict()->assertJsonPath('code', 'OFFER_UNAVAILABLE');
    app(CustomerCart::class)->set($this->buyer->id, $this->rts);
    ($this->preview)(['coupon' => 'PROMO'] + $this->body)->assertUnprocessable();
    ($this->confirm)(['coupon' => 'PROMO', 'quote_hash' => str_repeat('0', 64)] + $this->body)->assertUnprocessable()->assertJsonPath('code', 'VALIDATION_FAILED');
    ($this->preview)(['address_id' => $this->checkoutAddress(User::factory()->create()->id, $this->district)] + $this->body)->assertNotFound();
    DB::table('regions')->where('id', $this->district)->update(['active' => false]);
    ($this->preview)($this->body)->assertUnprocessable();
    DB::table('regions')->where('id', $this->district)->update(['active' => true]);
    DB::table('courier_services')->update(['active' => false]);
    ($this->preview)($this->body)->assertConflict()->assertJsonPath('code', 'NO_SHIPPING_RATE');
    $this->assertDatabaseCount('orders', 0);
});

it('prices and converts mixed groups once with immutable snapshots, tax rounding, and scoped result replay', function () {
    ($this->login)($this->buyer);
    $cart = app(CustomerCart::class);
    $cart->set($this->buyer->id, $this->rts);
    $cart->set($this->buyer->id, ['cart_version' => 1] + $this->po);
    [$data,$preview] = ($this->finalQuote)(['cart_version' => 2] + $this->body);
    expect(array_column($preview['groups'], 'due_now_amount'))->toBe([2411, 611])
        ->and(array_column($preview['groups'], 'total_amount'))->toBe([2411, 1656]);
    foreach (['orders', 'invoices', 'stock_reservations', 'preorder_allocations'] as $table) {
        $this->assertDatabaseCount($table, 0);
    }
    $this->postJson('/api/v1/checkout', $data)->assertUnprocessable();
    $result = ($this->confirm)($data)->assertCreated()->json('data');
    expect($result['order_numbers'])->toHaveCount(2)->and($result['invoice_numbers'])->toHaveCount(2);
    foreach (['first', 'second'] as $key) {
        ($this->confirm)($data, $key)->assertCreated()->assertJsonPath('data', $result);
    }
    ($this->confirm)(['notes' => 'new intent'] + $data)->assertUnprocessable()->assertJsonPath('code', 'IDEMPOTENCY_KEY_REUSED');
    ($this->confirm)(['notes' => 'new intent'] + $data, 'third')->assertConflict()->assertJsonPath('code', 'VERSION_CONFLICT');
    $this->assertDatabaseCount('invoices', 2);
    $this->assertDatabaseCount('stock_reservations', 1);
    $this->assertDatabaseCount('preorder_allocations', 1);
    $this->assertDatabaseHas('invoices', ['purpose' => 'DEPOSIT', 'total_amount' => 611, 'tax_amount' => 56]);
    $this->assertDatabaseHas('invoices', ['purpose' => 'FULL_PAYMENT', 'status' => 'ISSUED', 'total_amount' => 2411, 'shipping_amount' => 200]);
    $this->assertDatabaseHas('carts', ['status' => 'CONVERTED']);
    expect(DB::table('outbox_events')->where('event_type', 'OrderCreated')->count())->toBe(2)
        ->and(DB::table('outbox_events')->where('event_type', 'CheckoutCompleted')->count())->toBe(1);
    DB::table('addresses')->update(['line1' => 'New address']);
    DB::table('products')->update(['name' => 'New title']);
    DB::table('couriers')->update(['name' => 'New carrier']);
    DB::table('courier_services')->update(['code' => 'NEW']);
    DB::table('shipping_rates')->update(['eta_max_days' => 9, 'base_amount' => 900]);
    $rts = DB::table('orders')->where('selling_method', 'READY_TO_SHIP')->first();
    $po = DB::table('orders')->where('selling_method', 'PREORDER')->first();
    $this->getJson("/api/v1/orders/$rts->number")->assertOk()->assertJsonPath('data.status', 'PENDING_PAYMENT')
        ->assertJsonPath('data.checkout_shipping', ['quote_id' => $data['shipping_quote_id'], 'carrier_label' => 'Local Courier',
            'service_code' => 'REG', 'amount' => 200, 'estimated_days_from' => 1, 'estimated_days_to' => 3]);
    $this->getJson("/api/v1/orders/$po->number")->assertOk()->assertJsonMissingPath('data.checkout_shipping');
    expect(fn () => DB::table('shipping_quotes')->where('public_id', $data['shipping_quote_id'])->update(['used_at' => null]))->toThrow(QueryException::class);
    $this->assertDatabaseHas('order_addresses', ['line1' => 'Old address']);
    $this->assertDatabaseHas('order_items', ['product_snapshot' => 'Album']);
    $path = '/api/v1/checkouts/'.$result['id'];
    $this->getJson($path)->assertOk()->assertJsonPath('data', $result);
    ($this->login)(User::factory()->create());
    $this->getJson($path)->assertNotFound();
});

it('does not accept another buyers shipping option even for the same route and weight', function () {
    ($this->login)($this->buyer);
    app(CustomerCart::class)->set($this->buyer->id, $this->rts);
    $quote = ($this->preview)($this->body)->assertOk()->assertJsonMissingPath('data.quote_hash')->json('data.shipping_quotes.0.id');
    $other = User::factory()->create();
    ($this->login)($other);
    app(CustomerCart::class)->set($other->id, $this->rts);
    ($this->preview)(['address_id' => $this->checkoutAddress($other->id, $this->district), 'shipping_quote_id' => $quote] + $this->body)->assertNotFound();
});

it('does not exceed the per-account preorder maximum across two converted carts', function () {
    DB::table('preorder_campaign_variants')->update(['quota' => 8]);
    ($this->login)($this->buyer);
    app(CustomerCart::class)->set($this->buyer->id, $this->po);
    [$data,$preview] = ($this->finalQuote)($this->body);
    expect($preview['shipping_quotes'])->toBe([]);
    ($this->confirm)($data)->assertCreated();
    app(CustomerCart::class)->set($this->buyer->id, $this->po);
    ($this->preview)(['cart_version' => 2] + $this->body)->assertConflict()->assertJsonPath('code', 'OFFER_UNAVAILABLE');
    $this->assertDatabaseCount('preorder_allocations', 1);
});

it('stops offering an item with an archived assigned tax class instead of silently issuing a tax-free invoice', function (string $method) {
    ($this->login)($this->buyer);
    $input = $method === 'RTS' ? $this->rts : $this->po;
    app(CustomerCart::class)->set($this->buyer->id, $input);
    [$old] = ($this->finalQuote)($this->body);
    DB::table('tax_classes')->update(['active' => false]);
    $this->getJson('/api/v1/cart')->assertOk()->assertJsonPath('data.items.0.availability', 'UNAVAILABLE')->assertJsonPath('data.estimated_total_amount', 0);
    $this->postJson('/api/v1/cart/items', ['cart_version' => 1] + $input)->assertConflict()->assertJsonPath('code', 'OFFER_UNAVAILABLE');
    $this->getJson('/api/v1/products/album')->assertOk()->assertJsonCount(0, 'data.offers')->assertJsonCount(0, 'data.variants.0.offers');
    $this->getJson('/api/v1/products?method=RTS')->assertOk()->assertJsonCount(0, 'data');
    ($this->confirm)($old)->assertConflict()->assertJsonPath('code', 'QUOTE_CHANGED');
    ($this->preview)($this->body)->assertConflict()->assertJsonPath('code', 'OFFER_UNAVAILABLE');
    $this->assertDatabaseCount('invoices', 0);

    DB::table('products')->update(['tax_class_id' => null]);
    $this->getJson('/api/v1/cart')->assertOk()->assertJsonPath('data.items.0.availability', 'AVAILABLE');
    [$fresh, $preview] = ($this->finalQuote)($this->body);
    expect($preview['groups'][0]['tax_amount'])->toBe(0);
    ($this->confirm)($fresh, 'unassigned')->assertCreated();
    $this->assertDatabaseHas('order_items', ['tax_class_id' => null, 'tax_bps' => 0]);
})->with(['RTS', 'PO']);

it('requires a fresh quote after an offer rename without forcing the buyer to rebuild the cart', function (string $field) {
    ($this->login)($this->buyer);
    $cart = app(CustomerCart::class);
    $cart->set($this->buyer->id, $this->po);
    [$old] = ($this->finalQuote)($this->body);
    if ($field === 'sku') {
        app(StockLedger::class)->adjust(['level_id' => DB::table('inventory_levels')->value('id'), 'delta' => -12, 'reason_code' => 'INITIAL'], $this->buyer->id, 'clear');
        DB::table('product_variants')->where('sku', 'A1')->update(['sku' => 'NEW']);
    } else {
        DB::table('preorder_campaigns')->where('slug', 'po')->update(['slug' => 'renamed']);
    }
    $item = $cart->read($this->buyer->id)['items'][0];
    expect($item['availability'])->toBe('AVAILABLE')->and($item[$field])->toBe($field === 'sku' ? 'NEW' : 'renamed');
    ($this->confirm)($old)->assertConflict()->assertJsonPath('code', 'QUOTE_CHANGED');
    [$fresh, $preview] = ($this->finalQuote)($this->body);
    expect($preview['groups'][0]['campaign_slug'])->toBe($item['campaign_slug']);
    ($this->confirm)($fresh, 'after-rename')->assertCreated();
    $this->assertDatabaseHas('order_items', ['sku_snapshot' => $item['sku']]);
})->with(['sku', 'campaign_slug']);

it('requires a fresh preview after changes, expiration or stale cart edits', function (string $change, string $expected) {
    ($this->login)($this->buyer);
    app(CustomerCart::class)->set($this->buyer->id, $this->rts);
    [$data] = ($this->finalQuote)($this->body);
    match ($change) {
        'price' => DB::table('product_variants')->update(['rts_price_amount' => 99]),
        'rate' => DB::table('shipping_rates')->update(['eta_max_days' => 5]),
        'address' => DB::table('addresses')->update(['line1' => 'Moved']),
        'hash' => $data['quote_hash'] = str_repeat('0', 64),
        'expiry' => DB::table('checkout_previews')->update(['expires_at' => now()->subMinute()]),
        'version' => app(CustomerCart::class)->set($this->buyer->id, ['cart_version' => 1, 'qty' => 3] + $this->rts),
    };
    ($this->confirm)($data)->assertConflict()->assertJsonPath('code', $expected);
    $this->assertDatabaseCount('orders', 0);
    $this->assertDatabaseHas('carts', ['status' => 'ACTIVE']);
})->with([['price', 'QUOTE_CHANGED'], ['rate', 'QUOTE_CHANGED'], ['address', 'QUOTE_CHANGED'], ['hash', 'QUOTE_CHANGED'], ['expiry', 'QUOTE_EXPIRED'], ['version', 'VERSION_CONFLICT']]);

it('rolls back an RTS hold and invoice when the subsequent preorder invoice fails', function () {
    ($this->login)($this->buyer);
    app(CustomerCart::class)->set($this->buyer->id, $this->rts);
    app(CustomerCart::class)->set($this->buyer->id, ['cart_version' => 1] + $this->po);
    [$data] = ($this->finalQuote)(['cart_version' => 2] + $this->body);
    DB::statement("ALTER TABLE invoices ADD CONSTRAINT simulate_issue_failure CHECK(purpose != 'DEPOSIT')");
    try {
        ($this->confirm)($data)->assertServerError();
        foreach (['checkouts', 'orders', 'invoices', 'preorder_allocations', 'stock_reservations', 'order_addresses'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
        $this->assertDatabaseHas('inventory_levels', ['available' => 12]);
        $this->assertDatabaseHas('preorder_campaign_variants', ['reserved_qty' => 0]);
        $this->assertDatabaseHas('carts', ['status' => 'ACTIVE']);
        $this->assertDatabaseMissing('outbox_events', ['event_type' => 'InvoiceIssued']);
    } finally {
        DB::statement('ALTER TABLE invoices DROP CONSTRAINT simulate_issue_failure');
    }
    ($this->confirm)($data)->assertCreated();
});

it('leaves the chosen shipping option reusable when cart conversion fails after consumption', function () {
    ($this->login)($this->buyer);
    app(CustomerCart::class)->set($this->buyer->id, $this->rts);
    $express = DB::table('courier_services')->insertGetId(['courier_id' => DB::table('couriers')->value('id'), 'code' => 'EXP', 'name' => 'Express']);
    DB::table('shipping_rates')->insert(['service_id' => $express, 'origin_region_id' => $this->district, 'destination_region_id' => $this->district,
        'base_amount' => 400, 'eta_min_days' => 1, 'eta_max_days' => 1]);
    [$regular, $selected] = ($this->preview)($this->body)->assertOk()->json('data.shipping_quotes');
    $data = $this->body + ['shipping_quote_id' => $selected['id']];
    $data['quote_hash'] = ($this->preview)($data)->assertOk()->json('data.quote_hash');
    DB::statement("ALTER TABLE carts ADD CONSTRAINT simulate_conversion_failure CHECK (status <> 'CONVERTED')");
    try {
        ($this->confirm)($data)->assertServerError();
        $this->assertDatabaseHas('shipping_quotes', ['public_id' => $data['shipping_quote_id'], 'used_at' => null, 'carrier_label_snapshot' => null]);
        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('invoices', 0);
        $this->assertDatabaseCount('stock_reservations', 0);
        $this->assertDatabaseHas('inventory_levels', ['available' => 12, 'on_hand' => 12]);
    } finally {
        DB::statement('ALTER TABLE carts DROP CONSTRAINT simulate_conversion_failure');
    }
    $result = ($this->confirm)($data)->assertCreated()->json('data');
    ($this->confirm)($data, 'replay')->assertCreated()->assertJsonPath('data', $result);
    $this->assertDatabaseHas('shipping_quotes', ['public_id' => $regular['id'], 'used_at' => null]);
    $this->getJson('/api/v1/orders/'.$result['order_numbers'][0])->assertOk()->assertJsonPath('data.checkout_shipping.service_code', 'EXP')
        ->assertJsonPath('data.checkout_shipping.quote_id', $selected['id'])->assertJsonPath('data.checkout_shipping.amount', 400);
    expect(DB::table('shipping_quotes')->whereNotNull('used_at')->count())->toBe(1);
});

function checkoutWorker(array $intents, string $barrier, int $workers): Closure
{
    return static function () use ($intents, $barrier, $workers) {
        if (DB::connection()->getDatabaseName() !== 'snowego_test') {
            throw new LogicException('Expected disposable test database.');
        }
        touch($barrier.'/'.getmypid());
        $deadline = microtime(true) + 40;
        while (count(glob($barrier.'/*')) < $workers) {
            if (microtime(true) > $deadline) {
                throw new RuntimeException('Checkout workers did not reach the start barrier.');
            }
            usleep(20000);
        }
        $results = [];
        foreach ($intents as [$owner,$data]) {
            try {
                app(ConfirmCheckout::class)->submit($owner, $data);
                $results[] = 'OK';
            } catch (ApiProblem $e) {
                $results[] = $e->getStatusCode().':'.$e->errorCode;
            }
        }

        return $results;
    };
}

it('reserves exactly the available units when independent buyers confirm competing quotes', function (bool $po, int $count, int $units) {
    if (! $po && $units < 12) {
        app(StockLedger::class)->adjust(['level_id' => DB::table('inventory_levels')->value('id'), 'delta' => $units - 12, 'reason_code' => 'INITIAL'], $this->buyer->id, 'set-last-unit');
    }
    $intents = [];
    for ($i = 0; $i < $count; $i++) {
        $owner = User::factory()->create()->id;
        $data = ['address_id' => $this->checkoutAddress($owner, $this->district), 'cart_version' => 1];
        app(CustomerCart::class)->set($owner, ['qty' => 1] + ($po ? $this->po : $this->rts));
        $pricing = app(CheckoutPricing::class);
        $preview = $pricing->preview($owner, $data);
        if (! $po) {
            $data['shipping_quote_id'] = $preview['shipping_quotes'][0]['id'];
            $preview = $pricing->preview($owner, $data);
        }
        $intents[] = [$owner, $data + ['quote_hash' => $preview['quote_hash']]];
    }
    $chunks = array_chunk($intents, getenv('CHECKOUT_STRESS_100') === '1' ? 1 : max(1, intdiv($count, 10)));
    $barrier = sys_get_temp_dir().'/checkout-start-'.Str::uuid();
    mkdir($barrier, 0700);
    try {
        $results = array_merge(...Concurrency::driver('process')->run(array_map(fn ($chunk) => checkoutWorker($chunk, $barrier, count($chunks)), $chunks)));
    } finally {
        foreach (glob($barrier.'/*') as $flag) {
            unlink($flag);
        }
        rmdir($barrier);
    }
    $outcomes = array_count_values($results);
    expect($outcomes['OK'])->toBe($units)->and($outcomes[$po ? '409:QUOTA_FULL' : '409:OUT_OF_STOCK'])->toBe($count - $units);
    $this->assertDatabaseCount('orders', $units);
    $this->assertDatabaseCount('invoices', $units);
    $this->assertDatabaseCount('checkouts', $units);
    $this->assertDatabaseCount($po ? 'preorder_allocations' : 'stock_reservations', $units);
    $this->assertDatabaseHas('inventory_levels', ['reserved' => $po ? 0 : $units]);
    $this->assertDatabaseHas('preorder_campaign_variants', ['reserved_qty' => $po ? $units : 0]);
    if ($po) {
        $this->assertDatabaseCount('inventory_movements', 1);
        $this->assertDatabaseHas('inventory_levels', ['on_hand' => 12, 'reserved' => 0, 'available' => 12, 'committed' => 0]);
        $this->assertDatabaseHas('preorder_campaign_variants', ['quota' => 1, 'reserved_qty' => 1, 'confirmed_qty' => 0]);
    } else {
        expect(DB::table('orders')->where(['status' => 'PENDING_PAYMENT', 'payment_status' => 'UNPAID'])->count())->toBe($units)
            ->and(DB::table('stock_reservations')->where('status', 'ACTIVE')->count())->toBe($units)
            ->and(DB::table('shipping_quotes')->whereNotNull('used_at')->count())->toBe($units)
            ->and(DB::table('carts')->where('status', 'ACTIVE')->count())->toBe($count - $units);
        $this->assertDatabaseHas('inventory_levels', ['available' => 0, 'reserved' => $units, 'on_hand' => $units, 'committed' => 0]);
    }
})->with(['last RTS unit' => [false, 2, 1], 'RTS contention' => [false, 100, 12], 'last preorder slot' => [true, 2, 1]]);
