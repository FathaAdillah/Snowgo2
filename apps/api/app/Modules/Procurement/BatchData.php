<?php

namespace App\Modules\Procurement;

use App\Support\Http\ApiResponse;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

final class BatchData
{
    private const META = 'array{request_id:string,page:int,per_page:int,total:int,last_page:int}';

    private const ROW = 'array{number:string,campaign_number:string|null,request_number:string|null,status:string,stage:string,eta_date:string|null,supplier_ref:string|null,notes:string|null,closure_reason:string|null,next_stages:list<string>,closure_target:string|null,items:list<array{order_item_id:string,order_number:string,sku:string|null,qty:int,received_qty:int,failed_qty:int}>,stage_events:list<array{stage:string,note:string|null,eta_date:string|null,occurred_at:string}>}';

    public const ONE = 'array{success:bool,message:string,data:'.self::ROW.',meta:array{request_id:string}}';

    public const PAGE = 'array{success:bool,message:string,data:list<'.self::ROW.'>,meta:'.self::META.'}';

    public const CHOICES = 'array{success:bool,message:string,data:list<array{order_item_id:string,order_number:string,sku:string|null,campaign_number:string|null,request_number:string|null,available_qty:int}>,meta:'.self::META.'}';

    public static function query(): Builder
    {
        return DB::table('procurement_batches as b')->leftJoin('preorder_campaigns as c', 'c.id', '=', 'b.campaign_id')
            ->leftJoin('request_orders as r', 'r.id', '=', 'b.request_order_id')
            ->select('b.number', 'c.number as campaign_number', 'r.number as request_number', 'b.status', 'b.stage', 'b.eta_date', 'b.notes', 'b.supplier_ref', 'b.closure_reason')
            ->selectRaw(<<<'SQL'
                (SELECT json_agg(json_build_object('order_item_id',i.public_id,'order_number',o.number,'sku',i.sku_snapshot,
                    'qty',bi.qty,'received_qty',bi.received_qty,'failed_qty',bi.failed_qty) ORDER BY bi.id)
                    FROM procurement_batch_items bi JOIN order_items i ON i.id=bi.order_item_id JOIN orders o ON o.id=i.order_id WHERE bi.batch_id=b.id) AS items,
                (SELECT json_agg(json_build_object('stage',e.stage,'note',e.note,'eta_date',e.eta_date,'occurred_at',e.occurred_at) ORDER BY e.id)
                    FROM procurement_stage_events e WHERE e.batch_id=b.id) AS stage_events
                SQL);
    }

    public static function choices(): Builder
    {
        $q = DB::table('order_items as i')->join('orders as o', 'o.id', '=', 'i.order_id')->leftJoin('request_orders as r', 'r.id', '=', 'o.request_order_id')
            ->leftJoin('preorder_campaigns as c', 'c.id', '=', 'o.campaign_id')->whereNotNull('i.public_id')
            ->whereRaw("((o.selling_method='PREORDER' AND c.status IN ('IN_PROCUREMENT','FULFILLING') AND o.status IN ('PENDING_PAYMENT','CONFIRMED')
                AND EXISTS (SELECT 1 FROM preorder_allocations a JOIN preorder_campaign_variants v ON v.id=a.campaign_variant_id
                    WHERE v.campaign_id=c.id AND a.order_item_id=i.id AND a.status='CONFIRMED'))
                OR (o.selling_method='REQUEST_ORDER' AND r.status='CONVERTED_TO_ORDER' AND o.status='CONFIRMED' AND EXISTS
                    (SELECT 1 FROM invoices inv WHERE inv.order_id=o.id AND inv.status='PAID' AND inv.purpose IN ('DEPOSIT','FULL_PAYMENT'))))")
            ->select('i.public_id as order_item_id', 'o.number as order_number', 'i.sku_snapshot as sku', 'c.number as campaign_number', 'r.number as request_number')
            ->selectRaw(BatchSources::AVAILABLE_SQL.' as available_qty');

        return DB::query()->fromSub($q, 'eligible')->where('available_qty', '>', 0);
    }

    public static function one(string $number, int $status = 200): JsonResponse
    {
        $row = self::query()->where('b.number', $number)->first() ?? abort(404);

        return ApiResponse::success(self::row($row), status: $status)->header('Cache-Control', 'private, no-store');
    }

    /** @param array<string,mixed> $filters */
    public static function page(Builder $q, array $filters): JsonResponse
    {
        $p = $q->paginate((int) ($filters['per_page'] ?? 20), page: (int) ($filters['page'] ?? 1));

        return ApiResponse::success(array_map(self::row(...), $p->items()), meta: ['page' => $p->currentPage(), 'per_page' => $p->perPage(),
            'last_page' => $p->lastPage(), 'total' => $p->total()])->header('Cache-Control', 'private, no-store');
    }

    private static function row(object $row): object
    {
        foreach (['items', 'stage_events'] as $field) {
            if (property_exists($row, $field)) {
                $row->$field = json_decode($row->$field ?? '[]', false, flags: JSON_THROW_ON_ERROR);
            }
        }

        if (isset($row->items)) {
            /** @var list<object{qty:int,received_qty:int,failed_qty:int}> $lines */
            $lines = $row->items;
            $items = collect($lines);
            $outstanding = $items->contains(fn ($i) => $i->qty > $i->received_qty + $i->failed_qty);
            $row->next_stages = BatchLifecycle::nextStages($row, $outstanding, $items->sum('received_qty') > 0);
            $row->closure_target = BatchLifecycle::closure($row, $outstanding);
        }

        return $row;
    }
}
