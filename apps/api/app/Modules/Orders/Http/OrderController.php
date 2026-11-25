<?php

namespace App\Modules\Orders\Http;

use App\Modules\Orders\OrderDirectory;
use App\Support\Http\ApiInput;
use App\Support\Http\ApiResponse;
use Dedoc\Scramble\Attributes\HeaderParameter;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class OrderController
{
    public const FILTERS = [...ApiInput::PAGE, 'status' => 'sometimes|required|string|in:PENDING_PAYMENT,CONFIRMED,EXPIRED,CANCELLED,COMPLETED',
        'method' => 'sometimes|required|string|in:READY_TO_SHIP,PREORDER,REQUEST_ORDER'];

    public const REASON = 'string|max:48|regex:/\A[A-Za-z0-9_.-]+\z/D';

    #[Response(403, 'Verified customer required', type: ApiResponse::ERROR_SCHEMA)]
    #[Response(200, 'Own orders', type: OrderResponse::PAGE)]
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate(self::FILTERS);
        ApiInput::fields($request, $data);

        return OrderResponse::page(OrderDirectory::summaries($request->user()->id), $data);
    }

    #[Response(403, 'Verified customer required', type: ApiResponse::ERROR_SCHEMA)]
    #[Response(200, 'Own order snapshots and timeline', type: OrderResponse::FULL)]
    #[Response(404, 'Order not found', type: ApiResponse::ERROR_SCHEMA)]
    public function show(Request $request, string $number): JsonResponse
    {
        $data = $request->validate(['include' => 'sometimes|required|string|in:totals']);
        ApiInput::fields($request, $data);

        return OrderResponse::one($number, $request->user()->id, true, true, isset($data['include']));
    }

    #[HeaderParameter('Idempotency-Key', required: true, type: 'string')]
    #[HeaderParameter('X-XSRF-TOKEN', required: true, type: 'string')]
    #[Response(403, 'Verified customer required', type: ApiResponse::ERROR_SCHEMA)]
    #[Response(200, 'Unpaid order cancelled', type: OrderResponse::ONE)]
    #[Response(404, 'Order not found', type: ApiResponse::ERROR_SCHEMA)]
    #[Response(409, 'INVALID_STATE_TRANSITION or REQUEST_IN_PROGRESS', type: ApiResponse::ERROR_SCHEMA)]
    #[Response(419, 'CSRF mismatch', type: ApiResponse::ERROR_SCHEMA)]
    #[Response(422, 'Invalid input or IDEMPOTENCY_KEY_REUSED', type: ApiResponse::ERROR_SCHEMA)]
    public function cancel(Request $request, string $number): JsonResponse
    {
        return OrderMutation::run($request, $number, 'CANCEL', $request->validate(['reason_code' => 'required|'.self::REASON, 'note' => 'sometimes|required|string|max:1000']));
    }

    #[HeaderParameter('Idempotency-Key', required: true, type: 'string')]
    #[HeaderParameter('X-XSRF-TOKEN', required: true, type: 'string')]
    #[Response(403, 'Verified customer required', type: ApiResponse::ERROR_SCHEMA)]
    #[Response(200, 'Delivered order completed', type: OrderResponse::ONE)]
    #[Response(404, 'Order not found', type: ApiResponse::ERROR_SCHEMA)]
    #[Response(409, 'INVALID_STATE_TRANSITION or REQUEST_IN_PROGRESS', type: ApiResponse::ERROR_SCHEMA)]
    #[Response(419, 'CSRF mismatch', type: ApiResponse::ERROR_SCHEMA)]
    #[Response(422, 'Invalid input or IDEMPOTENCY_KEY_REUSED', type: ApiResponse::ERROR_SCHEMA)]
    public function complete(Request $request, string $number): JsonResponse
    {
        return OrderMutation::run($request, $number, 'COMPLETE', []);
    }
}
