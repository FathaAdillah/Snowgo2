<?php

namespace App\Modules\Catalog\Http;

use App\Modules\Catalog\CatalogAdmin;
use App\Modules\Catalog\CatalogEditor;
use App\Modules\Catalog\ProductDirectory;
use App\Modules\Preorders\CampaignOffers;
use App\Support\Http\ApiInput;
use App\Support\Http\ApiResponse;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

final class PublicCatalog
{
    private const META = 'array{request_id:string,page:int,per_page:int,last_page:int,total:int}';

    private const CARD = 'slug:string,name:string,brand_slug:string|null,category_slugs:list<string>,cover_url:string|null,offers:list<array{method:string,from_amount:int,campaign_slug?:string,available:bool}>';

    private const DETAIL = 'array{'.self::CARD.',description:string,seo_title:string|null,seo_description:string|null,variants:list<array{sku:string,name:string,weight_g:int,options:list<array{code:string,label:string}>,offers:list<array{method:string,unit_amount:int,campaign_slug?:string,available:bool}>}>,media:list<array{url:string,alt:string,position:int,sku:string|null}>}';

    #[Response(200, 'Published catalog', type: 'array{success:bool,message:string,data:list<array{'.self::CARD.'}>,meta:'.self::META.'}')]
    public function products(Request $request): JsonResponse
    {
        $filters = $request->validate([...ApiInput::PAGE, 'q' => 'sometimes|required|string|max:120',
            'category' => 'sometimes|required|string|max:160|'.CatalogAdmin::SLUG, 'brand' => 'sometimes|required|string|max:160|'.CatalogAdmin::SLUG,
            'collection' => 'sometimes|required|string|max:160|'.CatalogAdmin::SLUG, 'tag' => 'sometimes|required|string|max:120|'.CatalogAdmin::SLUG,
            'method' => 'sometimes|required|string|in:RTS,PO', 'sort' => 'sometimes|required|string|in:-published_at,name']);
        ApiInput::fields($request, $filters);
        $q = ProductDirectory::published();
        if (isset($filters['q'])) {
            $q->whereRaw("search_vector @@ plainto_tsquery('simple', ?)", [$filters['q']]);
        }
        foreach (CatalogEditor::RELATIONS as [$table, $fk]) {
            $field = substr($fk, 0, -3);
            if (isset($filters[$field])) {
                $q->whereIn('products.id', DB::table("product_$table as j")->join("$table as t", 't.id', '=', "j.$fk")
                    ->where(['t.slug' => $filters[$field], 't.active' => true])->select('j.product_id'));
            }
        }
        if (isset($filters['brand'])) {
            $q->whereIn('products.brand_id', DB::table('brands')->where(['slug' => $filters['brand'], 'active' => true])->select('id'));
        }
        if (isset($filters['method'])) {
            $offers = $filters['method'] === 'RTS' ? ProductDirectory::offerableVariants()->where('rts_enabled', true) : CampaignOffers::open();
            $q->whereIn('products.id', $offers->select('product_variants.product_id'));
        }
        ($filters['sort'] ?? null) === 'name' ? $q->orderBy('name') : $q->orderByDesc('published_at');
        $page = $q->orderByDesc('id')->paginate((int) ($filters['per_page'] ?? 20), ['id', 'slug', 'name', 'brand_id']);

        return ApiResponse::success(ProductDirectory::cards($page->getCollection()), meta: self::meta($page));
    }

    #[Response(200, 'Published product', type: 'array{success:bool,message:string,data:'.self::DETAIL.',meta:array{request_id:string}}')]
    #[Response(404, 'Product not published', type: ApiResponse::ERROR_SCHEMA)]
    public function detail(Request $request, string $slug): JsonResponse
    {
        ApiInput::fields($request, []);

        return ApiResponse::success(ProductDirectory::detail($slug));
    }

    #[Response(200, 'Active categories', type: 'array{success:bool,message:string,data:list<array{slug:string,name:string,description:string|null,parent_slug:string|null}>,meta:'.self::META.'}')]
    public function categories(Request $request): JsonResponse
    {
        $filters = $request->validate([...ApiInput::PAGE, 'parent' => 'sometimes|required|string|max:160|'.CatalogAdmin::SLUG]);
        ApiInput::fields($request, $filters);
        $q = DB::table('categories as c')->leftJoin('categories as p', 'p.id', '=', 'c.parent_id')->where('c.active', true);
        if (isset($filters['parent'])) {
            $q->where('p.slug', $filters['parent']);
        }
        $page = $q->orderBy('c.name')->orderBy('c.id')->paginate((int) ($filters['per_page'] ?? 20), ['c.slug', 'c.name', 'c.description', 'p.slug as parent_slug']);

        return ApiResponse::success($page->items(), meta: self::meta($page));
    }

    /**
     * @param  LengthAwarePaginator<int,object>  $page
     * @return array<string,int>
     */
    private static function meta(LengthAwarePaginator $page): array
    {
        return ['page' => $page->currentPage(), 'per_page' => $page->perPage(), 'total' => $page->total(), 'last_page' => $page->lastPage()];
    }
}
