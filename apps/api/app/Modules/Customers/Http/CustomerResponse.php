<?php

namespace App\Modules\Customers\Http;

use App\Support\Http\ApiResponse;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\JsonResponse;

final class CustomerResponse
{
    public const META = 'array{request_id:string,page:int,last_page:int,per_page:int,total:int}';

    public const ADDRESS = 'array{id:string,recipient:string,phone:string,district_code:string,line1:string,line2:string|null,postal_code:string,is_default:bool}';

    public const ADDRESSES = 'array{success:bool,message:string,data:list<'.self::ADDRESS.'>,meta:'.self::META.'}';

    public const PHONE = 'regex:/^\+[1-9][0-9]{1,14}\z/';

    /** @param array<string,mixed> $meta */
    public static function ok(mixed $data = null, int $status = 200, array $meta = []): JsonResponse
    {
        return ApiResponse::success($data, status: $status, meta: $meta)->header('Cache-Control', 'private, no-store');
    }

    /** @param array<string,mixed> $filters */
    public static function page(Builder $query, array $filters): JsonResponse
    {
        $page = $query->paginate((int) ($filters['per_page'] ?? 20), page: (int) ($filters['page'] ?? 1));

        return self::ok($page->items(), meta: ['page' => $page->currentPage(), 'per_page' => $page->perPage(),
            'total' => $page->total(), 'last_page' => $page->lastPage()]);
    }
}
