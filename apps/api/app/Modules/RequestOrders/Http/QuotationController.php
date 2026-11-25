<?php

namespace App\Modules\RequestOrders\Http;

use App\Modules\RequestOrders\QuotationData;
use App\Modules\RequestOrders\QuotationDrafts;
use App\Modules\RequestOrders\QuotationLifecycle;
use App\Support\Http\ApiResponse;
use Closure;
use Dedoc\Scramble\Attributes\HeaderParameter;
use Dedoc\Scramble\Attributes\IgnoreResponse;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class QuotationController
{
    public const DATE = ['string', 'date', 'max:40', 'regex:/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d(?:\.\d{1,6})?(?:Z|[+-]\d\d:\d\d)$/D'];

    public const ITEMS = 'array|list|min:1|max:200';

    public const MONEY = 'integer|min:0|max:1000000000000';

    public const LINES = ['items.*' => 'required|array:request_item_id,label,qty,unit_amount,options,weight_g,foreign_currency,foreign_amount,exchange_rate',
        'items.*.request_item_id' => 'required|string|ulid|distinct:ignore_case', 'items.*.label' => 'required|string|max:200',
        'items.*.qty' => 'required|integer|between:1,10000', 'items.*.unit_amount' => 'required|'.self::MONEY,
        'items.*.weight_g' => 'sometimes|required|integer|between:1,2147483647', 'items.*.options' => 'sometimes|required|string|max:1000',
        'items.*.foreign_currency' => 'sometimes|required|string|regex:/^[A-Z]{3}$/D',
        'items.*.foreign_amount' => 'sometimes|required|string|regex:/^\d{1,16}(?:\.\d{1,2})?$/D',
        'items.*.exchange_rate' => 'sometimes|required|string|regex:/^\d{1,12}(?:\.\d{1,6})?$/D',
        'deposit_amount' => 'sometimes|nullable|'.self::MONEY, 'shipping_estimate_amount' => 'sometimes|nullable|'.self::MONEY];

    #[HeaderParameter('Idempotency-Key', required: true, type: 'string')]
    #[HeaderParameter('X-XSRF-TOKEN', required: true, type: 'string')]
    #[IgnoreResponse(200)]
    #[Response(201, 'Draft quotation', type: QuotationData::ONE)]
    #[Response(404, 'Request not found', type: ApiResponse::ERROR_SCHEMA)]
    #[Response(409, 'Invalid state, expired or request conflict', type: ApiResponse::ERROR_SCHEMA)]
    #[Response(419, 'CSRF mismatch', type: ApiResponse::ERROR_SCHEMA)]
    #[Response(422, 'Invalid input or reused key', type: CaseMutation::INVALID)]
    public function create(Request $request, string $number): JsonResponse
    {
        $data = $request->validate(['valid_until' => ['required', ...self::DATE], 'term' => 'required|string|in:FULL,DEPOSIT', 'terms' => 'required|string|max:5000', 'items' => 'required|'.self::ITEMS, ...self::LINES]);

        return $this->staff($request, $number, 'create', $data, fn ($case, $actor) => ApiResponse::success(QuotationDrafts::save($case, null, $actor, $data), status: 201));
    }

    #[HeaderParameter('Idempotency-Key', required: true, type: 'string')]
    #[HeaderParameter('X-XSRF-TOKEN', required: true, type: 'string')]
    #[Response(200, 'Updated draft', type: QuotationData::ONE)]
    #[Response(404, 'Quotation not found', type: ApiResponse::ERROR_SCHEMA)]
    #[Response(409, 'Invalid state, expired or request conflict', type: ApiResponse::ERROR_SCHEMA)]
    #[Response(419, 'CSRF mismatch', type: ApiResponse::ERROR_SCHEMA)]
    #[Response(422, 'Invalid input or reused key', type: CaseMutation::INVALID)]
    public function update(Request $request, string $number, string $quotation_number): JsonResponse
    {
        $data = $request->validate(['revision' => 'required|integer|min:1', 'valid_until' => ['sometimes', 'required', ...self::DATE], 'term' => 'sometimes|required|string|in:FULL,DEPOSIT', 'terms' => 'sometimes|required|string|max:5000', 'items' => 'sometimes|required|'.self::ITEMS, ...self::LINES]);

        return $this->staff($request, $number, 'update', $data, fn ($case, $actor) => ApiResponse::success(QuotationDrafts::save($case, QuotationData::locked($case->id, $quotation_number, (int) $data['revision']), $actor, $data)));
    }

    #[HeaderParameter('Idempotency-Key', required: true, type: 'string')]
    #[HeaderParameter('X-XSRF-TOKEN', required: true, type: 'string')]
    #[Response(200, 'Frozen quotation sent', type: QuotationData::ONE)]
    #[Response(404, 'Quotation not found', type: ApiResponse::ERROR_SCHEMA)]
    #[Response(409, 'Invalid state, expired or request conflict', type: ApiResponse::ERROR_SCHEMA)]
    #[Response(419, 'CSRF mismatch', type: ApiResponse::ERROR_SCHEMA)]
    #[Response(422, 'Invalid input or reused key', type: CaseMutation::INVALID)]
    public function dispatch(Request $request, string $number, string $quotation_number): JsonResponse
    {
        $data = $request->validate(['revision' => 'required|integer|min:1']);

        return $this->staff($request, $number, 'dispatch', $data, fn ($case, $actor) => ApiResponse::success(QuotationLifecycle::dispatch($case, QuotationData::locked($case->id, $quotation_number, (int) $data['revision']), $actor)));
    }

    #[HeaderParameter('Idempotency-Key', required: true, type: 'string')]
    #[HeaderParameter('X-XSRF-TOKEN', required: true, type: 'string')]
    #[Response(200, 'Withdrawn quotation', type: QuotationData::ONE)]
    #[Response(404, 'Quotation not found', type: ApiResponse::ERROR_SCHEMA)]
    #[Response(409, 'Invalid state or request conflict', type: ApiResponse::ERROR_SCHEMA)]
    #[Response(419, 'CSRF mismatch', type: ApiResponse::ERROR_SCHEMA)]
    #[Response(422, 'Invalid input or reused key', type: CaseMutation::INVALID)]
    public function withdraw(Request $request, string $number, string $quotation_number): JsonResponse
    {
        $data = $request->validate(['reason_code' => 'required|'.CaseMutation::CODE]);

        return $this->staff($request, $number, 'withdraw', $data, fn ($case, $actor) => ApiResponse::success(QuotationLifecycle::withdraw($case, QuotationData::locked($case->id, $quotation_number), $actor, $data['reason_code'])));
    }

    #[HeaderParameter('Idempotency-Key', required: true, type: 'string')]
    #[HeaderParameter('X-XSRF-TOKEN', required: true, type: 'string')]
    #[Response(200, 'Quotation decision and conversion references', type: QuotationData::DECISION)]
    #[Response(403, 'Verified customer required', type: ApiResponse::ERROR_SCHEMA)]
    #[Response(404, 'Request, sent quotation or address not found', type: ApiResponse::ERROR_SCHEMA)]
    #[Response(409, 'Invalid state, expired or request conflict', type: ApiResponse::ERROR_SCHEMA)]
    #[Response(419, 'CSRF mismatch', type: ApiResponse::ERROR_SCHEMA)]
    #[Response(422, 'Invalid input or reused key', type: CaseMutation::INVALID)]
    #[Response(429, 'Decision rate limit', type: ApiResponse::ERROR_SCHEMA)]
    public function decide(Request $request, string $number, string $quotation_number): JsonResponse
    {
        $data = $request->validate(['decision' => 'required|string|in:ACCEPT,REJECT,REVISE', 'note' => 'sometimes|required|string|max:1000', 'address_id' => 'required_if:decision,ACCEPT|prohibited_unless:decision,ACCEPT|string|ulid']);

        return CaseMutation::run($request, $data, $number, null, fn ($case, $actor) => ApiResponse::success(QuotationLifecycle::decide($case, QuotationData::locked($case->id, $quotation_number), $actor, $data)));
    }

    /** @param array<string,mixed> $data
     * @param Closure(object,int):JsonResponse $write */
    private function staff(Request $request, string $number, string $action, array $data, Closure $write): JsonResponse
    {
        return CaseMutation::authorized($request, $data, $number, ['request_orders.request.view', 'request_orders.quotation.view', 'request_orders.quotation.manage', 'request_orders.quotation.'.$action], $write);
    }
}
