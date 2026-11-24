<?php

namespace App\Modules\Checkout\Http;

use App\Modules\Checkout\CheckoutPricing;
use App\Modules\Checkout\ConfirmCheckout;
use App\Modules\Foundation\Idempotency\IdempotentMutation;
use App\Support\Http\ApiInput;
use App\Support\Http\ApiProblem;
use App\Support\Http\ApiResponse;
use Dedoc\Scramble\Attributes\HeaderParameter;
use Dedoc\Scramble\Attributes\IgnoreResponse;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class CheckoutController
{
    public const INPUT = ['cart_version' => 'required|integer|between:0,2147483647', 'address_id' => 'required|string|ulid',
        'shipping_quote_id' => 'sometimes|required|string|ulid'];

    private const PREVIEW = "array{success:bool,message:string,data:array{cart_version:int,quote_hash?:string,expires_at:string,shipping_quotes:list<array{id:string,service_code:string,carrier_label:string,amount:int,estimated_days_from:int,estimated_days_to:int,expires_at:string}>,groups:list<array{method:'READY_TO_SHIP'|'PREORDER',campaign_slug?:string,subtotal_amount:int,discount_amount:int,tax_amount:int,shipping_amount:int,total_amount:int,due_now_amount:int}>},meta:array{request_id:string}}";

    private const CHECKOUT = 'array{success:bool,message:string,data:array{id:string,order_numbers:list<string>,invoice_numbers:list<string>,completed_at:string},meta:array{request_id:string}}';

    #[HeaderParameter('X-XSRF-TOKEN', required: true, type: 'string')]
    #[Response(200, 'Select a shipping quote and preview again to obtain the final hash. Preorder-only carts need no selection.', type: self::PREVIEW)]
    #[Response(403, 'Verified customer required', type: ApiResponse::ERROR_SCHEMA)]
    #[Response(404, 'Address or shipping quote not found', type: ApiResponse::ERROR_SCHEMA)]
    #[Response(409, 'VERSION_CONFLICT, OFFER_UNAVAILABLE, NO_SHIPPING_RATE, QUOTE_EXPIRED or QUOTE_CHANGED', type: ApiResponse::ERROR_SCHEMA)]
    #[Response(419, 'CSRF mismatch', type: ApiResponse::ERROR_SCHEMA)]
    #[Response(429, 'Checkout rate limit', type: ApiResponse::ERROR_SCHEMA)]
    public function preview(Request $request, CheckoutPricing $pricing): JsonResponse
    {
        $data = $request->validate(self::INPUT);
        ApiInput::fields($request, $data);

        return ApiResponse::success($pricing->preview($request->user()->id, $data))->header('Cache-Control', 'private, no-store');
    }

    #[HeaderParameter('Idempotency-Key', required: true, type: 'string')]
    #[HeaderParameter('X-XSRF-TOKEN', required: true, type: 'string')]
    #[IgnoreResponse(200)]
    #[Response(201, 'Cart converted; replay returns the same checkout', type: self::CHECKOUT)]
    #[Response(403, 'Verified customer required', type: ApiResponse::ERROR_SCHEMA)]
    #[Response(404, 'Address or shipping quote not found', type: ApiResponse::ERROR_SCHEMA)]
    #[Response(409, 'VERSION_CONFLICT, QUOTE_CHANGED, QUOTE_EXPIRED, OFFER_UNAVAILABLE, OUT_OF_STOCK, QUOTA_FULL or REQUEST_IN_PROGRESS', type: ApiResponse::ERROR_SCHEMA)]
    #[Response(419, 'CSRF mismatch', type: ApiResponse::ERROR_SCHEMA)]
    #[Response(422, 'Invalid input or IDEMPOTENCY_KEY_REUSED', type: ApiResponse::ERROR_SCHEMA)]
    #[Response(429, 'Checkout rate limit', type: ApiResponse::ERROR_SCHEMA)]
    public function submit(Request $request, ConfirmCheckout $checkout, IdempotentMutation $keys): JsonResponse
    {
        $data = $request->validate(self::INPUT + ['quote_hash' => 'required|string|regex:/\A[a-f0-9]{64}\z/D', 'notes' => 'sometimes|string|max:1000']);
        ApiInput::fields($request, $data);
        $owner = $request->user()->id;

        return $keys->run($request, fn () => 'user:'.$owner, function () use ($owner, $data, $checkout) {
            try {
                return ApiResponse::success($checkout->submit($owner, $data), 'Checkout completed', 201);
            } catch (ApiProblem $e) {
                return $e->render();
            }
        })->header('Cache-Control', 'private, no-store');
    }

    #[Response(200, 'Own checkout result', type: self::CHECKOUT)]
    #[Response(403, 'Verified customer required', type: ApiResponse::ERROR_SCHEMA)]
    #[Response(404, 'Checkout not found', type: ApiResponse::ERROR_SCHEMA)]
    public function show(Request $request, ConfirmCheckout $checkout, string $id): JsonResponse
    {
        ApiInput::fields($request, []);

        return ApiResponse::success($checkout->result($request->user()->id, $id))->header('Cache-Control', 'private, no-store');
    }
}
