<?php

use App\Models\User;
use App\Modules\Cart\CustomerCart;
use App\Modules\Identity\Sessions\DeviceSessions;
use App\Modules\Orders\CheckoutOrders;
use App\Modules\Shipping\CourierApi;
use App\Modules\Shipping\CourierFailure;
use App\Modules\Shipping\CourierMasterSync;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\Feature\Checkout\PreparesCheckout;

uses(DatabaseTruncation::class, PreparesCheckout::class);

beforeEach(function () {
    $this->prepareCheckout();
    config(['services.api_co_id.key' => 'test-only']);
    Http::preventStrayRequests();
});

function courierFixtures(int $district): void
{
    DB::table('courier_region_codes')->insert(['code' => '317405', 'region_id' => $district]);
    CourierMasterSync::apply('couriers', null, [['code' => 'jne', 'name' => 'JNE Express', 'cod' => true]], null);
}

function courierOffer(array $extra = []): array
{
    return $extra + ['courier_code' => 'jne', 'service_code' => 'REG', 'service_name' => 'Reguler', 'etd' => '1-3', 'price' => 10000, 'handling_fee' => 0];
}

function courierStaff($test): User
{
    $test->seed(PermissionSeeder::class);
    config(['sanctum.stateful' => ['localhost', 'admin.localhost'], 'auth.admin_url' => 'http://admin.localhost']);
    $staff = User::factory()->staff()->create(['two_factor_confirmed_at' => now()])->refresh();
    $staff->assignRole('admin');
    $test->actingAs($staff)->withHeader('Origin', 'http://admin.localhost')->withSession([DeviceSessions::SURFACE_KEY => 'STAFF', 'staff.version' => $staff->staff_session_version, 'staff.started' => now()->timestamp, 'staff.seen' => now()->timestamp]);

    return $staff;
}

it('requires the explicit sync grant and replays the same imported page without another request', function () {
    Http::fake(function () {
        expect(DB::transactionLevel())->toBe(0);

        return Http::response(['is_success' => true, 'data' => [['code' => 'jne', 'name' => 'JNE Express', 'cod' => true]]]);
    });
    $staff = courierStaff($this);
    $sync = fn () => $this->postJson('/api/v1/admin/courier-master-syncs', ['group' => 'couriers'], ['Idempotency-Key' => 'sync-page']);
    $sync()->assertForbidden();
    Http::assertNothingSent();
    $staff->givePermissionTo('shipping.provider.sync');
    $sync()->assertOk()->assertJsonPath('data.count', 1);
    $sync()->assertOk()->assertJsonPath('data.count', 1);
    Http::assertSentCount(1);
});

it('freezes measured package freight and issues no invoice before the customer accepts', function (string $method) {
    courierFixtures($this->district);
    $order = DB::transaction(function () use ($method) {
        $addr = ['district_id' => $this->district, 'line1' => 'Street', 'recipient' => 'Buyer', 'phone' => '090', 'postal_code' => '12345', 'region_snapshot' => []];
        $group = ['method' => $method, 'term' => 'FULL', 'subtotal_amount' => 100, 'total_amount' => 100, 'shipping_amount' => 0, 'tax_amount' => 0, 'discount_amount' => 0, 'campaign_terms' => null,
            'lines' => [['product_snapshot' => 'Box', 'term' => 'FULL', 'qty' => 1, 'received_qty' => 1, 'unit_amount' => 100, 'initial_unit_amount' => 100, 'initial_line_amount' => 100, 'tax_amount' => 0, 'initial_tax' => 0, 'weight_g' => 600]]];
        $o = app(CheckoutOrders::class)->create(null, $this->buyer->id, $group, $addr, now()->addDay()->toISOString(), null);
        DB::table('orders')->where('id', $o['id'])->update(['status' => 'CONFIRMED']);

        return DB::table('orders')->find($o['id'])->number;
    });
    Http::fake(function (Request $req) {
        expect(DB::transactionLevel())->toBe(0)->and((string) $req['weight'])->toBe('1.25');

        return Http::response(['is_success' => true, 'data' => ['quote_id' => 'q_pkg', 'rates' => [courierOffer()]]]);
    });
    courierStaff($this);
    $route = ['warehouse_id' => DB::table('warehouses')->sole()->id, 'order_number' => $order, 'measured_weight_g' => 1250];
    $write = fn ($url, $body) => $this->postJson('/api/v1/'.$url, $body, ['Idempotency-Key' => (string) Str::uuid()]);
    $plan = ['items' => [['order_item_id' => DB::table('order_items')->sole()->public_id, 'qty' => 1]], 'service_code' => 'REG'] + $route;
    $option = $this->getJson('/api/v1/admin/shipment-rate-options?'.http_build_query($route))->assertOk()->assertJsonPath('data.0.source', 'API_CO_ID')->json('data.0');
    $plan += ['rate_key' => $option['rate_key'], 'service_id' => $option['service_id']];
    $this->travel(16)->minutes();
    $write('admin/shipments', $plan)->assertConflict()->assertJsonPath('code', 'QUOTE_CHANGED');
    $this->assertDatabaseCount('shipments', 0);
    $this->travelBack();
    $write('admin/shipments', ['measured_weight_g' => 1251] + $plan)->assertConflict()->assertJsonPath('code', 'QUOTE_CHANGED');
    $n = $write('admin/shipments', $plan)->assertOk()->assertJsonPath('data.shipping_amount', 10000)->json('data.number');
    $q = $write('admin/shipping-quotes', ['order_number' => $order, 'shipment_number' => $n])->assertOk()->json('data.0.id');
    $proposal = $write("admin/orders/$order/charge-consents", ['type' => 'DOMESTIC_SHIPPING', 'quote_id' => $q, 'expires_at' => now()->addHours(4)->toISOString()])->assertOk()->json('data.id');
    $this->assertDatabaseCount('invoices', 0);
    ($this->login)($this->buyer);
    $write("orders/$order/charge-consents/$proposal/decisions", ['decision' => 'ACCEPT'])->assertOk();
    $this->assertDatabaseHas('invoices', ['purpose' => 'ADDITIONAL_CHARGE', 'total_amount' => 10000]);
    Http::assertSentCount(1);
})->with(['PREORDER', 'REQUEST_ORDER']);

it('maps each reference beneath its parent without changing existing address IDs or unarchiving couriers', function () {
    $base = 'https://use.api.co.id/courier/v1/';
    $responses = [];
    foreach (['provinces' => ['31', 'PROVINCE'], 'cities?province=31' => ['3174', 'CITY'], 'districts?city=3174' => ['317405', 'DISTRICT']] as $path => [$code,$name]) {
        $responses[$base.'locations/'.$path] = Http::response(['is_success' => true, 'data' => [compact('code', 'name')]]);
    }
    $responses[$base.'couriers'] = Http::response(['is_success' => true, 'data' => [['code' => 'local', 'name' => 'Local Courier', 'cod' => false]]]);
    Http::fake($responses);
    DB::table('couriers')->update(['active' => false]);
    foreach ([['couriers', null], ['provinces', null], ['cities', '31'], ['districts', '3174'], ['districts', '3174']] as [$group,$parent]) {
        $rows = CourierMasterSync::fetch($group, $parent);
        CourierMasterSync::apply($group, $parent, $rows, null);
    }
    expect(DB::table('regions')->count())->toBe(4)
        ->and(DB::table('courier_region_codes')->where('code', '317405')->value('region_id'))->toBe($this->district)
        ->and(DB::table('addresses')->sole()->district_id)->toBe($this->district)
        ->and(DB::table('couriers')->sole()->active)->toBeFalse();
});

it('requires an explicit verified binding for similar codes with different names and refuses to rebind it', function () {
    $row = [['code' => '11', 'name' => 'Different spelling']];
    expect(fn () => CourierMasterSync::apply('provinces', null, $row, null))->toThrow(ValidationException::class);
    CourierMasterSync::apply('provinces', null, $row, null, ['11' => '11']);
    $this->assertDatabaseHas('regions', ['code' => '11', 'name' => 'PROVINCE']);
    DB::table('regions')->insert(['code' => 'other', 'name' => 'Other', 'kind' => 'PROVINCE', 'parent_id' => DB::table('regions')->where('code', 'ID')->value('id')]);
    expect(fn () => CourierMasterSync::apply('provinces', null, $row, null, ['11' => 'other']))->toThrow(ValidationException::class);
    $this->assertDatabaseCount('courier_region_codes', 1);
});

it('binds the exact RTS grams and freezes the chosen total across preview confirm and replay', function (array $extra, int $expected) {
    courierFixtures($this->district);
    Http::fake(function (Request $req) use ($extra) {
        expect(DB::transactionLevel())->toBe(0)->and($req->hasHeader('x-api-co-id', 'test-only'))->toBeTrue()
            ->and($req['origin_district_code'])->toBe('317405')->and($req['destination_district_code'])->toBe('317405')
            ->and((string) $req['weight'])->toBe('1.002');

        return Http::response(['is_success' => true, 'data' => ['quote_id' => 'q_1', 'rates' => [courierOffer($extra)]]]);
    });
    ($this->login)($this->buyer);
    app(CustomerCart::class)->set($this->buyer->id, $this->rts);
    [$data, $result] = ($this->finalQuote)($this->body);
    expect($result['shipping_quotes'][0])->toMatchArray(['source' => 'API_CO_ID', 'amount' => $expected]);
    config(['services.api_co_id.key' => null]);
    ($this->confirm)($data)->assertCreated();
    ($this->confirm)($data)->assertCreated();
    $this->assertDatabaseHas('invoices', ['shipping_amount' => $expected]);
    $this->assertDatabaseHas('provider_freights', ['quote_id' => 'q_1', 'amount' => $expected, 'weight_g' => 1002]);
    $this->assertDatabaseHas('shipping_quotes', ['public_id' => $data['shipping_quote_id'], 'rate_id' => null]);
    Http::assertSentCount(1);
})->with([[['total_price' => 10500, 'handling_fee' => 500], 10500], [[], 10000]]);

it('rejects a selected provider quote if weight changes or it expires without contacting the provider', function () {
    courierFixtures($this->district);
    Http::fake(['*' => Http::response(['is_success' => true, 'data' => ['quote_id' => 'q_1', 'rates' => [courierOffer()]]])]);
    ($this->login)($this->buyer);
    app(CustomerCart::class)->set($this->buyer->id, $this->rts);
    [$data] = ($this->finalQuote)($this->body);
    DB::table('product_variants')->increment('weight_g');
    ($this->confirm)($data)->assertConflict()->assertJsonPath('code', 'QUOTE_CHANGED');
    $this->travel(16)->minutes();
    ($this->preview)(['shipping_quote_id' => $data['shipping_quote_id']] + $this->body)->assertConflict()->assertJsonPath('code', 'QUOTE_EXPIRED');
    Http::assertSentCount(1);
});

it('offers only a matching active manual fallback for unusable provider responses', function (string $failure) {
    courierFixtures($this->district);
    $rates = match ($failure) {
        'ambiguous price' => [courierOffer(['handling_fee' => 500])],
        'invalid total' => [courierOffer(['total_price' => -1])],
        'missing price' => [array_diff_key(courierOffer(), ['price' => true])],
        'unmapped courier' => [courierOffer(['courier_code' => 'unknown'])],
        default => [],
    };
    Http::fake(['*' => $failure === 'connection' ? Http::failedConnection() : Http::response(['is_success' => true, 'data' => ['quote_id' => 'q_1', 'rates' => $rates]])]);
    ($this->login)($this->buyer);
    app(CustomerCart::class)->set($this->buyer->id, $this->rts);
    ($this->preview)($this->body)->assertOk()->assertJsonPath('data.shipping_quotes.0.source', 'MANUAL')->assertJsonPath('data.shipping_quotes.0.amount', 200);
    DB::table('shipping_rates')->update(['active' => false]);
    ($this->preview)($this->body)->assertConflict()->assertJsonPath('code', 'NO_SHIPPING_RATE');
})->with(['empty', 'connection', 'ambiguous price', 'invalid total', 'missing price', 'unmapped courier']);

it('normalizes provider errors without leaking provider text or expiring the local session', function (int $status, string $message) {
    Http::fake(['*' => Http::response(['message' => 'upstream diagnostics'], $status)]);
    try {
        CourierApi::get('v1/couriers');
        $this->fail('Expected provider failure');
    } catch (CourierFailure $error) {
        expect($error->upstreamStatus)->toBe($status)->and($error->getStatusCode())->toBe(503)
            ->and($error->render()->getData(true))->toBe(['is_success' => false, 'message' => $message]);
    }
})->with([[400, 'Parameter wilayah atau berat tidak valid.'], [401, 'Autentikasi ke penyedia ongkir gagal.']]);

it('suppresses rate traffic for all routes until reset_at and can resume afterwards', function () {
    Http::fakeSequence()->push(['error' => 'quota_exceeded', 'reset_at' => now()->addHours(8)->toIso8601String()], 429)->push(['is_success' => true, 'data' => []]);
    expect(fn () => CourierApi::get('v2/rates'))->toThrow(CourierFailure::class);
    $this->travel(7)->hours();
    expect(fn () => CourierApi::get('v2/rates'))->toThrow(CourierFailure::class);
    Http::assertSentCount(1);
    $this->travel(2)->hours();
    expect(CourierApi::get('v2/rates'))->toBe([]);
    Http::assertSentCount(2);
});
