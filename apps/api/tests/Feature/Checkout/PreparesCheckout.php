<?php

namespace Tests\Feature\Checkout;

use App\Models\User;
use App\Modules\Customers\Profiles\CustomerProfiles;
use App\Modules\Identity\Sessions\DeviceSessions;
use App\Modules\Inventory\StockLedger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

trait PreparesCheckout
{
    private function checkoutAddress(int $owner, int $district): string
    {
        app(CustomerProfiles::class)->open($owner, 'Buyer');
        $public = (string) Str::ulid();
        DB::table('addresses')->insert(['public_id' => $public, 'user_id' => $owner, 'district_id' => $district, 'line1' => 'Old address',
            'phone' => '+628123456789', 'recipient' => 'Buyer', 'postal_code' => '12345']);

        return $public;
    }

    public function prepareCheckout(): void
    {
        config(['sanctum.stateful' => ['localhost']]);
        $this->buyer = User::factory()->create();
        $this->login = fn (User $user) => $this->actingAs($user)->withSession([DeviceSessions::SURFACE_KEY => 'CUSTOMER'])->withHeader('Origin', 'http://localhost');
        $parent = null;
        foreach (['COUNTRY' => 'ID', 'PROVINCE' => '11', 'CITY' => '11.01', 'DISTRICT' => '11.01.01'] as $kind => $code) {
            $parent = DB::table('regions')->insertGetId(['kind' => $kind, 'code' => $code, 'name' => $kind, 'parent_id' => $parent]);
        }
        $this->district = $parent;
        $this->body = ['cart_version' => 1, 'address_id' => $this->checkoutAddress($this->buyer->id, $parent)];
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
    }
}
