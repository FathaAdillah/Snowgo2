<?php

namespace App\Modules\Inventory\Http;

use App\Modules\Inventory\InventoryData as Data;
use App\Modules\Inventory\StockLedger;
use App\Support\Http\ApiInput;
use App\Support\Http\ApiResponse;
use Dedoc\Scramble\Attributes\HeaderParameter;
use Dedoc\Scramble\Attributes\IgnoreResponse;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

final class StockController
{
    private const LEVEL = 'array{success:bool,message:string,data:'.Data::LEVEL.',meta:array{request_id:string}}';

    private const ADJUSTMENT = 'array{success:bool,message:string,data:'.Data::ADJUSTMENT.',meta:array{request_id:string}}';

    #[Response(200, 'Stock levels', type: 'array{success:bool,message:string,data:list<'.Data::LEVEL.'>,meta:'.Data::META.'}')]
    public function levels(Request $request): JsonResponse
    {
        $data = $request->validate([...ApiInput::PAGE, 'warehouse' => 'sometimes|required|string|max:32|'.Data::CODE,
            'sku' => 'sometimes|required|string|max:80|'.Data::CODE, 'low_stock' => Data::BOOL_FILTER]);
        ApiInput::fields($request, $data);
        $q = Data::levels();
        if (isset($data['warehouse'])) {
            $q->where('w.code', $data['warehouse']);
        }
        if (isset($data['sku'])) {
            $q->where('v.sku', $data['sku']);
        }
        if (isset($data['low_stock'])) {
            $q->whereColumn('l.available', $request->boolean('low_stock') ? '<=' : '>', 'l.reorder_point');
        }

        return Data::page($q, 'l.id', $request);
    }

    #[Response(200, 'Stock level', type: self::LEVEL)]
    #[Response(404, 'Not found', type: ApiResponse::ERROR_SCHEMA)]
    public function level(Request $request, int $id): JsonResponse
    {
        ApiInput::fields($request, []);

        return Data::detail(Data::levels(), 'l.id', $id);
    }

    #[Response(200, 'Movement ledger', type: 'array{success:bool,message:string,data:list<'.Data::MOVEMENT.'>,meta:array{request_id:string,next_cursor:string|null}}')]
    public function movements(Request $request): JsonResponse
    {
        $data = $request->validate(['per_page' => ApiInput::PAGE['per_page'], 'cursor' => 'sometimes|required|string|max:128',
            'level_id' => 'sometimes|required|'.Data::ID, 'type' => 'sometimes|required|string|in:INITIAL,PURCHASE,ADJUSTMENT_IN,ADJUSTMENT_OUT,RESERVATION,RESERVATION_RELEASE,COMMIT,COMMIT_RELEASE,SALE', ...Data::DATES]);
        ApiInput::fields($request, $data);
        $q = Data::movements();
        foreach (['level_id', 'type'] as $field) {
            if (isset($data[$field])) {
                $q->where("m.$field", $data[$field]);
            }
        }
        Data::dates($q, 'm.occurred_at', $request);
        if (isset($data['cursor'])) {
            $cursor = base64_decode($data['cursor'], true);
            if (! is_string($cursor) || (int) $cursor <= 0 || (string) (int) $cursor !== $cursor) {
                throw ValidationException::withMessages(['cursor' => 'Invalid ledger cursor.']);
            }
            $q->where('m.id', '<', (int) $cursor);
        }
        $limit = (int) ($data['per_page'] ?? 20);
        $rows = $q->orderByDesc('m.id')->limit($limit + 1)->get();
        $page = $rows->take($limit);

        return ApiResponse::success($page->all(), meta: ['next_cursor' => $rows->count() > $limit ? base64_encode((string) $page->last()->id) : null])->header('Cache-Control', 'private, no-store');
    }

    #[Response(200, 'Stock movement', type: 'array{success:bool,message:string,data:'.Data::MOVEMENT.',meta:array{request_id:string}}')]
    #[Response(404, 'Not found', type: ApiResponse::ERROR_SCHEMA)]
    public function movement(Request $request, int $id): JsonResponse
    {
        ApiInput::fields($request, []);

        return Data::detail(Data::movements(), 'm.id', $id);
    }

    #[Response(200, 'Applied adjustments', type: 'array{success:bool,message:string,data:list<'.Data::ADJUSTMENT.'>,meta:'.Data::META.'}')]
    public function adjustments(Request $request): JsonResponse
    {
        $data = $request->validate([...ApiInput::PAGE, 'status' => 'sometimes|required|string|in:APPLIED', ...Data::DATES]);
        ApiInput::fields($request, $data);
        $q = Data::adjustments();
        Data::dates($q, 'a.created_at', $request);

        return Data::page($q, 'a.id', $request);
    }

    #[Response(200, 'Applied adjustment', type: self::ADJUSTMENT)]
    #[Response(404, 'Not found', type: ApiResponse::ERROR_SCHEMA)]
    public function adjustment(Request $request, int $id): JsonResponse
    {
        ApiInput::fields($request, []);

        return Data::detail(Data::adjustments(), 'a.id', $id);
    }

    #[HeaderParameter('Idempotency-Key', required: true, type: 'string')]
    #[HeaderParameter('X-XSRF-TOKEN', required: true, type: 'string')]
    #[Response(200, 'Stock received', type: self::LEVEL)]
    #[Response(403, 'Permission denied', type: ApiResponse::ERROR_SCHEMA)]
    #[Response(404, 'Warehouse or SKU not active', type: ApiResponse::ERROR_SCHEMA)]
    #[Response(409, 'Duplicate operation or quantity conflict', type: ApiResponse::ERROR_SCHEMA)]
    #[Response(419, 'CSRF token mismatch', type: ApiResponse::ERROR_SCHEMA)]
    public function receive(Request $request, StockLedger $ledger): JsonResponse
    {
        $data = $request->validate(['warehouse_id' => 'required|'.Data::ID, 'sku' => 'required|string|max:80|'.Data::CODE,
            'qty' => 'required|integer|min:1|max:10000', 'type' => 'required|string|in:INITIAL,PURCHASE',
            'reason_code' => 'required|string|max:48|'.Data::CODE, 'note' => 'sometimes|nullable|string|max:1000']);

        return InventoryMutation::run($request, $data, 'inventory.receipt.create', fn (int $actor, string $key) => Data::detail(Data::levels(), 'l.id', $ledger->receive($data, $actor, $key)));
    }

    #[HeaderParameter('Idempotency-Key', required: true, type: 'string')]
    #[HeaderParameter('X-XSRF-TOKEN', required: true, type: 'string')]
    #[IgnoreResponse(200)]
    #[Response(201, 'Adjustment created and applied', type: self::ADJUSTMENT)]
    #[Response(403, 'Permission denied', type: ApiResponse::ERROR_SCHEMA)]
    #[Response(404, 'Active stock level not found', type: ApiResponse::ERROR_SCHEMA)]
    #[Response(409, 'Duplicate operation or quantity conflict', type: ApiResponse::ERROR_SCHEMA)]
    #[Response(419, 'CSRF token mismatch', type: ApiResponse::ERROR_SCHEMA)]
    public function adjust(Request $request, StockLedger $ledger): JsonResponse
    {
        $data = $request->validate(['level_id' => 'required|'.Data::ID, 'delta' => 'required|integer|not_in:0|min:-2147483647|max:2147483647',
            'reason_code' => 'required|string|max:48|'.Data::CODE, 'note' => 'sometimes|nullable|string|max:1000']);

        return InventoryMutation::run($request, $data, 'inventory.adjustment.create', fn (int $actor, string $key) => Data::detail(Data::adjustments(), 'a.id', $ledger->adjust($data, $actor, $key), 201));
    }
}
