<?php

namespace App\Modules\Customers\Http;

use App\Modules\Catalog\ProductDirectory;
use App\Support\Http\ApiInput;
use App\Support\Http\ApiResponse;
use Dedoc\Scramble\Attributes\HeaderParameter;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class WishlistController
{
    private const PRODUCT = 'array{slug:string,name:string,brand_slug:string|null,category_slugs:list<string>,cover_url:string|null,offers:list<array{method:string,from_amount:int,campaign_slug?:string,available:bool}>}';

    #[Response(200, 'Published favorites', type: 'array{success:bool,message:string,data:list<'.self::PRODUCT.'>,meta:'.CustomerResponse::META.'}')]
    #[Response(403, 'Verified customer session required', type: ApiResponse::ERROR_SCHEMA)]
    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate(ApiInput::PAGE);
        ApiInput::fields($request, $filters);
        $page = ProductDirectory::published()->join('wishlists as w', 'w.product_id', '=', 'products.id')
            ->where('w.user_id', $request->user()->id)->orderByDesc('w.id')
            ->paginate((int) ($filters['per_page'] ?? 20), ['products.id', 'products.slug', 'products.name', 'products.brand_id']);

        return CustomerResponse::ok(ProductDirectory::cards($page->getCollection()), meta: ['page' => $page->currentPage(), 'per_page' => $page->perPage(),
            'total' => $page->total(), 'last_page' => $page->lastPage()]);
    }

    #[HeaderParameter('X-XSRF-TOKEN', required: true, type: 'string')]
    #[Response(200, 'Favorite saved', type: ApiResponse::ACK_SCHEMA)]
    #[Response(403, 'Verified customer session required', type: ApiResponse::ERROR_SCHEMA)]
    #[Response(419, 'CSRF token mismatch', type: ApiResponse::ERROR_SCHEMA)]
    #[Response(404, 'No published product', type: ApiResponse::ERROR_SCHEMA)]
    public function store(Request $request, string $slug): JsonResponse
    {
        ApiInput::fields($request, []);
        DB::transaction(function () use ($request, $slug) {
            $id = ProductDirectory::published()->where('slug', $slug)->sharedLock()->value('id') ?? abort(404);
            DB::table('wishlists')->insertOrIgnore(['user_id' => $request->user()->id, 'product_id' => $id]);
        });

        return CustomerResponse::ok();
    }

    #[HeaderParameter('X-XSRF-TOKEN', required: true, type: 'string')]
    #[Response(200, 'Favorite removed', type: ApiResponse::ACK_SCHEMA)]
    #[Response(403, 'Verified customer session required', type: ApiResponse::ERROR_SCHEMA)]
    #[Response(419, 'CSRF token mismatch', type: ApiResponse::ERROR_SCHEMA)]
    public function destroy(Request $request, string $slug): JsonResponse
    {
        ApiInput::fields($request, []);
        DB::table('wishlists')->where('user_id', $request->user()->id)->whereIn('product_id', ProductDirectory::identity($slug))->delete();

        return CustomerResponse::ok();
    }
}
