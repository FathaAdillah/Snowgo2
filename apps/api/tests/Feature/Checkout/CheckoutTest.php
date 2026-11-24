<?php

use App\Models\User;
use App\Modules\Cart\CustomerCart;
use App\Modules\Checkout\CheckoutPricing;
use App\Modules\Checkout\ConfirmCheckout;
use App\Modules\Customers\Profiles\CustomerProfiles;
use App\Modules\Identity\Sessions\DeviceSessions;
use App\Modules\Inventory\StockLedger;
use App\Support\Http\ApiProblem;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\Concurrency;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(DatabaseTruncation::class);

function checkoutAddress(int $owner, int $district): string
{
    app(CustomerProfiles::class)->open($owner, 'Buyer');
    $public = (string) Str::ulid();
    DB::table('addresses')->insert(['public_id' => $public, 'user_id' => $owner, 'district_id' => $district, 'line1' => 'Old address',
        'phone' => '+628123456789', 'recipient' => 'Buyer', 'postal_code' => '12345']);

    return $public;
}

beforeEach(function () {
    config(['sanctum.stateful' => ['localhost']]);
    $this->buyer = User::factory()->create();
    $this->login = fn (User $user) => $this->actingAs($user)->withSession([DeviceSessions::SURFACE_KEY => 'CUSTOMER'])->withHeader('Origin', 'http://localhost');
    $parent = null;
    foreach (['COUNTRY' => 'ID', 'PROVINCE' => '11', 'CITY' => '11.01', 'DISTRICT' => '11.01.01'] as $kind => $code) {
        $parent = DB::table('regions')->insertGetId(['kind' => $kind, 'code' => $code, 'name' => $kind, 'parent_id' => $parent]);
    }
    $this->district = $parent;
    $this->body = ['cart_version' => 1, 'address_id' => checkoutAddress($this->buyer->id, $parent)];
    $this->rts = ['cart_version' => 0, 'sku' => 'A1', 'term' => 'FULL', 'qty' => 2];
    $this->po = ['term' => 'DEPOSIT', 'campaign_slug' => 'po', 'qty' => 1] + $this->rts;
    $unit = DB::table('units')->insertGetId(['code' => 'pcs', 'name' => 'Piece']);
    $tax = DB::table('tax_classes')->insertGetId(['code' => 'T', 'name' => 'Test tax', 'rate_bps' => 1000]);
    $p = DB::table('products')->insertGetId(['slug' => 'album', 'name' => 'Album', 'status' => 'PUBLISHED', 'tax_class_id' => $tax, 'published_at' => now()]);
    $v = DB::table('product_variants')->insertGetId(['product_id' => $p, 'unit_id' => $unit, 'sku' => 'A1', 'name' => 'Album A', 'option_key' => 'a',
        'weight_g' => 501, 'rts_enabled' => true, 'rts_price_amount' => 1005]);
    $w = DB::table('warehouses')->insertGetId(['code' => 'JKT', 'name' => 'JKT', 'district_id' => $parent, 'address' => 'Origin', 'is_default' => true]);
    DB::table('reason_codes')->insert(['domain' => 'inventory', 'code' => 'INITIAL', 'label' => 'Initial stock']);
    app(StockLedger::class)->receive(['warehouse_id' => $w, 'sku' => 'A1', 'qty' => 12, 'type' => 'INITIAL', 'reason_code' => 'INITIAL'], $this->buyer->id, 'opening');
    $c = DB::table('couriers')->insertGetId(['code' => 'LOCAL', 'name' => 'Local Courier']);
    $service = DB::table('courier_services')->insertGetId(['courier_id' => $c, 'code' => 'REG', 'name' => 'Regular']);
    DB::table('shipping_rates')->insert(['service_id' => $service, 'origin_region_id' => $parent, 'destination_region_id' => $parent,
        'base_amount' => 100, 'amount_per_kg' => 50, 'eta_min_days' => 1, 'eta_max_days' => 3]);
    $campaign = DB::table('preorder_campaigns')->insertGetId(['number' => 'PO-1', 'slug' => 'po', 'name' => 'PO', 'status' => 'OPEN',
        'starts_at' => now()->subDay(), 'ends_at' => now()->addDay(), 'ready_eta_from' => today(), 'ready_eta_to' => today(), 'ship_eta_from' => today(),
        'ship_eta_to' => today(), 'origin' => 'KR', 'transport_mode' => 'AIR', 'payment_scheme' => 'FULL_OR_DEPOSIT', 'terms' => 'Terms 1']);
    DB::table('preorder_campaign_variants')->insert(['campaign_id' => $campaign, 'variant_id' => $v, 'quota' => 1, 'max_qty' => 1, 'unit_amount' => 1505, 'deposit_unit_amount' => 555]);
    $this->preview = fn (array $data) => $this->postJson('/api/v1/checkout/previews', $data);
    $this->confirm = fn (array $data, string $key = 'first') => $this->postJson('/api/v1/checkout', $data, ['Idempotency-Key' => $key]);
    $this->finalQuote = function (array $body) {
        $options = ($this->preview)($body)->assertOk()->json('data.shipping_quotes');
        if ($options) {
            $body['shipping_quote_id'] = $options[0]['id'];
        }
        $result = ($this->preview)($body)->assertOk()->json('data');

        return [$body + ['quote_hash' => $result['quote_hash']], $result];
    };
});

it('rejects guests, unverified buyers, empty carts, foreign addresses, inactive districts, coupons and missing rates', function () {
    ($this->preview)($this->body)->assertUnauthorized();
    ($this->login)(User::factory()->unverified()->create());
    ($this->preview)($this->body)->assertForbidden();
    ($this->login)($this->buyer);
    ($this->preview)(['cart_version' => 0] + $this->body)->assertConflict()->assertJsonPath('code', 'OFFER_UNAVAILABLE');
    app(CustomerCart::class)->set($this->buyer->id, $this->rts);
    ($this->preview)(['coupon' => 'PROMO'] + $this->body)->assertUnprocessable();
    ($this->preview)(['address_id' => checkoutAddress(User::factory()->create()->id, $this->district)] + $this->body)->assertNotFound();
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
    $this->assertDatabaseHas('carts', ['status' => 'CONVERTED']);
    expect(DB::table('outbox_events')->where('event_type', 'OrderCreated')->count())->toBe(2)
        ->and(DB::table('outbox_events')->where('event_type', 'CheckoutCompleted')->count())->toBe(1);
    DB::table('addresses')->update(['line1' => 'New address']);
    DB::table('products')->update(['name' => 'New title']);
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
    ($this->preview)(['address_id' => checkoutAddress($other->id, $this->district), 'shipping_quote_id' => $quote] + $this->body)->assertNotFound();
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

it('refuses an archived assigned tax class instead of silently issuing a tax-free invoice', function () {
    ($this->login)($this->buyer);
    app(CustomerCart::class)->set($this->buyer->id, $this->rts);
    [$old] = ($this->finalQuote)($this->body);
    DB::table('tax_classes')->update(['active' => false]);
    ($this->confirm)($old)->assertConflict()->assertJsonPath('code', 'QUOTE_CHANGED');
    ($this->preview)($this->body)->assertConflict()->assertJsonPath('code', 'OFFER_UNAVAILABLE');
    $this->assertDatabaseCount('invoices', 0);

    DB::table('products')->update(['tax_class_id' => null]);
    [$fresh, $preview] = ($this->finalQuote)($this->body);
    expect($preview['groups'][0]['tax_amount'])->toBe(0);
    ($this->confirm)($fresh, 'unassigned')->assertCreated();
    $this->assertDatabaseHas('order_items', ['tax_class_id' => null, 'tax_bps' => 0]);
});

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
                $results[] = $e->errorCode;
            }
        }

        return $results;
    };
}

it('reserves exactly the available units when independent buyers confirm competing quotes', function (bool $po, int $count, int $units) {
    $intents = [];
    for ($i = 0; $i < $count; $i++) {
        $owner = User::factory()->create()->id;
        $data = ['address_id' => checkoutAddress($owner, $this->district), 'cart_version' => 1];
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
    expect($outcomes['OK'])->toBe($units)->and($outcomes[$po ? 'QUOTA_FULL' : 'OUT_OF_STOCK'])->toBe($count - $units);
    $this->assertDatabaseCount('orders', $units);
    $this->assertDatabaseCount('invoices', $units);
    $this->assertDatabaseCount('checkouts', $units);
    $this->assertDatabaseCount($po ? 'preorder_allocations' : 'stock_reservations', $units);
    $this->assertDatabaseHas('inventory_levels', ['reserved' => $po ? 0 : $units]);
    $this->assertDatabaseHas('preorder_campaign_variants', ['reserved_qty' => $po ? $units : 0]);
})->with([[false, 100, 12], [true, 2, 1]]);
