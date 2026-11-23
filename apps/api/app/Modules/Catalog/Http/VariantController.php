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

final class VariantController
{
    private const ROW = 'array{id:int,product_id:int,sku:string,name:string,unit_id:int,rts_enabled:bool,rts_price_amount:int|null,weight_g:int,length_mm:int|null,width_mm:int|null,height_mm:int|null,active:bool,attribute_value_ids:list<int>}';

    private const ONE = 'array{success:bool,message:string,data:'.self::ROW.',meta:array{request_id:string}}';

    #[Response(200, 'Catalog page', type: 'array{success:bool,message:string,data:list<'.self::ROW.'>,meta:array{request_id:string,page:int,per_page:int,total:int,last_page:int}}')]
    public function index(Request $request, CatalogAdmin $catalog): JsonResponse
    {
        return $catalog->page($request, Resource::Variant, $request->validate([...CatalogAdmin::FILTERS, 'product_id' => 'sometimes|required|'.CatalogAdmin::ID]));
    }

    #[Response(200, 'Catalog record', type: self::ONE)]
    #[Response(404, 'Not found', type: ApiResponse::ERROR_SCHEMA)]
    public function show(CatalogAdmin $catalog, int $id): JsonResponse
    {
        return $catalog->show(Resource::Variant, $id);
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
        return $catalog->save($request, Resource::Variant, $request->validate([
            'product_id' => 'required|'.CatalogAdmin::ID,
            'sku' => 'required|string|max:80|'.CatalogAdmin::CODE,
            'name' => 'required|string|max:160',
            'unit_id' => 'required|'.CatalogAdmin::ID,
            'rts_enabled' => 'required|boolean',
            'rts_price_amount' => 'sometimes|nullable|integer|min:0|max:1000000000000',
            'weight_g' => 'required|'.CatalogAdmin::ID,
            'length_mm' => 'sometimes|nullable|'.CatalogAdmin::ID,
            'width_mm' => 'sometimes|nullable|'.CatalogAdmin::ID,
            'height_mm' => 'sometimes|nullable|'.CatalogAdmin::ID,
            'active' => 'required|boolean',
            'attribute_value_ids' => 'present|array|list|max:50',
            'attribute_value_ids.*' => 'required|distinct|'.CatalogAdmin::ID,
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
        return $catalog->save($request, Resource::Variant, $request->validate([
            'product_id' => 'sometimes|required|'.CatalogAdmin::ID,
            'sku' => 'sometimes|required|string|max:80|'.CatalogAdmin::CODE,
            'name' => 'sometimes|required|string|max:160',
            'unit_id' => 'sometimes|required|'.CatalogAdmin::ID,
            'rts_enabled' => 'sometimes|required|boolean',
            'rts_price_amount' => 'sometimes|nullable|integer|min:0|max:1000000000000',
            'weight_g' => 'sometimes|required|'.CatalogAdmin::ID,
            'length_mm' => 'sometimes|nullable|'.CatalogAdmin::ID,
            'width_mm' => 'sometimes|nullable|'.CatalogAdmin::ID,
            'height_mm' => 'sometimes|nullable|'.CatalogAdmin::ID,
            'active' => 'sometimes|required|boolean',
            'attribute_value_ids' => 'sometimes|array|list|max:50',
            'attribute_value_ids.*' => 'required|distinct|'.CatalogAdmin::ID,
        ]), $id);
    }
}
