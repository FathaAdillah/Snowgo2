<?php

namespace App\Modules\Preorders;

use App\Modules\Catalog\ProductDirectory;
use App\Support\Http\ApiResponse;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

final class CampaignData
{
    public const STATES = 'DRAFT,SCHEDULED,OPEN,CLOSED,IN_PROCUREMENT,FULFILLING,COMPLETED,CANCELLED';

    public const PUBLIC_STATES = 'SCHEDULED,OPEN,CLOSED,IN_PROCUREMENT,FULFILLING,COMPLETED';

    private const FIELDS = 'number:string,slug:string,name:string,status:string,starts_at:string,ends_at:string,ready_eta_from:string,ready_eta_to:string,ship_eta_from:string,ship_eta_to:string,payment_scheme:string';

    private const VARIANT = 'sku:string,unit_amount:int,deposit_unit_amount:int|null,available_qty:int,min_qty:int,max_qty:int';

    private const DETAILS = self::FIELDS.',origin:string,transport_mode:string,terms:string,variants:list<array{'.self::VARIANT;

    private const META = 'array{request_id:string,page:int,per_page:int,last_page:int,total:int}';

    public const PAGE = 'array{success:bool,message:string,data:list<array{'.self::FIELDS.'}>,meta:'.self::META.'}';

    public const ONE = 'array{success:bool,message:string,data:array{'.self::DETAILS.'}>},meta:array{request_id:string}}';

    private const STAFF_ROW = 'array{'.self::DETAILS.',quota:int,reserved_qty:int,confirmed_qty:int}>}';

    public const STAFF_ONE = 'array{success:bool,message:string,data:'.self::STAFF_ROW.',meta:array{request_id:string}}';

    public const STAFF_PAGE = 'array{success:bool,message:string,data:list<'.self::STAFF_ROW.'>,meta:'.self::META.'}';

    public const ALLOCATIONS = 'array{success:bool,message:string,data:list<array{order_number:string,sku:string,qty:int,status:string,failed_qty:int,expires_at:string}>,meta:'.self::META.'}';

    public static function query(bool $staff, bool $detail): Builder
    {
        $q = DB::table('preorder_campaigns as c')->select('c.number', 'c.slug', 'c.name', 'c.status', 'c.starts_at', 'c.ends_at',
            'c.ready_eta_from', 'c.ready_eta_to', 'c.ship_eta_from', 'c.ship_eta_to', 'c.payment_scheme');
        if (! $staff) {
            $q->whereIn('c.status', explode(',', self::PUBLIC_STATES));
        }
        if ($detail) {
            $extra = $staff ? ",'quota',v.quota,'reserved_qty',v.reserved_qty,'confirmed_qty',v.confirmed_qty" : '';
            $variants = DB::table('preorder_campaign_variants as v')->join('product_variants as p', 'p.id', '=', 'v.variant_id')->whereColumn('v.campaign_id', 'c.id')
                ->selectRaw("json_agg(json_build_object('sku',p.sku,'unit_amount',v.unit_amount,'deposit_unit_amount',v.deposit_unit_amount,
                    'min_qty',v.min_qty,'max_qty',v.max_qty,'available_qty',v.quota-v.reserved_qty-v.confirmed_qty $extra) ORDER BY p.sku)");
            if (! $staff) {
                $variants->whereIn('v.variant_id', ProductDirectory::offerableVariants()->select('id'));
            }
            $q->addSelect('c.origin', 'c.transport_mode', 'c.terms')->selectSub($variants, 'variants');
        }

        return $q;
    }

    public static function one(string $key, bool $staff, int $status = 200): JsonResponse
    {
        $row = self::query($staff, true)->where($staff ? 'c.number' : 'c.slug', $key)->first() ?? abort(404);

        return ApiResponse::success(self::row($row), status: $status)->header('Cache-Control', 'private, no-store');
    }

    /** @param array<string,mixed> $filters */
    public static function page(Builder $q, array $filters, string $sort): JsonResponse
    {
        $p = $q->orderByDesc($sort)->paginate((int) ($filters['per_page'] ?? 20), page: (int) ($filters['page'] ?? 1));

        return ApiResponse::success(array_map(self::row(...), $p->items()), meta: ['page' => $p->currentPage(), 'per_page' => $p->perPage(), 'total' => $p->total(), 'last_page' => $p->lastPage()])->header('Cache-Control', 'private, no-store');
    }

    /** @return array<string,mixed> */
    private static function row(object $row): array
    {
        $data = (array) $row;
        foreach (['starts_at', 'ends_at', 'expires_at'] as $key) {
            if (isset($data[$key])) {
                $data[$key] = Carbon::parse($data[$key])->toISOString();
            }
        }
        if (array_key_exists('variants', $data)) {
            $data['variants'] = json_decode($data['variants'] ?? '[]', false, flags: JSON_THROW_ON_ERROR);
        }

        return $data;
    }
}
