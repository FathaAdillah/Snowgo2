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

final class AttributeController
{
    private const ROW = 'array{id:int,name:string,code:string,active:bool}';

    private const ONE = 'array{success:bool,message:string,data:'.self::ROW.',meta:array{request_id:string}}';

    #[Response(200, 'Catalog page', type: 'array{success:bool,message:string,data:list<'.self::ROW.'>,meta:array{request_id:string,page:int,per_page:int,total:int,last_page:int}}')]
    public function index(Request $request, CatalogAdmin $catalog): JsonResponse
    {
        return $catalog->page($request, Resource::Attribute, $request->validate(CatalogAdmin::FILTERS));
    }

    #[Response(200, 'Catalog record', type: self::ONE)]
    #[Response(404, 'Not found', type: ApiResponse::ERROR_SCHEMA)]
    public function show(CatalogAdmin $catalog, int $id): JsonResponse
    {
        return $catalog->show(Resource::Attribute, $id);
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
        return $catalog->save($request, Resource::Attribute, $request->validate([
            'name' => 'required|string|max:80',
            'code' => 'required|string|max:48|'.CatalogAdmin::CODE,
            'active' => 'required|boolean',
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
        return $catalog->save($request, Resource::Attribute, $request->validate([
            'name' => 'sometimes|required|string|max:80',
            'code' => 'sometimes|required|string|max:48|'.CatalogAdmin::CODE,
            'active' => 'sometimes|required|boolean',
        ]), $id);
    }
}
