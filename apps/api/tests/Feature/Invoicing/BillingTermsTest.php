<?php

use App\Models\User;
use App\Modules\Identity\Sessions\DeviceSessions;
use App\Modules\Invoicing\InitialInvoices;
use App\Modules\Orders\CheckoutOrders;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Checkout\PreparesCheckout;

uses(DatabaseTruncation::class, PreparesCheckout::class);
beforeEach(function () {
    $this->prepareCheckout();
    $this->order = DB::transaction(function () {
        $group = ['method' => 'REQUEST_ORDER', 'term' => 'DEPOSIT', 'subtotal_amount' => 300, 'total_amount' => 300, 'shipping_amount' => 0, 'tax_amount' => 0, 'discount_amount' => 0, 'due_now_amount' => 100, 'initial_tax' => 0, 'campaign_terms' => null,
            'lines' => [['product_snapshot' => 'Box', 'term' => 'DEPOSIT', 'qty' => 3, 'unit_amount' => 100, 'initial_unit_amount' => 33, 'initial_line_amount' => 100, 'tax_amount' => 0, 'initial_tax' => 0, 'received_qty' => 1, 'weight_g' => 600]]];
        $address = ['district_id' => $this->district, 'line1' => 'Street', 'recipient' => 'Buyer', 'phone' => '090', 'postal_code' => '12345', 'region_snapshot' => []];
        $due = now()->addDays(1)->toISOString();
        $o = app(CheckoutOrders::class)->create(null, $this->buyer->id, $group, $address, $due, null);
        app(InitialInvoices::class)->issue($o['id'], $this->buyer->id, $group, $o['lines'], $due, 'quotation:test');
        DB::table('invoices')->where('order_id', $o['id'])->update(['status' => 'PAID']);
        DB::table('orders')->where('id', $o['id'])->update(['status' => 'CONFIRMED', 'payment_status' => 'PARTIALLY_PAID']);

        return DB::table('orders')->where('id', $o['id'])->value('number');
    });
    $this->item = DB::table('order_items')->sole();
    $this->seed(PermissionSeeder::class);
    config(['sanctum.stateful' => ['localhost', 'admin.localhost'], 'auth.admin_url' => 'http://admin.localhost']);
    $this->staff = User::factory()->staff()->create(['two_factor_confirmed_at' => now()])->refresh();
    $this->staff->assignRole('admin');
    $this->asStaff = fn () => $this->actingAs($this->staff)->withHeader('Origin', 'http://admin.localhost')->withSession([DeviceSessions::SURFACE_KEY => 'STAFF', 'staff.version' => $this->staff->staff_session_version, 'staff.started' => now()->timestamp, 'staff.seen' => now()->timestamp]);
    ($this->asStaff)();
    $this->write = fn (string $path, array $body, ?string $key = null) => $this->postJson('/api/v1/'.$path, $body, ['Idempotency-Key' => $key ?? (string) Str::uuid()]);
    $this->balance = ['purpose' => 'BALANCE', 'due_at' => now()->addDay()->toISOString()];
});
it('allocates the remainder rupiah to the first unit and never bills a receipt twice', function () {
    $url = "admin/orders/$this->order/invoices";
    ($this->write)($url, $this->balance, 'one')->assertOk()->assertJsonPath('data.total_amount', 66);
    ($this->write)($url, $this->balance, 'one')->assertOk();
    ($this->write)($url, $this->balance)->assertConflict();
    DB::table('order_items')->where('id', $this->item->id)->update(['received_qty' => 3]);
    ($this->write)($url, $this->balance)->assertOk()->assertJsonPath('data.total_amount', 134);
    expect((int) DB::table('invoices')->where('purpose', 'BALANCE')->sum('total_amount'))->toBe(200);
});
it('freezes a fee revision, rejects foreign decisions and issues once only after acceptance', function () {
    $url = "admin/orders/$this->order/charge-consents";
    $d = ['type' => 'IMPORT_TAX', 'description' => 'Duty', 'order_item_id' => $this->item->public_id, 'qty' => 1, 'unit_amount' => 10, 'expires_at' => now()->addDay()->toISOString()];
    $a = ($this->write)($url, $d)->assertOk()->json('data.id');
    $b = ($this->write)($url, ['unit_amount' => 11] + $d)->assertOk()->json('data.id');
    expect(DB::table('invoices')->where('purpose', 'ADDITIONAL_CHARGE')->count())->toBe(0);
    $decide = fn ($id) => ($this->write)("orders/$this->order/charge-consents/$id/decisions", ['decision' => 'ACCEPT'], 'accept:'.$id);
    ($this->login)(User::factory()->create());
    $decide($b)->assertNotFound();
    ($this->login)($this->buyer);
    $decide($a)->assertConflict();
    $decide($b)->assertOk()->assertJsonPath('data.amount', 11);
    $decide($b)->assertOk();
    expect(DB::table('invoices')->where('purpose', 'ADDITIONAL_CHARGE')->count())->toBe(1);
    $this->getJson("/api/v1/orders/$this->order")->assertOk()->assertJsonPath('data.charge_consents.1.status', 'ACCEPTED');
});
it('preserves the original unpaid tranche when shortage reduces eligible units', function () {
    DB::table('order_items')->update(['received_qty' => 3]);
    $v = ($this->write)("admin/orders/$this->order/invoices", $this->balance)->assertOk()->json('data.number');
    DB::table('order_items')->update(['received_qty' => 2, 'cancelled_qty' => 1]);
    DB::table('reason_codes')->insert(['code' => 'SHORT', 'label' => 'Shortage', 'domain' => 'invoicing']);
    ($this->write)("admin/orders/$this->order/credit-adjustments", ['order_item_id' => $this->item->public_id, 'reason_code' => 'SHORT', 'invoice_number' => $v])->assertOk()->assertJsonPath('data.replaces_invoice_number', $v)->assertJsonPath('data.total_amount', 133);
    $this->assertDatabaseHas('invoices', ['number' => $v, 'status' => 'VOID', 'total_amount' => 200]);
});

it('never invoices a declined or expired fee, even if expiry cleanup has not run', function () {
    $path = "admin/orders/$this->order/charge-consents";
    $d = ['type' => 'SERVICE_FEE', 'description' => 'Packing', 'order_item_id' => $this->item->public_id, 'qty' => 1, 'unit_amount' => 17, 'expires_at' => now()->addMinute()->toISOString()];
    $id = ($this->write)($path, $d)->assertOk()->json('data.id');
    ($this->login)($this->buyer);
    ($this->write)("orders/$this->order/charge-consents/$id/decisions", ['decision' => 'REJECT'])->assertOk()->assertJsonPath('data.status', 'REJECTED');
    ($this->asStaff)();
    $id = ($this->write)($path, $d)->assertOk()->json('data.id');
    $this->travel(2)->minutes();
    ($this->login)($this->buyer);
    ($this->write)("orders/$this->order/charge-consents/$id/decisions", ['decision' => 'ACCEPT'])->assertConflict();
    expect(DB::table('invoices')->where('purpose', 'ADDITIONAL_CHARGE')->exists())->toBeFalse();
});

it('keeps an unshipped unit eligible for fees when a later package leaves first', function () {
    DB::table('order_items')->update(['received_qty' => 2]);
    DB::table('shipping_rates')->update(['base_amount' => 0, 'amount_per_kg' => 0]);
    ($this->write)("admin/orders/$this->order/invoices", $this->balance)->assertOk();
    DB::table('invoices')->where('purpose', 'BALANCE')->update(['status' => 'PAID']);
    $package = ['order_number' => $this->order, 'warehouse_id' => DB::table('warehouses')->sole()->id, 'service_code' => 'REG', 'items' => [['order_item_id' => $this->item->public_id, 'qty' => 1]]];
    ($this->write)('admin/shipments', $package)->assertOk();
    $second = ($this->write)('admin/shipments', $package)->assertOk()->json('data.number');
    $url = "admin/orders/$this->order/charge-consents";
    $fee = ['type' => 'IMPORT_TAX', 'description' => 'Duty', 'order_item_id' => $this->item->public_id, 'qty' => 1, 'unit_amount' => 10, 'expires_at' => now()->addDay()->toISOString()];
    $pending = ($this->write)($url, $fee)->assertOk()->assertJsonPath('data.lines.0.start', 0)->json('data.id');
    foreach (['PACK_START', 'PACK_FINISH', 'READY_FOR_DISPATCH'] as $stage) {
        ($this->write)("admin/orders/$this->order/packing-events", ['shipment_number' => $second, 'action' => $stage])->assertOk();
    }
    ($this->write)("admin/shipments/$second/dispatches", ['service_code' => 'REG', 'tracking_number' => 'SECOND'])->assertOk();
    ($this->login)($this->buyer);
    ($this->write)("orders/$this->order/charge-consents/$pending/decisions", ['decision' => 'ACCEPT'])->assertOk();
    ($this->asStaff)();
    ($this->write)($url, $fee)->assertConflict();
    ($this->write)($url, ['type' => 'SERVICE_FEE'] + $fee)->assertOk()->assertJsonPath('data.lines.0.start', 0);
});
