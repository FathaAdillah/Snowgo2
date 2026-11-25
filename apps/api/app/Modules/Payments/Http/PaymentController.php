<?php

namespace App\Modules\Payments\Http;

use App\Modules\Payments\PaymentData;
use App\Modules\Payments\PaymentFlow;
use App\Support\Http\ApiInput;
use Dedoc\Scramble\Attributes\HeaderParameter;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class PaymentController
{
    #[Response(200, 'Payments', type: PaymentData::PAGE)]
    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([...ApiInput::PAGE, 'order' => 'sometimes|required|string|max:40', 'status' => 'sometimes|required|string|in:UNPAID,WAITING_CONFIRMATION,UNDER_REVIEW,EXPIRED,CANCELLED,REJECTED,PAID,PARTIALLY_REFUNDED,REFUNDED',
            'from' => 'sometimes|required|date_format:Y-m-d', 'to' => 'sometimes|required|date_format:Y-m-d']);
        ApiInput::fields($request, $filters);
        $actor = $request->user();
        $staff = $request->routeIs('payments.staff.*');
        $query = PaymentData::rows($staff ? null : $actor->id)->when($filters['order'] ?? null, fn ($q, $s) => $q->whereIn('p.id', DB::table('payment_allocations as a')->join('invoices as i', 'i.id', '=', 'a.invoice_id')->join('orders as o', 'o.id', '=', 'i.order_id')->where('o.number', $s)->select('a.payment_id')))
            ->when($filters['status'] ?? null, fn ($q, $s) => $q->where('p.status', $s))->orderByDesc('p.id');
        if ($from = $request->date('from', tz: 'Asia/Jakarta')) {
            $query->where('p.created_at', '>=', $from->startOfDay()->toISOString());
        }
        if ($to = $request->date('to', tz: 'Asia/Jakarta')) {
            $query->where('p.created_at', '<', $to->startOfDay()->addDay()->toISOString());
        }

        return PaymentData::page($query, $filters, fn ($p) => PaymentData::present($p, $actor->id, false, false));
    }

    #[Response(200, 'Payment', type: PaymentData::ONE)]
    public function show(Request $request, string $number): JsonResponse
    {
        return PaymentData::detail($number, $request);
    }

    #[HeaderParameter('Idempotency-Key', required: true, type: 'string')]
    #[HeaderParameter('X-XSRF-TOKEN', required: true, type: 'string')]
    #[Response(200, 'Payment instructions', type: PaymentData::ONE)]
    public function create(Request $request): JsonResponse
    {
        $data = $request->validate(['invoice_numbers' => 'required|array|list|min:1|max:50', 'invoice_numbers.*' => 'required|string|max:40|distinct', 'bank_account_code' => 'required|string|max:32']);

        return PaymentData::mutate($request, $data, [], fn () => PaymentData::detail(PaymentFlow::create($request->user()->id, $data['invoice_numbers'], $data['bank_account_code']), $request));
    }

    #[HeaderParameter('Idempotency-Key', required: true, type: 'string')]
    #[HeaderParameter('X-XSRF-TOKEN', required: true, type: 'string')]
    #[Response(200, 'Proof submitted', type: PaymentData::ONE)]
    public function proof(Request $request, string $number): JsonResponse
    {
        $data = $request->validate(['file_id' => 'required|string|ulid']);

        return PaymentData::mutate($request, $data, [], function () use ($request, $number, $data) {
            PaymentFlow::submit($number, $request->user()->id, $data['file_id']);

            return PaymentData::detail($number, $request);
        });
    }

    #[HeaderParameter('Idempotency-Key', required: true, type: 'string')]
    #[HeaderParameter('X-XSRF-TOKEN', required: true, type: 'string')]
    #[Response(200, 'Cancelled intent', type: PaymentData::ONE)]
    public function cancel(Request $request, string $number): JsonResponse
    {
        return PaymentData::mutate($request, [], [], function () use ($request, $number) {
            PaymentFlow::finish($number, $request->user()->id, null);

            return PaymentData::detail($number, $request);
        });
    }

    #[HeaderParameter('Idempotency-Key', required: true, type: 'string')]
    #[HeaderParameter('X-XSRF-TOKEN', required: true, type: 'string')]
    #[Response(200, 'Review lease changed', type: PaymentData::ONE)]
    public function claim(Request $request, string $number): JsonResponse
    {
        $data = $request->validate(['action' => 'required|string|in:CLAIM,RELEASE']);

        return PaymentData::mutate($request, $data, ['payments.payment.view', 'payments.payment.review'], function () use ($request, $number, $data) {
            PaymentFlow::claim($number, $request->user()->id, $data['action']);

            return PaymentData::detail($number, $request);
        });
    }

    #[HeaderParameter('Idempotency-Key', required: true, type: 'string')]
    #[HeaderParameter('X-XSRF-TOKEN', required: true, type: 'string')]
    #[Response(200, 'Finance decision', type: PaymentData::ONE)]
    public function decide(Request $request, string $number): JsonResponse
    {
        $data = $request->validate(['decision' => 'required|string|in:REJECT', 'reason_code' => 'required|string|max:48']);

        return PaymentData::mutate($request, $data, ['payments.payment.view', 'payments.payment.verify'], function () use ($request, $number, $data) {
            PaymentFlow::finish($number, $request->user()->id, $data['reason_code']);

            return PaymentData::detail($number, $request);
        });
    }
}
