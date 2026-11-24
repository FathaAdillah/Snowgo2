<?php

namespace App\Modules\Inventory\Http;

use App\Modules\Inventory\InventoryData as Data;
use App\Support\Http\ApiInput;
use App\Support\Http\ApiResponse;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class ReservationController
{
    #[Response(200, 'RTS stock reservations', type: 'array{success:bool,message:string,data:list<'.Data::RESERVATION.'>,meta:'.Data::META.'}')]
    public function index(Request $request): JsonResponse
    {
        $input = $request->validate([...ApiInput::PAGE, 'order' => 'sometimes|required|string|max:40',
            'status' => 'sometimes|required|string|in:ACTIVE,COMMITTED,RELEASED,EXPIRED,CONSUMED', 'expires_before' => 'sometimes|required|date']);
        ApiInput::fields($request, $input);
        $rows = Data::reservations();
        foreach (['status' => 'r.status', 'order' => 'line.number'] as $key => $column) {
            if (isset($input[$key])) {
                $rows->where($column, $input[$key]);
            }
        }
        if ($date = $request->date('expires_before')) {
            $rows->where('r.expires_at', '<', $date->toISOString());
        }

        return Data::page($rows, 'r.id', $request);
    }

    #[Response(200, 'RTS stock reservation', type: 'array{success:bool,message:string,data:'.Data::RESERVATION.',meta:array{request_id:string}}')]
    #[Response(404, 'Not found', type: ApiResponse::ERROR_SCHEMA)]
    public function show(Request $request, int $id): JsonResponse
    {
        ApiInput::fields($request, []);

        return Data::detail(Data::reservations(), 'r.id', $id);
    }
}
