<?php

namespace App\Modules\Catalog;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

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

    /** @return array<string,mixed> */
    public static function card(object $product): array
    {
        return ['slug' => $product->slug, 'name' => $product->name, 'brand_slug' => null,
            'category_slugs' => [], 'cover_url' => null, 'offers' => []];
    }
}
