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

final class AttributeValueController
{
    private const ROW = 'array{id:int,attribute_id:int,code:string,label:string,position:int,active:bool}';

    private const ONE = 'array{success:bool,message:string,data:'.self::ROW.',meta:array{request_id:string}}';

    #[Response(200, 'Catalog page', type: 'array{success:bool,message:string,data:list<'.self::ROW.'>,meta:array{request_id:string,page:int,per_page:int,total:int,last_page:int}}')]
    public function index(Request $request, CatalogAdmin $catalog): JsonResponse
    {
        return $catalog->page($request, Resource::AttributeValue, $request->validate([...CatalogAdmin::FILTERS, 'attribute_id' => 'sometimes|required|'.CatalogAdmin::ID]));
    }

    #[Response(200, 'Catalog record', type: self::ONE)]
    #[Response(404, 'Not found', type: ApiResponse::ERROR_SCHEMA)]
    public function show(CatalogAdmin $catalog, int $id): JsonResponse
    {
        return $catalog->show(Resource::AttributeValue, $id);
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
        return $catalog->save($request, Resource::AttributeValue, $request->validate([
            'attribute_id' => 'required|'.CatalogAdmin::ID,
            'code' => 'required|string|max:64|'.CatalogAdmin::CODE,
            'label' => 'required|string|max:100',
            'position' => 'required|integer|min:0|max:2147483647',
            'active' => 'sometimes|required|boolean',
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
        return $catalog->save($request, Resource::AttributeValue, $request->validate([
            'attribute_id' => 'sometimes|required|'.CatalogAdmin::ID,
            'code' => 'sometimes|required|string|max:64|'.CatalogAdmin::CODE,
            'label' => 'sometimes|required|string|max:100',
            'position' => 'sometimes|required|integer|min:0|max:2147483647',
            'active' => 'sometimes|required|boolean',
        ]), $id);
    }
}
