<?php

use App\Models\User;
use App\Modules\Catalog\Resource;
use App\Modules\Foundation\Files\MalwareScanner;
use App\Modules\Foundation\Files\ScanStoredFile;
use App\Modules\Foundation\Outbox\ConsumeEvent;
use App\Modules\Identity\Sessions\DeviceSessions;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

uses(DatabaseTruncation::class);

beforeEach(function () {
    config(['sanctum.stateful' => ['admin.localhost'], 'auth.admin_url' => 'http://admin.localhost']);
    $this->seed(PermissionSeeder::class);
    $this->actor = User::factory()->staff()->create(['two_factor_confirmed_at' => now()])->refresh();
    $this->actingAs($this->actor)->withHeader('Origin', 'http://admin.localhost')->withSession([
        DeviceSessions::SURFACE_KEY => 'STAFF', 'staff.version' => $this->actor->staff_session_version,
        'staff.seen' => now()->timestamp, 'staff.started' => now()->timestamp,
    ]);
    $this->api = fn ($method, $path, $body = [], $key = null) => $this->json($method, '/api/v1/admin/'.$path, $body,
        ['Idempotency-Key' => $key ?? (string) Str::uuid()]);
    $this->create = fn ($path, $body) => ($this->api)('POST', $path, $body)->assertCreated()->json('data.id');
    $this->item = ['name' => 'Kopi Pin', 'slug' => 'kopi-pin', 'description' => 'Koleksi pin', 'status' => 'DRAFT', 'authenticity' => 'OFFICIAL', 'category_ids' => []];
    $this->variant = ['sku' => 'PIN-1', 'name' => 'Pin', 'unit_id' => DB::table('units')->insertGetId(['code' => 'pcs', 'name' => 'Buah']),
        'weight_g' => 40, 'rts_enabled' => true, 'rts_price_amount' => 25000, 'active' => true, 'attribute_value_ids' => []];
});

it('denies all nine resources without grants and separates create, edit, publish and archive', function () {
    $api = $this->api;
    foreach (Resource::cases() as $kind) {
        $api('GET', $kind->path())->assertForbidden();
        $api('GET', $kind->path().'/1')->assertForbidden();
        $api('POST', $kind->path())->assertForbidden();
        $api('PATCH', $kind->path().'/1')->assertForbidden();
    }
    $this->actor->givePermissionTo('catalog.product.create');
    $id = $api('POST', 'products', $this->item, 'draft')->assertCreated()->json('data.id');
    $api('POST', 'products', $this->item, 'draft')->assertCreated()->assertJsonPath('data.id', $id);
    $this->assertDatabaseCount('products', 1);
    $api('GET', "products/$id")->assertForbidden();
    $api('PATCH', "products/$id", ['name' => 'Other'])->assertForbidden();
    $this->actor->givePermissionTo('catalog.product.update');
    $api('PATCH', "products/$id", ['status' => 'PUBLISHED'])->assertForbidden();
    $api('PATCH', "products/$id", ['status' => 'ARCHIVED'])->assertForbidden();
    $api('PATCH', "products/$id", ['search_vector' => 'test'])->assertUnprocessable();
    $api('PATCH', "products/$id", [])->assertUnprocessable();
    $api('POST', 'products', $this->item)->assertConflict()->assertJsonPath('code', 'DUPLICATE_RESOURCE');
    $this->assertDatabaseCount('audit_logs', 1);
    $this->assertDatabaseCount('outbox_events', 1);
});

it('grants content managers read-only catalog access on a fresh install', function () {
    $this->actor->assignRole('content_manager');
    foreach (Resource::cases() as $kind) {
        ($this->api)('GET', $kind->path())->assertOk();
        ($this->api)('POST', $kind->path())->assertForbidden();
        ($this->api)('PATCH', $kind->path().'/1')->assertForbidden();
        expect($this->actor->can($kind->permission('archive')))->toBeFalse();
    }
    expect($this->actor->can('catalog.product.publish'))->toBeFalse();
});

it('rejects cycles and duplicates and applies active filters before pagination', function () {
    $this->actor->assignRole('product_manager');
    $create = $this->create;
    $api = $this->api;
    $root = $create('categories', ['name' => 'Root', 'slug' => 'root', 'active' => true]);
    $child = $create('categories', ['name' => 'Child', 'slug' => 'child', 'parent_id' => $root, 'active' => true]);
    $api('PATCH', "categories/$root", ['parent_id' => $child])->assertUnprocessable();
    $api('PATCH', "categories/$root", ['active' => false])->assertConflict();
    $this->getJson('/api/v1/categories?parent=root')->assertOk()->assertJsonPath('data.0.parent_slug', 'root')->assertJsonMissingPath('data.0.id');
    $api('PATCH', "categories/$child", ['active' => false])->assertOk();
    $this->getJson('/api/v1/categories?per_page=1')->assertOk()->assertJsonPath('meta.total', 1);
    $api('GET', 'categories?q=child&active=0')->assertOk()->assertJsonPath('meta.total', 1);
    $api('POST', 'categories', ['slug' => 'Root!', 'name' => 'bad', 'active' => true])->assertUnprocessable();
});

it('publishes only with valid category and SKU, keeps prices in IDR and never fabricates stock or preorder availability', function () {
    $this->actor->assignRole('product_manager');
    $create = $this->create;
    $api = $this->api;
    $dictionary = ['slug' => 'pins', 'name' => 'Pins', 'active' => true];
    $category = $create('categories', $dictionary);
    $brand = $create('brands', $dictionary);
    $tag = $create('tags', $dictionary);
    $collection = $create('collections', $dictionary);
    $product = $create('products', $this->item + ['brand_id' => $brand]);
    $api('PATCH', "products/$product", ['status' => 'PUBLISHED'])->assertConflict();
    $this->getJson('/api/v1/products/kopi-pin')->assertNotFound();
    $this->getJson('/api/v1/products')->assertJsonCount(0, 'data');
    $variant = $create('variants', ['product_id' => $product] + $this->variant);
    $api('PATCH', "products/$product", ['status' => 'PUBLISHED', 'category_ids' => []])->assertConflict();
    $api('PATCH', "products/$product", ['status' => 'PUBLISHED', 'category_ids' => [$category], 'collection_ids' => [$collection], 'tag_ids' => [$tag]])->assertOk();
    $this->getJson('/api/v1/products?brand=pins&category=pins&tag=pins&collection=pins&method=RTS&q=pin&sort=name')
        ->assertOk()->assertJsonPath('data.0.offers.0.from_amount', 25000)->assertJsonPath('data.0.category_slugs', ['pins'])
        ->assertJsonPath('data.0.brand_slug', 'pins')->assertJsonPath('data.0.offers.0.available', false)->assertJsonMissingPath('data.0.id');
    $this->getJson('/api/v1/products/kopi-pin')->assertOk()->assertJsonPath('data.variants.0.offers.0.unit_amount', 25000)
        ->assertJsonPath('data.variants.0.sku', 'PIN-1')->assertJsonMissingPath('data.variants.0.unit_id');
    $this->getJson('/api/v1/products?method=PO')->assertJsonPath('meta.total', 0);
    $this->getJson('/api/v1/products?status=DRAFT')->assertUnprocessable();
    $api('PATCH', "categories/$category", ['active' => false])->assertConflict();
    $api('PATCH', "variants/$variant", ['active' => false])->assertConflict();
    $api('PATCH', "products/$product", ['status' => 'ARCHIVED'])->assertOk();
    $this->getJson('/api/v1/products/kopi-pin')->assertNotFound();
    $api('PATCH', "variants/$variant", ['active' => false])->assertOk();
});

it('enforces global SKUs, canonical unordered options, one option per attribute and one default per product', function () {
    $this->actor->assignRole('product_manager');
    $create = $this->create;
    $api = $this->api;
    $product = $create('products', $this->item);
    $other = $create('products', ['slug' => 'other'] + $this->item);
    $color = $create('attributes', ['code' => 'color', 'name' => 'Warna', 'active' => true]);
    $size = $create('attributes', ['code' => 'size', 'name' => 'Ukuran', 'active' => true]);
    $value = fn ($attr, $code) => $create('attribute-values', ['attribute_id' => $attr, 'code' => $code, 'label' => $code, 'position' => 0]);
    $red = $value($color, 'Red');
    $blue = $value($color, 'Blue');
    $small = $value($size, 'S');
    $input = ['product_id' => $product, 'attribute_value_ids' => [$red, $small]] + $this->variant;
    $id = $create('variants', $input);
    $api('GET', "variants?product_id=$product")->assertOk()->assertJsonPath('data.0.attribute_value_ids', [$red, $small]);
    $api('POST', 'variants', ['product_id' => $other] + $input)->assertConflict();
    $api('POST', 'variants', ['sku' => 'PIN-2', 'attribute_value_ids' => [$small, $red]] + $input)->assertConflict();
    $api('POST', 'variants', ['sku' => 'PIN-2', 'attribute_value_ids' => [$blue, $red]] + $input)->assertUnprocessable();
    $api('PATCH', "variants/$id", ['product_id' => $other])->assertUnprocessable();
    $api('PATCH', "variants/$id", ['weight_g' => 0])->assertUnprocessable();
    $api('PATCH', "variants/$id", ['rts_price_amount' => null])->assertUnprocessable();
    $api('PATCH', "variants/$id", ['rts_price_amount' => 27000])->assertOk();
    $audit = DB::table('audit_logs')->where(['subject_type' => 'variant', 'subject_id' => $id])->latest('id')->first();
    expect(json_decode($audit->before)->rts_price_amount)->toBe(25000)
        ->and(json_decode($audit->after)->rts_price_amount)->toBe(27000);
    $api('PATCH', "attribute-values/$red", ['active' => false])->assertConflict();
    $api('PATCH', "variants/$id", ['active' => false])->assertOk();
    $api('PATCH', "attribute-values/$red", ['active' => false])->assertOk();
    $api('PATCH', "variants/$id", ['active' => true])->assertUnprocessable();
    $api('PATCH', "variants/$id", ['active' => true, 'attribute_value_ids' => []])->assertOk();
    $api('POST', 'variants', ['sku' => 'PIN-2', 'attribute_value_ids' => []] + $input)->assertConflict();
    $api('POST', 'variants', ['sku' => 'PIN-2', 'attribute_value_ids' => [$small]] + $input)->assertCreated();
});

it('keeps staged images private, binds READY media to its actual owner, and reconciles exposure after publish and archive', function () {
    Storage::fake('private');
    $public = Storage::fake('catalog');
    $this->actor->assignRole('product_manager');
    $create = $this->create;
    $api = $this->api;
    $category = $create('categories', ['slug' => 'pins', 'name' => 'Pins', 'active' => true]);
    $product = $create('products', ['category_ids' => [$category]] + $this->item);
    $other = $create('products', ['slug' => 'other'] + $this->item);
    $variant = $create('variants', ['product_id' => $product] + $this->variant);
    $upload = fn ($type, $owner) => $this->post('/api/v1/admin/files', ['owner_type' => $type, 'owner_ref' => (string) $owner, 'collection' => 'catalog_media',
        'file' => UploadedFile::fake()->image('pin.png')], ['Accept' => 'application/json', 'Idempotency-Key' => $type.'image'])->assertAccepted()->json('data.id');
    $ref = $upload('variant', $variant);
    $productRef = $upload('product', $product);
    $media = ['product_id' => $product, 'variant_id' => $variant, 'file_id' => $ref, 'alt' => 'Pin merah', 'position' => 1];
    $api('POST', 'product-media', $media)->assertConflict()->assertJsonPath('code', 'FILE_NOT_READY');
    $scanner = new class implements MalwareScanner
    {
        public function clean(string $bytes): bool
        {
            return true;
        }
    };
    foreach ([$ref, $productRef] as $file) {
        $token = (string) Str::uuid();
        $target = DB::table('stored_files')->where('public_id', $file);
        $target->update(['status' => 'SCANNING', 'scan_token' => $token]);
        (new ScanStoredFile($target->value('id'), $token))->handle($scanner);
    }
    $api('POST', 'product-media', ['variant_id' => null] + $media)->assertNotFound();
    $api('POST', 'product-media', ['product_id' => $other] + $media)->assertUnprocessable();
    $id = $create('product-media', $media);
    $productMedia = $create('product-media', ['file_id' => $productRef, 'variant_id' => null, 'position' => 2] + $media);
    $runConsumers = function () {
        foreach (DB::table('event_consumptions')->where('status', 'PROCESSING')->orderByDesc('id')->pluck('id') as $receipt) {
            (new ConsumeEvent($receipt))->handle();
        }
    };
    $runConsumers();
    expect($public->allFiles())->toBeEmpty();
    $api('PATCH', "products/$product", ['status' => 'PUBLISHED'])->assertOk();
    $this->getJson('/api/v1/products/kopi-pin')->assertOk()->assertJsonPath('data.cover_url', null);
    $runConsumers();
    $key = "published/catalog/$product/$id";
    $public->assertExists($key);
    $api('PATCH', "variants/$variant", ['product_id' => (string) $product, 'weight_g' => 45])->assertOk();
    $runConsumers();
    $public->assertExists("published/catalog/$product/$productMedia");
    $this->getJson('/api/v1/products/kopi-pin')->assertOk()->assertJsonPath('data.cover_url', $public->url($key))
        ->assertJsonPath('data.media.0.sku', 'PIN-1')->assertJsonPath('data.media.0.alt', 'Pin merah')->assertJsonMissingPath('data.media.0.file_id');
    $api('PATCH', "products/$product", ['status' => 'ARCHIVED'])->assertOk();
    $this->getJson('/api/v1/products/kopi-pin')->assertNotFound();
    $runConsumers();
    $public->assertMissing($key);
});
