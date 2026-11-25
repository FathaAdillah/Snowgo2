<?php

namespace App\Modules\Reporting\Http;

use App\Modules\Reporting\FinanceReport;
use App\Support\Http\ApiInput;
use App\Support\Http\ApiResponse;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

final class ReportController
{
    public const FILTERS = ['method' => 'sometimes|required|string|in:ready_stock,preorder,request_order'];

    private const DASHBOARD = 'array{orders_count:int,by_method:list<array{method:string,orders_count:int,paid_amount:int}>,paid_amount:int,refund_amount:int,net_amount:int,pending_payments:int,shipment_failures:int,open_returns:int,open_refunds:int,low_stock_count:int,trend:list<array{date:string,orders:int,amount:int}>}';

    private const TRANSACTION = 'array{reference:string,order_numbers:list<string>,status:string,occurred_at:string,kind:string,order_number:string,invoice_number:string,payment_number:string,refund_number:string|null,method:string,posted_at:string,amount:int}';

    /** @param array<string,mixed> $d
     * @return array{string,string,?string} */
    private function range(array $d): array
    {
        $to = isset($d['to']) ? Carbon::parse($d['to'], 'Asia/Jakarta') : now('Asia/Jakarta')->startOfDay();
        $from = isset($d['from']) ? Carbon::parse($d['from'], 'Asia/Jakarta') : $to->copy()->subDays(13);
        if ($from->gt($to) || $from->diffInDays($to) >= 92) {
            throw ValidationException::withMessages(['from' => 'Choose a date range of 1 to 92 days.']);
        }

        return [$from->toISOString(), $to->addDay()->toISOString(), FinanceReport::METHODS[$d['method'] ?? ''] ?? null];
    }

    #[Response(200, type: 'array{success:bool,message:string,data:'.self::DASHBOARD.',meta:array{request_id:string}}')]
    public function dashboard(Request $request): JsonResponse
    {
        $d = $request->validate([...self::FILTERS, 'from' => 'sometimes|required|date_format:Y-m-d', 'to' => 'sometimes|required|date_format:Y-m-d']);
        ApiInput::fields($request, $d);

        return ApiResponse::success(FinanceReport::dashboard(...$this->range($d)));
    }

    #[Response(200, type: 'array{success:bool,message:string,data:list<'.self::TRANSACTION.'>,meta:array{request_id:string,page:int,per_page:int,last_page:int,total:int}}')]
    public function transactions(Request $request): JsonResponse
    {
        $d = $request->validate([...ApiInput::PAGE, ...self::FILTERS, 'from' => 'required|date_format:Y-m-d', 'to' => 'required|date_format:Y-m-d']);
        ApiInput::fields($request, $d);
        $p = FinanceReport::ledger(...$this->range($d))->orderByDesc('posted_at')->orderByDesc('allocation_id')->orderByDesc('transfer_id')->paginate((int) ($d['per_page'] ?? 20));
        $rows = [];
        foreach ($p as $row) {
            $rows[] = ['reference' => $row->refund_number ?? $row->payment_number, 'order_numbers' => [$row->order_number],
                'status' => $row->kind === 'CAPTURE' ? 'PAID' : 'COMPLETED', 'occurred_at' => Carbon::parse($row->posted_at)->toISOString(), 'kind' => $row->kind, 'order_number' => $row->order_number, 'invoice_number' => $row->invoice_number, 'payment_number' => $row->payment_number, 'refund_number' => $row->refund_number,
                'method' => array_search($row->selling_method, FinanceReport::METHODS, true), 'posted_at' => Carbon::parse($row->posted_at)->toISOString(), 'amount' => (int) $row->amount];
        }

        return ApiResponse::success($rows, meta: ['page' => $p->currentPage(), 'per_page' => $p->perPage(), 'last_page' => $p->lastPage(), 'total' => $p->total()]);
    }
}
