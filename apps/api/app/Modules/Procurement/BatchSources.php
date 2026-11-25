<?php

namespace App\Modules\Procurement;

use App\Modules\Orders\OrderSupply;
use App\Modules\Preorders\CampaignWindow;
use App\Modules\RequestOrders\RequestSource;
use App\Support\Http\ApiProblem;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final class BatchSources
{
    public const AVAILABLE_SQL = "i.qty-i.cancelled_qty-i.received_qty-coalesce((select sum(bi.qty-bi.received_qty-bi.failed_qty)
        from procurement_batch_items bi join procurement_batches b on b.id=bi.batch_id
        where bi.order_item_id=i.id and b.status in ('OPEN','IN_PROGRESS')),0)";

    /** @param array<string,mixed> $input
     * @return array{campaign_id:int}|array{request_order_id:int} */
    public static function resolve(array $input): array
    {
        if (isset($input['campaign_number'])) {
            $row = CampaignWindow::lock($input['campaign_number']);
            if (! in_array($row->status, ['IN_PROCUREMENT', 'FULFILLING'], true)) {
                throw new ApiProblem(409, 'INVALID_STATE_TRANSITION', 'Campaign is not in procurement.');
            }

            return ['campaign_id' => $row->id];
        }
        $row = RequestSource::lock($input['request_number']);
        if ($row->status !== 'CONVERTED_TO_ORDER') {
            throw new ApiProblem(409, 'INVALID_STATE_TRANSITION', 'Request has no confirmed conversion.');
        }

        return ['request_order_id' => $row->id];
    }

    /** Lock the same source as create before acquiring order, item or allocation locks. */
    public static function lockBatch(string $number): object
    {
        $q = DB::table('procurement_batches')->where('number', $number);
        $b = (clone $q)->first() ?? abort(404);
        if ($b->campaign_id !== null) {
            CampaignWindow::lock((int) $b->campaign_id);
        } else {
            RequestSource::lock((int) $b->request_order_id);
        }

        return $q->lockForUpdate()->firstOrFail();
    }

    /** @param array{campaign_id:int}|array{request_order_id:int} $source
     * @param list<string> $refs
     * @return Collection<string,\stdClass> */
    public static function lines(array $source, array $refs): Collection
    {
        $items = OrderSupply::lockLines($source, $refs);
        if (isset($source['campaign_id']) && DB::table('preorder_allocations as a')->join('preorder_campaign_variants as v', 'v.id', '=', 'a.campaign_variant_id')
            ->where('v.campaign_id', $source['campaign_id'])->whereIn('a.order_item_id', $items->pluck('id'))->where('a.status', 'CONFIRMED')->count() !== $items->count()) {
            throw new ApiProblem(409, 'INVALID_STATE_TRANSITION', 'Only confirmed allocations are eligible.');
        }

        return $items;
    }

    public static function remaining(object $item): int
    {
        return (int) DB::table('order_items as i')->where('i.id', $item->id)->selectRaw(self::AVAILABLE_SQL.' as available')->value('available');
    }
}
