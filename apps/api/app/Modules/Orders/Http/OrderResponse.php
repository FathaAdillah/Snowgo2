<?php

namespace App\Modules\Orders\Http;

use App\Modules\Orders\OrderDirectory;
use App\Support\Http\ApiResponse;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\JsonResponse;

final class OrderResponse
{
    private const FIELDS = "number:string,method:'READY_TO_SHIP'|'PREORDER'|'REQUEST_ORDER',term:'FULL'|'DEPOSIT',status:'PENDING_PAYMENT'|'CONFIRMED'|'EXPIRED'|'CANCELLED'|'COMPLETED',payment_status:string,fulfillment_status:string,total_amount:int,currency:string,created_at:string,payment_due_at:string";

    private const SUMMARY = 'array{'.self::FIELDS.'}';

    private const REGION = 'array{code:string,name:string}';

    private const ADDRESS = 'array{recipient:string,phone:string,line1:string,line2:string|null,postal_code:string,regions:array{country:'.self::REGION.',province:'.self::REGION.',city:'.self::REGION.',district:'.self::REGION.'}}';

    private const SHIPPING = 'array{quote_id:string,carrier_label:string|null,service_code:string|null,amount:int,estimated_days_from:int,estimated_days_to:int}';

    private const DETAIL = 'array{'.self::FIELDS.',subtotal_amount?:int,discount_amount?:int,tax_amount?:int,shipping_amount?:int,checkout_shipping?:'.self::SHIPPING.',items:list<array{id:string|null,sku:string|null,name:string|null,variant:string|null,qty:int,unit_amount:int|null,received_qty:int,shipped_qty:int,cancelled_qty:int}>,address:'.self::ADDRESS.'|null,history:list<array{dimension:string,to_status:string,occurred_at:string}>,invoice_numbers:list<string>,shipment_numbers:list<string>,payment_numbers:list<string>,charge_consents:list<array{id:string,revision:int,status:string,amount:int,expires_at:string,lines:list<array{type:string,description:string,amount:int}>,invoice_number?:string}>}';

    private const META = 'array{request_id:string,page:int,per_page:int,total:int,last_page:int}';

    public const PAGE = 'array{success:bool,message:string,data:list<'.self::SUMMARY.'>,meta:'.self::META.'}';

    public const STAFF_PAGE = 'array{success:bool,message:string,data:list<'.self::DETAIL.'>,meta:'.self::META.'}';

    public const ONE = 'array{success:bool,message:string,data:'.self::SUMMARY.',meta:array{request_id:string}}';

    public const FULL = 'array{success:bool,message:string,data:'.self::DETAIL.',meta:array{request_id:string}}';

    public const INVALID = "array{success:false,message:string,code:'VALIDATION_FAILED'|'IDEMPOTENCY_KEY_REUSED',errors?:array<string,list<string>>,meta:array{request_id:string}}";

    public static function one(string $number, ?int $owner, bool $detail, bool $address = false, bool $totals = false): JsonResponse
    {
        $query = $detail ? OrderDirectory::details($owner, $address, $totals) : OrderDirectory::summaries($owner);
        $row = $query->where('o.number', $number)->first() ?? abort(404);

        return ApiResponse::success(OrderDirectory::responseData($row))->header('Cache-Control', 'private, no-store');
    }

    /** @param array<string,mixed> $filters */
    public static function page(Builder $query, array $filters): JsonResponse
    {
        foreach (['method' => 'o.selling_method', 'status' => 'o.status'] as $key => $column) {
            if (isset($filters[$key])) {
                $query->where($column, $filters[$key]);
            }
        }
        $page = $query->orderByDesc('o.created_at')->orderByDesc('o.id')->paginate((int) ($filters['per_page'] ?? 20), page: (int) ($filters['page'] ?? 1));
        $data = array_map(OrderDirectory::responseData(...), $page->items());

        return ApiResponse::success($data, meta: ['page' => $page->currentPage(), 'per_page' => $page->perPage(), 'total' => $page->total(), 'last_page' => $page->lastPage()])->header('Cache-Control', 'private, no-store');
    }
}
