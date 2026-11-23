<?php

namespace App\Modules\Inventory;

use App\Support\Http\ApiResponse;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class InventoryData
{
    public const ID = 'integer|min:1|max:2147483647';

    public const CODE = 'regex:/\A[A-Za-z0-9_.-]+\z/D';

    public const BOOL_FILTER = 'sometimes|required|string|in:0,1,true,false';

    public const DATES = ['from' => 'sometimes|required|date', 'to' => 'sometimes|required|date'];

    public const META = 'array{request_id:string,page:int,per_page:int,total:int,last_page:int}';

    public const WAREHOUSE = 'array{id:int,code:string,name:string,district_code:string,address:string,is_default:bool,active:bool}';

    public const LEVEL = 'array{id:int,warehouse_id:int,sku:string,on_hand:int,reserved:int,committed:int,available:int,reorder_point:int}';

    public const MOVEMENT = 'array{id:int,level_id:int,type:string,source_key:string,on_hand_delta:int,reserved_delta:int,committed_delta:int,reason_code:string,occurred_at:string}';

    public const ADJUSTMENT = 'array{id:int,level_id:int,delta:int,reason_code:string,created_at:string}';

    public static function warehouses(): Builder
    {
        return DB::table('warehouses as w')->join('regions as d', 'd.id', '=', 'w.district_id')
            ->select('w.id', 'w.code', 'w.name', 'd.code as district_code', 'w.address', 'w.is_default', 'w.active');
    }

    public static function levels(): Builder
    {
        return DB::table('inventory_levels as l')->join('product_variants as v', 'v.id', '=', 'l.variant_id')
            ->join('warehouses as w', 'w.id', '=', 'l.warehouse_id')
            ->select('l.id', 'l.warehouse_id', 'v.sku', 'l.on_hand', 'l.reserved', 'l.committed', 'l.available', 'l.reorder_point');
    }

    public static function movements(): Builder
    {
        return DB::table('inventory_movements as m')
            ->select('m.id', 'm.level_id', 'm.type', 'm.source_key', 'm.on_hand_delta', 'm.reserved_delta', 'm.committed_delta', 'm.reason_code', 'm.occurred_at');
    }

    public static function adjustments(): Builder
    {
        return DB::table('stock_adjustments as a')->join('inventory_movements as m', 'm.id', '=', 'a.movement_id')
            ->select('a.id', 'a.level_id', 'a.delta', 'm.reason_code', 'a.created_at');
    }

    public static function detail(Builder $query, string $idColumn, int $id, int $status = 200): JsonResponse
    {
        return ApiResponse::success($query->where($idColumn, $id)->first() ?? abort(404), status: $status)->header('Cache-Control', 'private, no-store');
    }

    public static function page(Builder $query, string $idColumn, Request $request): JsonResponse
    {
        $page = $query->orderByDesc($idColumn)->paginate($request->integer('per_page', 20));

        return ApiResponse::success($page->items(), meta: ['page' => $page->currentPage(), 'per_page' => $page->perPage(),
            'total' => $page->total(), 'last_page' => $page->lastPage()])->header('Cache-Control', 'private, no-store');
    }

    public static function dates(Builder $query, string $column, Request $request): void
    {
        $from = $request->date('from');
        $to = $request->date('to');
        if ($to && strlen($request->input('to')) === 10) {
            $to->endOfDay();
        }
        if ($from && $to && $from->gt($to)) {
            throw ValidationException::withMessages(['to' => 'Must not precede from.']);
        }
        $query->when($from, fn ($q) => $q->where($column, '>=', $from?->toISOString()))
            ->when($to, fn ($q) => $q->where($column, '<=', $to?->toISOString()));
    }
}
