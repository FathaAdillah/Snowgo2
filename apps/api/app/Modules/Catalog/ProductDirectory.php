<?php

namespace App\Modules\Catalog;

use App\Modules\Inventory\StockAvailability;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

final class ProductDirectory
{
    public static function published(): Builder
    {
        return DB::table('products')->where('products.status', 'PUBLISHED')->whereNull('products.deleted_at');
    }

    public static function identity(string $slug): Builder
    {
        return DB::table('products')->select('id')->where('slug', $slug);
    }

    private static function media(): Builder
    {
        return DB::table('product_media as m')->join('stored_files as f', 'f.id', '=', 'm.file_id')
            ->leftJoin('product_variants as v', 'v.id', '=', 'm.variant_id')
            ->where('m.active', true)->where('f.status', 'READY')->whereNotNull('m.published_key')
            ->where(fn ($q) => $q->whereNull('m.variant_id')->orWhere('v.active', true))->whereNull('v.deleted_at')
            ->orderBy('m.product_id')->orderBy('m.position')->orderBy('m.id');
    }

    public static function variants(): Builder
    {
        return DB::table('product_variants')->where('active', true)->whereNull('deleted_at');
    }

    public static function activeTaxClasses(): Builder
    {
        return DB::table('tax_classes')->where('active', true);
    }

    public static function offerableVariants(): Builder
    {
        $products = self::published()->where(fn ($q) => $q->whereNull('tax_class_id')
            ->orWhereIn('tax_class_id', self::activeTaxClasses()->select('id')));

        return self::variants()->whereIn('product_id', $products->select('products.id'));
    }

    /**
     * @param  Collection<int,\stdClass>  $products
     * @return list<array<string,mixed>>
     */
    public static function cards(Collection $products): array
    {
        if ($products->isEmpty()) {
            return [];
        }
        $ids = $products->pluck('id')->all();
        $categories = DB::table('product_categories as pc')->join('categories as c', 'c.id', '=', 'pc.category_id')
            ->where('c.active', true)->whereIntegerInRaw('pc.product_id', $ids)->orderBy('c.slug')
            ->get(['pc.product_id', 'c.slug'])->groupBy('product_id');
        $brands = DB::table('brands')->where('active', true)->whereIntegerInRaw('id', $products->pluck('brand_id')->filter()->all())->pluck('slug', 'id');
        $covers = self::media()->whereIntegerInRaw('m.product_id', $ids)->selectRaw('DISTINCT ON (m.product_id) m.product_id,m.published_key')
            ->get()->keyBy('product_id');
        $prices = self::offerableVariants()->whereIntegerInRaw('product_id', $ids)->where('rts_enabled', true)
            ->leftJoinSub(StockAvailability::readyToShip(), 'stock', 'stock.variant_id', '=', 'product_variants.id')
            ->selectRaw('product_id, COALESCE(min(rts_price_amount) FILTER (WHERE stock.variant_id IS NOT NULL),min(rts_price_amount)) AS price,
                bool_or(stock.variant_id IS NOT NULL) AS available')->groupBy('product_id')->get()->keyBy('product_id');

        $cards = [];
        foreach ($products as $p) {
            $cards[] = ['slug' => $p->slug, 'name' => $p->name, 'brand_slug' => $brands[$p->brand_id] ?? null,
                'category_slugs' => isset($categories[$p->id]) ? $categories[$p->id]->pluck('slug')->all() : [],
                'cover_url' => isset($covers[$p->id]) ? Storage::disk('catalog')->url($covers[$p->id]->published_key) : null,
                'offers' => isset($prices[$p->id]) ? [['method' => 'RTS', 'from_amount' => $prices[$p->id]->price, 'available' => $prices[$p->id]->available]] : [],
            ];
        }

        return $cards;
    }

    /** @return array<string,mixed> */
    public static function detail(string $slug): array
    {
        $p = self::published()->where('slug', $slug)->first(['id', 'name', 'slug', 'brand_id', 'description', 'seo_title', 'seo_description']) ?? abort(404);
        $variants = self::variants()->where('product_id', $p->id)->orderBy('id')
            ->leftJoinSub(StockAvailability::readyToShip(), 'stock', 'stock.variant_id', '=', 'product_variants.id')
            ->select('product_variants.*')->selectRaw('stock.variant_id IS NOT NULL AS available')->get();
        $options = DB::table('variant_attribute_values as o')->join('attribute_values as av', 'av.id', '=', 'o.value_id')
            ->whereIntegerInRaw('o.variant_id', $variants->pluck('id')->all())->orderBy('o.attribute_id')->get(['o.variant_id', 'av.code', 'av.label'])->groupBy('variant_id');
        $media = self::media()->where('m.product_id', $p->id)->get(['m.published_key', 'm.alt', 'm.position', 'v.sku']);

        $card = self::cards(collect([$p]))[0];

        return $card + ['description' => $p->description, 'seo_title' => $p->seo_title, 'seo_description' => $p->seo_description,
            'media' => $media->map(fn ($m) => ['url' => Storage::disk('catalog')->url($m->published_key), 'alt' => $m->alt, 'position' => $m->position, 'sku' => $m->sku])->all(),
            'variants' => $variants->map(fn ($v) => ['sku' => $v->sku, 'name' => $v->name, 'weight_g' => $v->weight_g,
                'options' => ($options[$v->id] ?? collect())->map(fn ($o) => ['code' => $o->code, 'label' => $o->label])->all(),
                'offers' => $v->rts_enabled && $card['offers'] ? [['method' => 'RTS', 'unit_amount' => $v->rts_price_amount, 'available' => $v->available]] : [],
            ])->all()];
    }
}
