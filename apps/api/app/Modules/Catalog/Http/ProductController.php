<?php

namespace App\Modules\Catalog\Http;

use App\Modules\Catalog\CatalogAdmin;
use App\Modules\Catalog\Resource;
use App\Support\Http\ApiResponse;
use Dedoc\Scramble\Attributes\HeaderParameter;
use Dedoc\Scramble\Attributes\IgnoreResponse;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class ProductController
{
    private const ROW = 'array{id:int,name:string,slug:string,description:string,status:string,brand_id:int|null,tax_class_id:int|null,authenticity:string,seo_title:string|null,seo_description:string|null,category_ids:list<int>,collection_ids:list<int>,tag_ids:list<int>}';

    private const ONE = 'array{success:bool,message:string,data:'.self::ROW.',meta:array{request_id:string}}';

    #[Response(200, 'Catalog page', type: 'array{success:bool,message:string,data:list<'.self::ROW.'>,meta:array{request_id:string,page:int,per_page:int,total:int,last_page:int}}')]
    public function index(Request $request, CatalogAdmin $catalog): JsonResponse
    {
        return $catalog->page($request, Resource::Product, $request->validate(CatalogAdmin::FILTERS));
    }

    #[Response(200, 'Catalog record', type: self::ONE)]
    #[Response(404, 'Not found', type: ApiResponse::ERROR_SCHEMA)]
    public function show(CatalogAdmin $catalog, int $id): JsonResponse
    {
        return $catalog->show(Resource::Product, $id);
    }

    #[HeaderParameter('Idempotency-Key', required: true, type: 'string')]
    #[HeaderParameter('X-XSRF-TOKEN', required: true, type: 'string')]
    #[Response(403, 'Permission denied', type: ApiResponse::ERROR_SCHEMA)]
    #[Response(404, 'Not found', type: ApiResponse::ERROR_SCHEMA)]
    #[Response(409, 'Conflict or invalid transition', type: ApiResponse::ERROR_SCHEMA)]
    #[Response(419, 'CSRF token mismatch', type: ApiResponse::ERROR_SCHEMA)]
    #[IgnoreResponse(200)]
    #[Response(201, 'Catalog record saved', type: self::ONE)]
    public function create(Request $request, CatalogAdmin $catalog): JsonResponse
    {
        return $catalog->save($request, Resource::Product, $request->validate([
            'name' => 'required|string|max:200',
            'slug' => 'required|string|max:200|'.CatalogAdmin::SLUG,
            'description' => 'required|string|max:10000',
            'status' => 'required|string|in:DRAFT,PUBLISHED,ARCHIVED',
            'brand_id' => 'sometimes|nullable|'.CatalogAdmin::ID,
            'tax_class_id' => 'sometimes|nullable|'.CatalogAdmin::ID,
            'authenticity' => 'required|string|in:OFFICIAL,UNOFFICIAL,UNKNOWN',
            'seo_title' => 'sometimes|nullable|string|max:160',
            'seo_description' => 'sometimes|nullable|string|max:320',
            'category_ids' => 'present|array|list|max:50',
            'category_ids.*' => 'required|distinct|'.CatalogAdmin::ID,
            'collection_ids' => 'sometimes|array|list|max:50',
            'collection_ids.*' => 'required|distinct|'.CatalogAdmin::ID,
            'tag_ids' => 'sometimes|array|list|max:50',
            'tag_ids.*' => 'required|distinct|'.CatalogAdmin::ID,
        ]));
    }

    #[HeaderParameter('Idempotency-Key', required: true, type: 'string')]
    #[HeaderParameter('X-XSRF-TOKEN', required: true, type: 'string')]
    #[Response(403, 'Permission denied', type: ApiResponse::ERROR_SCHEMA)]
    #[Response(404, 'Not found', type: ApiResponse::ERROR_SCHEMA)]
    #[Response(409, 'Conflict or invalid transition', type: ApiResponse::ERROR_SCHEMA)]
    #[Response(419, 'CSRF token mismatch', type: ApiResponse::ERROR_SCHEMA)]
    #[Response(200, 'Catalog record saved', type: self::ONE)]
    public function update(Request $request, CatalogAdmin $catalog, int $id): JsonResponse
    {
        return $catalog->save($request, Resource::Product, $request->validate([
            'name' => 'sometimes|required|string|max:200',
            'slug' => 'sometimes|required|string|max:200|'.CatalogAdmin::SLUG,
            'description' => 'sometimes|required|string|max:10000',
            'status' => 'sometimes|required|string|in:DRAFT,PUBLISHED,ARCHIVED',
            'brand_id' => 'sometimes|nullable|'.CatalogAdmin::ID,
            'tax_class_id' => 'sometimes|nullable|'.CatalogAdmin::ID,
            'authenticity' => 'sometimes|required|string|in:OFFICIAL,UNOFFICIAL,UNKNOWN',
            'seo_title' => 'sometimes|nullable|string|max:160',
            'seo_description' => 'sometimes|nullable|string|max:320',
            'category_ids' => 'sometimes|array|list|max:50',
            'category_ids.*' => 'required|distinct|'.CatalogAdmin::ID,
            'collection_ids' => 'sometimes|array|list|max:50',
            'collection_ids.*' => 'required|distinct|'.CatalogAdmin::ID,
            'tag_ids' => 'sometimes|array|list|max:50',
            'tag_ids.*' => 'required|distinct|'.CatalogAdmin::ID,
        ]), $id);
    }
}
