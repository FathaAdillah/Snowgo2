<?php

namespace App\Modules\Orders\Http;

use App\Modules\Foundation\Audit\Journal;
use App\Modules\Foundation\Audit\Subject;
use App\Modules\Orders\OrderDirectory;
use App\Support\Http\ApiInput;
use App\Support\Http\ApiResponse;
use Dedoc\Scramble\Attributes\HeaderParameter;
use Dedoc\Scramble\Attributes\PathParameter;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class StaffOrderController
{
    #[Response(200, 'Order details without addresses', type: OrderResponse::STAFF_PAGE)]
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([...OrderController::FILTERS, 'from' => 'sometimes|required|date_format:Y-m-d', 'to' => 'sometimes|required|date_format:Y-m-d']);
        ApiInput::fields($request, $data);
        if (isset($data['from'], $data['to']) && $data['from'] > $data['to']) {
            throw ValidationException::withMessages(['to' => 'Must not precede from.']);
        }
        $rows = OrderDirectory::details(null, false);
        if ($from = $request->date('from')) {
            $rows->where('o.created_at', '>=', $from->startOfDay()->toIso8601String());
        }
        if ($to = $request->date('to')) {
            $rows->where('o.created_at', '<', $to->startOfDay()->addDay()->toIso8601String());
        }

        return OrderResponse::page($rows, $data);
    }

    #[Response(200, 'Order detail; address requires PII access', type: OrderResponse::FULL)]
    #[Response(404, 'Order not found', type: ApiResponse::ERROR_SCHEMA)]
    public function show(Request $request, string $number): JsonResponse
    {
        ApiInput::fields($request, []);
        $user = $request->user();
        if (! $user->can('customers.customer.pii_view')) {
            return OrderResponse::one($number, null, true);
        }

        return DB::transaction(function () use ($user, $number) {
            $id = OrderDirectory::scope(null)->where('number', $number)->value('id') ?? abort(404);
            app(Journal::class)->audit('OrderAddressAccessed', Subject::Order, $id, $user->id);

            return OrderResponse::one($number, null, true, true);
        });
    }

    #[PathParameter('action', type: "'CANCEL'|'COMPLETE'")]
    #[HeaderParameter('Idempotency-Key', required: true, type: 'string')]
    #[HeaderParameter('X-XSRF-TOKEN', required: true, type: 'string')]
    #[Response(200, 'Authorized order action; address omitted from replay', type: OrderResponse::FULL)]
    #[Response(404, 'Order or action not found', type: ApiResponse::ERROR_SCHEMA)]
    #[Response(409, 'INVALID_STATE_TRANSITION or REQUEST_IN_PROGRESS', type: ApiResponse::ERROR_SCHEMA)]
    #[Response(419, 'CSRF mismatch', type: ApiResponse::ERROR_SCHEMA)]
    #[Response(422, 'Invalid input or IDEMPOTENCY_KEY_REUSED', type: ApiResponse::ERROR_SCHEMA)]
    public function action(Request $request, string $number, string $action): JsonResponse
    {
        abort_unless($request->user()->can('orders.order.'.strtolower($action)), 403);

        return OrderMutation::run($request, $number, $action, $request->validate(['reason_code' => 'sometimes|required|'.OrderController::REASON, 'note' => 'sometimes|required|string|max:1000']), true);
    }
}
