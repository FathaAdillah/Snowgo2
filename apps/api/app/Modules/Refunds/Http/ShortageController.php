<?php

namespace App\Modules\Refunds\Http;

use App\Modules\Foundation\Audit\Journal;
use App\Modules\Foundation\Audit\Subject;
use App\Modules\Foundation\Outbox\Outbox;
use App\Modules\Refunds\Refunds;
use App\Support\Http\ApiInput;
use App\Support\Http\ApiResponse;
use App\Support\Http\CaseResponses;
use Dedoc\Scramble\Attributes\HeaderParameter;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class ShortageController
{
    private function pending(): Builder
    {
        return DB::table('procurement_receipt_lines as r')
            ->join('procurement_batch_items as b', 'b.id', '=', 'r.batch_item_id')
            ->join('order_items as i', 'i.id', '=', 'b.order_item_id')
            ->join('orders as o', 'o.id', '=', 'i.order_id')
            ->join('order_transition_holds as h', 'h.order_id', '=', 'o.id')
            ->where('h.kind', 'DISPUTE')->whereRaw("h.source_ref='procurement-receipt:' || r.id")
            ->where('r.failed_qty', '>', 0)
            ->select('r.id', 'o.number as order_number', 'i.public_id as order_item_id', 'r.failed_qty', 'r.reason_code');
    }

    #[Response(200, type: 'array{success:bool,message:string,data:list<array{id:int,order_number:string,order_item_id:string,failed_qty:int,reason_code:string}>,meta:array{request_id:string,page:int,per_page:int,last_page:int,total:int}}')]
    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate(ApiInput::PAGE);
        ApiInput::fields($request, $filters);
        $page = $this->pending()->orderBy('r.id')->paginate($filters['per_page'] ?? 20);

        return ApiResponse::success($page->items(), meta: ['page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'per_page' => $page->perPage(), 'total' => $page->total()]);
    }

    #[HeaderParameter('Idempotency-Key', required: true, type: 'string')]
    #[HeaderParameter('X-XSRF-TOKEN', required: true, type: 'string')]
    #[Response(200, type: 'array{success:bool,message:string,data:array{receipt_id:int,refund_number:string|null},meta:array{request_id:string}}')]
    public function dismiss(Request $request, int $receipt): JsonResponse
    {
        return $this->decide($request, $receipt, $request->validate(['reason_code' => 'required|string|max:48']));
    }

    #[HeaderParameter('Idempotency-Key', required: true, type: 'string')]
    #[HeaderParameter('X-XSRF-TOKEN', required: true, type: 'string')]
    #[Response(200, type: 'array{success:bool,message:string,data:array{receipt_id:int,refund_number:string|null},meta:array{request_id:string}}')]
    public function refund(Request $request, int $receipt): JsonResponse
    {
        return $this->decide($request, $receipt, $request->validate(['reason_code' => 'required|string|max:48', 'requested_amount' => 'required|integer|between:1,999999999999']));
    }

    /** @param array<string,mixed> $data */
    private function decide(Request $request, int $receipt, array $data): JsonResponse
    {
        $startRefund = isset($data['requested_amount']);

        return CaseResponses::write($request, $data, function () use ($request, $receipt, $data, $startRefund) {
            $order = $this->pending()->where('r.id', $receipt)->select('o.id')->value('o.id') ?? abort(404);
            DB::table('orders')->where('id', $order)->lockForUpdate()->firstOrFail();
            $row = $this->pending()->where('r.id', $receipt)->first() ?? abort(404);
            $actor = $request->user()->id;
            $reason = Refunds::reason($data['reason_code']);
            $refund = null;
            if ($startRefund) {
                $item = DB::table('order_items')->where('public_id', $row->order_item_id)->firstOrFail();
                // Receipts allocate fulfilled units from the front and final shortages from the back.
                $earlierFailures = DB::table('procurement_receipt_lines as r')->join('procurement_batch_items as b', 'b.id', '=', 'r.batch_item_id')
                    ->where('b.order_item_id', $item->id)->where('r.id', '<', $receipt)->sum('r.failed_qty');
                $end = $item->qty - $earlierFailures;
                if ($data['requested_amount'] > Refunds::paidForRange($item, $end - $row->failed_qty, $end)) {
                    throw Refunds::limit();
                }
                $refund = Refunds::request($actor, ['order_number' => $row->order_number, 'kind' => 'ITEM',
                    'requested_amount' => $data['requested_amount'], 'items' => [['order_item_id' => $row->order_item_id,
                        'qty' => $row->failed_qty, 'requested_amount' => $data['requested_amount']]]], null, $reason);
            }
            $source = 'procurement-receipt:'.$receipt;
            DB::table('order_transition_holds')->where(['order_id' => $order, 'kind' => 'DISPUTE', 'source_ref' => $source])->delete();
            $state = $startRefund ? 'REFUND_REQUESTED' : 'NO_REFUND';
            app(Journal::class)->transition(Subject::Order, $order, 'supplier_shortage', 'OPEN', $state, $source, $actor, $reason);
            app(Journal::class)->audit('SupplierShortageReviewed', Subject::Order, $order, $actor, ['status' => 'OPEN'], ['status' => $state]);
            app(Outbox::class)->record('SupplierShortageReviewed', 'order', $order, ['source_ref' => $source, 'decision' => $state, 'refund_number' => $refund]);

            return ['receipt_id' => $receipt, 'refund_number' => $refund];
        });
    }
}
