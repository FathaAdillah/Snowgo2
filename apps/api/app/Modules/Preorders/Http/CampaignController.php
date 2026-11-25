<?php

namespace App\Modules\Preorders\Http;

use App\Modules\Preorders\CampaignData as Data;
use App\Support\Http\ApiInput;
use App\Support\Http\ApiResponse;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class CampaignController
{
    #[Response(200, 'Public campaigns', type: Data::PAGE)]
    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([...ApiInput::PAGE, 'status' => 'sometimes|required|string|in:'.Data::PUBLIC_STATES]);
        ApiInput::fields($request, $filters);
        $q = Data::query(false, false)->when($filters['status'] ?? null, fn ($q, $s) => $q->where('c.status', $s));

        return Data::page($q, $filters, 'c.id');
    }

    #[Response(200, 'Campaign terms and variants', type: Data::ONE)]
    #[Response(404, 'Campaign not published', type: ApiResponse::ERROR_SCHEMA)]
    public function show(Request $request, string $slug): JsonResponse
    {
        ApiInput::fields($request, []);

        return Data::one($slug, false);
    }

    #[Response(200, 'Your allocations in this campaign', type: Data::ALLOCATIONS)]
    #[Response(404, 'Campaign not found', type: ApiResponse::ERROR_SCHEMA)]
    public function allocations(Request $request, string $slug): JsonResponse
    {
        $filters = $request->validate(ApiInput::PAGE);
        ApiInput::fields($request, $filters);
        $q = DB::table('preorder_allocations as a')->join('order_items as i', 'i.id', '=', 'a.order_item_id')->join('orders as o', 'o.id', '=', 'i.order_id')
            ->join('preorder_campaigns as c', 'c.id', '=', 'o.campaign_id')->where(['c.slug' => $slug, 'o.user_id' => $request->user()->id])
            ->select('o.number as order_number', 'i.sku_snapshot as sku', 'a.qty', 'a.status', 'a.failed_qty', 'a.expires_at');
        abort_unless((clone $q)->exists() || Data::query(false, false)->where('c.slug', $slug)->exists(), 404);

        return Data::page($q, $filters, 'a.id');
    }
}
