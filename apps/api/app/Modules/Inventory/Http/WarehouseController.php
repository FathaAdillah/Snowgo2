<?php

namespace App\Modules\Inventory\Http;

use App\Modules\Inventory\InventoryData as Data;
use App\Modules\Inventory\WarehouseEditor;
use App\Support\Http\ApiInput;
use App\Support\Http\ApiResponse;
use Dedoc\Scramble\Attributes\HeaderParameter;
use Dedoc\Scramble\Attributes\IgnoreResponse;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class WarehouseController
{
    private const ONE = 'array{success:bool,message:string,data:'.Data::WAREHOUSE.',meta:array{request_id:string}}';

    #[Response(200, 'Warehouses', type: 'array{success:bool,message:string,data:list<'.Data::WAREHOUSE.'>,meta:'.Data::META.'}')]
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([...ApiInput::PAGE, 'q' => 'sometimes|required|string|max:120', 'active' => Data::BOOL_FILTER]);
        ApiInput::fields($request, $data);
        $query = Data::warehouses();
        if (isset($data['q'])) {
            $term = '%'.addcslashes($data['q'], '\\%_').'%';
            $query->where(fn ($q) => $q->whereLike('w.code', $term)->orWhereLike('w.name', $term));
        }
        if (isset($data['active'])) {
            $query->where('w.active', $request->boolean('active'));
        }

        return Data::page($query, 'w.id', $request);
    }

    #[Response(200, 'Warehouse', type: self::ONE)]
    #[Response(404, 'Not found', type: ApiResponse::ERROR_SCHEMA)]
    public function show(Request $request, int $id): JsonResponse
    {
        ApiInput::fields($request, []);

        return Data::detail(Data::warehouses(), 'w.id', $id);
    }

    #[HeaderParameter('Idempotency-Key', required: true, type: 'string')]
    #[HeaderParameter('X-XSRF-TOKEN', required: true, type: 'string')]
    #[IgnoreResponse(200)]
    #[Response(201, 'Warehouse created', type: self::ONE)]
    #[Response(403, 'Permission denied', type: ApiResponse::ERROR_SCHEMA)]
    #[Response(409, 'Duplicate or invalid transition', type: ApiResponse::ERROR_SCHEMA)]
    #[Response(419, 'CSRF token mismatch', type: ApiResponse::ERROR_SCHEMA)]
    public function create(Request $request, WarehouseEditor $editor): JsonResponse
    {
        $data = $request->validate(['code' => 'required|string|max:32|'.Data::CODE, 'name' => 'required|string|max:120',
            'district_code' => 'required|string|max:48|'.Data::CODE, 'address' => 'required|string|max:255',
            'is_default' => 'required|boolean', 'active' => 'required|boolean']);

        return InventoryMutation::run($request, $data, 'inventory.warehouse.create',
            fn (int $actor) => Data::detail(Data::warehouses(), 'w.id', $editor->save($data, $actor, null), 201));
    }

    #[HeaderParameter('Idempotency-Key', required: true, type: 'string')]
    #[HeaderParameter('X-XSRF-TOKEN', required: true, type: 'string')]
    #[Response(200, 'Warehouse updated', type: self::ONE)]
    #[Response(403, 'Permission denied', type: ApiResponse::ERROR_SCHEMA)]
    #[Response(404, 'Not found', type: ApiResponse::ERROR_SCHEMA)]
    #[Response(409, 'Duplicate or invalid transition', type: ApiResponse::ERROR_SCHEMA)]
    #[Response(419, 'CSRF token mismatch', type: ApiResponse::ERROR_SCHEMA)]
    public function update(Request $request, WarehouseEditor $editor, int $id): JsonResponse
    {
        $data = $request->validate(['code' => 'sometimes|required|string|max:32|'.Data::CODE, 'name' => 'sometimes|required|string|max:120',
            'district_code' => 'sometimes|required|string|max:48|'.Data::CODE, 'address' => 'sometimes|required|string|max:255',
            'is_default' => 'sometimes|required|boolean', 'active' => 'sometimes|required|boolean']);

        return InventoryMutation::run($request, $data, 'inventory.warehouse.update',
            fn (int $actor) => Data::detail(Data::warehouses(), 'w.id', $editor->save($data, $actor, $id)));
    }
}
