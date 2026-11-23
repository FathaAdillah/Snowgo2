<?php

namespace App\Modules\Geography\Http\Controllers;

use App\Modules\Geography\Master;
use App\Modules\Geography\References;
use App\Support\Http\ApiResponse;
use Dedoc\Scramble\Attributes\HeaderParameter;
use Dedoc\Scramble\Attributes\IgnoreResponse;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class ReasonController
{
    private const ROW = 'array{id:int,domain:string,code:string,label:string,active:bool}';

    private const ONE = 'array{success:bool,message:string,data:'.self::ROW.',meta:array{request_id:string}}';

    #[Response(200, 'Paginated reference data', type: 'array{success:bool,message:string,data:list<'.self::ROW.'>,meta:array{request_id:string,page:int,last_page:int,per_page:int,total:int}}')]
    public function index(Request $request, References $refs): JsonResponse
    {
        return $refs->page($request, Master::Reason, $request->validate(References::ADMIN_FILTERS));
    }

    #[Response(200, 'Reference detail', type: self::ONE)]
    #[Response(404, 'Reference not found', type: ApiResponse::ERROR_SCHEMA)]
    public function show(References $refs, int $id): JsonResponse
    {
        return $refs->show(Master::Reason, $id);
    }

    #[Response(419, 'CSRF token mismatch', type: ApiResponse::ERROR_SCHEMA)]
    #[HeaderParameter('Idempotency-Key', required: true, type: 'string')]
    #[HeaderParameter('X-XSRF-TOKEN', required: true, type: 'string')]
    #[IgnoreResponse(200)]
    #[Response(201, 'Reference saved', type: self::ONE)]
    #[Response(409, 'Duplicate code or request in progress', type: ApiResponse::ERROR_SCHEMA)]
    public function create(Request $request, References $refs): JsonResponse
    {
        return $refs->save($request, Master::Reason, $request->validate([
            'domain' => 'required|string|max:40|'.References::CODE,
            'code' => 'required|string|max:48|'.References::CODE,
            'label' => 'required|string|max:120',
            'active' => 'required|boolean',
        ]));
    }

    #[Response(419, 'CSRF token mismatch', type: ApiResponse::ERROR_SCHEMA)]
    #[HeaderParameter('Idempotency-Key', required: true, type: 'string')]
    #[HeaderParameter('X-XSRF-TOKEN', required: true, type: 'string')]
    #[Response(404, 'Reference not found', type: ApiResponse::ERROR_SCHEMA)]
    #[Response(200, 'Reference saved', type: self::ONE)]
    #[Response(409, 'Duplicate code or request in progress', type: ApiResponse::ERROR_SCHEMA)]
    public function update(Request $request, References $refs, int $id): JsonResponse
    {
        return $refs->save($request, Master::Reason, $request->validate([
            'domain' => 'sometimes|required|string|max:40|'.References::CODE,
            'code' => 'sometimes|required|string|max:48|'.References::CODE,
            'label' => 'sometimes|required|string|max:120',
            'active' => 'sometimes|required|boolean',
        ]), $id);
    }
}
