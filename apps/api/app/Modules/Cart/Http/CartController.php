<?php

namespace App\Modules\Cart\Http;

use App\Modules\Cart\CustomerCart;
use App\Support\Http\ApiInput;
use App\Support\Http\ApiResponse;
use Dedoc\Scramble\Attributes\HeaderParameter;
use Dedoc\Scramble\Attributes\IgnoreResponse;
use Dedoc\Scramble\Attributes\QueryParameter;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final class CartController
{
    private const CART = "array{success:bool,message:string,data:array{version:int,items:list<array{id:string,sku:string,campaign_slug?:string,qty:int,term:'FULL'|'DEPOSIT',display_unit_amount:int,availability:'AVAILABLE'|'PRICE_CHANGED'|'UNAVAILABLE'}>,estimated_total_amount:int},meta:array{request_id:string}}";

    private const OFFER_CONFLICT = "array{success:false,code:'VERSION_CONFLICT'|'OFFER_UNAVAILABLE',message:string,meta:array{request_id:string}}";

    public const VERSION = 'required|integer|between:0,2147483647';

    public const QTY = 'required|integer|between:1,10000';

    public const CODE = 'required|string|max:80|regex:/\A[A-Za-z0-9_.-]+\z/D';

    #[Response(200, 'Own cart; empty virtual cart has version 0', type: self::CART)]
    #[Response(403, 'Verified customer required', type: ApiResponse::ERROR_SCHEMA)]
    #[Response(422, 'No query fields accepted', type: ApiResponse::ERROR_SCHEMA)]
    public function show(Request $request, CustomerCart $cart): JsonResponse
    {
        ApiInput::fields($request, []);

        return $this->response($cart->read($request->user()->id));
    }

    #[HeaderParameter('X-XSRF-TOKEN', required: true, type: 'string')]
    #[IgnoreResponse(200)]
    #[Response(201, 'Item quantity set', type: self::CART)]
    #[Response(403, 'Verified customer required', type: ApiResponse::ERROR_SCHEMA)]
    #[Response(409, 'VERSION_CONFLICT or OFFER_UNAVAILABLE', type: self::OFFER_CONFLICT)]
    #[Response(419, 'CSRF token mismatch', type: ApiResponse::ERROR_SCHEMA)]
    public function store(Request $request, CustomerCart $cart): JsonResponse
    {
        $data = $request->validate(['sku' => self::CODE, 'campaign_slug' => 'sometimes|'.self::CODE, 'qty' => self::QTY,
            'term' => 'required|string|in:FULL,DEPOSIT', 'cart_version' => self::VERSION]);
        ApiInput::fields($request, $data);

        return $this->response($cart->set($request->user()->id, $data), 201);
    }

    #[HeaderParameter('X-XSRF-TOKEN', required: true, type: 'string')]
    #[Response(200, 'Item updated', type: self::CART)]
    #[Response(403, 'Verified customer required', type: ApiResponse::ERROR_SCHEMA)]
    #[Response(404, 'Item not in your active cart', type: ApiResponse::ERROR_SCHEMA)]
    #[Response(409, 'VERSION_CONFLICT or OFFER_UNAVAILABLE', type: self::OFFER_CONFLICT)]
    #[Response(419, 'CSRF token mismatch', type: ApiResponse::ERROR_SCHEMA)]
    public function update(Request $request, CustomerCart $cart, string $id): JsonResponse
    {
        $data = $request->validate(['qty' => self::QTY, 'term' => 'sometimes|required|string|in:FULL,DEPOSIT', 'cart_version' => self::VERSION]);
        ApiInput::fields($request, $data);

        return $this->response($cart->set($request->user()->id, $data, $id));
    }

    #[QueryParameter('cart_version', type: 'int', required: true)]
    #[HeaderParameter('X-XSRF-TOKEN', required: true, type: 'string')]
    #[Response(200, 'Item removed', type: self::CART)]
    #[Response(403, 'Verified customer required', type: ApiResponse::ERROR_SCHEMA)]
    #[Response(404, 'Item not in your active cart', type: ApiResponse::ERROR_SCHEMA)]
    #[Response(409, 'VERSION_CONFLICT', type: "array{success:false,code:'VERSION_CONFLICT',message:string,meta:array{request_id:string}}")]
    #[Response(419, 'CSRF token mismatch', type: ApiResponse::ERROR_SCHEMA)]
    #[Response(422, 'Query must contain only cart_version; no body fields', type: ApiResponse::ERROR_SCHEMA)]
    public function destroy(Request $request, CustomerCart $cart, string $id): JsonResponse
    {
        if (array_diff(array_keys($request->query()), ['cart_version']) || $request->request->count() || $request->json()->count()) {
            throw ValidationException::withMessages(['fields' => 'Send only cart_version in the query.']);
        }
        $data = Validator::make($request->query(), ['cart_version' => self::VERSION])->validate();

        return $this->response($cart->remove($request->user()->id, $id, (int) $data['cart_version']));
    }

    /** @param array<string,mixed> $data */
    private function response(array $data, int $status = 200): JsonResponse
    {
        return ApiResponse::success($data, status: $status)->header('Cache-Control', 'private, no-store');
    }
}
