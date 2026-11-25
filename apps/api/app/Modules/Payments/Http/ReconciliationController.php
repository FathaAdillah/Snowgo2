<?php

namespace App\Modules\Payments\Http;

use App\Modules\Payments\PaymentData;
use App\Modules\Payments\Reconciliations;
use App\Support\Http\ApiInput;
use App\Support\Http\ApiResponse;
use Dedoc\Scramble\Attributes\HeaderParameter;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class ReconciliationController
{
    /**
     * Create a new class instance.
     */
    public const ROW = 'array{id:int,payment_number:string,status:string,bank_tx_ref:string,amount:int,reason_code:string,refund_transfer_ref:string|null,opened_by_me:bool,reviewed_by_me:bool,review_note:string|null,decision_note:string|null}';

    public const ONE = 'array{success:bool,message:string,data:'.self::ROW.',meta:array{request_id:string}}';

    #[Response(200, type: 'array{success:bool,message:string,data:list<'.self::ROW.'>,meta:array{request_id:string,page:int,last_page:int,per_page:int,total:int}}')]
    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([...ApiInput::PAGE, 'status' => 'sometimes|string|in:OPEN,APPROVED,REJECTED,APPLIED,RETURNED', 'from' => 'sometimes|date', 'to' => 'sometimes|date']);
        ApiInput::fields($request, $filters);
        $rows = Reconciliations::rows($request->user()->id)->when($filters['status'] ?? null, fn ($q, $s) => $q->where('r.status', $s))
            ->when($filters['from'] ?? null, fn ($q, $d) => $q->whereDate('r.created_at', '>=', $d))->when($filters['to'] ?? null, fn ($q, $d) => $q->whereDate('r.created_at', '<=', $d));

        return PaymentData::page($rows->orderByDesc('r.id'), $filters);
    }

    #[Response(200, type: self::ONE)]
    public function show(Request $request, int $id): JsonResponse
    {
        return ApiResponse::success(Reconciliations::rows($request->user()->id)->where('r.id', $id)->first() ?? abort(404))->header('Cache-Control', 'private, no-store');
    }

    #[HeaderParameter('Idempotency-Key', required: true, type: 'string')]
    #[HeaderParameter('X-XSRF-TOKEN', required: true, type: 'string')]
    #[Response(200, type: self::ONE)]
    public function open(Request $request, string $number): JsonResponse
    {
        $data = $request->validate(['bank_tx_ref' => 'required|string|max:160', 'received_amount' => 'required|integer|min:1', 'reason_code' => 'required|string|max:48']);

        return PaymentData::mutate($request, $data, ['payments.reconciliation.view', 'payments.payment.recover'], fn () => $this->show($request, Reconciliations::open($number, $request->user()->id, $data)));
    }

    #[HeaderParameter('Idempotency-Key', required: true, type: 'string')]
    #[HeaderParameter('X-XSRF-TOKEN', required: true, type: 'string')]
    #[Response(200, type: self::ONE)]
    public function review(Request $request, int $id): JsonResponse
    {
        $data = $request->validate(['decision' => 'required|string|in:APPROVE,REJECT', 'reason_code' => 'required_if:decision,REJECT|string|max:48', 'note' => 'required|string|max:1000']);

        return PaymentData::mutate($request, $data, ['payments.reconciliation.view', 'payments.reconciliation.review'], function () use ($request, $id, $data) {
            Reconciliations::act($id, $request->user()->id, $data);

            return $this->show($request, $id);
        });
    }

    #[HeaderParameter('Idempotency-Key', required: true, type: 'string')]
    #[HeaderParameter('X-XSRF-TOKEN', required: true, type: 'string')]
    #[Response(200, type: self::ONE)]
    public function decide(Request $request, int $id): JsonResponse
    {
        $data = $request->validate(['decision' => 'required|string|in:APPLY,RETURN', 'proof_file_id' => 'required_if:decision,RETURN|string|ulid', 'refund_transfer_ref' => 'required_if:decision,RETURN|string|max:160', 'note' => 'required|string|max:1000']);

        return PaymentData::mutate($request, $data, ['payments.reconciliation.view', 'payments.payment.recover'], function () use ($request, $id, $data) {
            Reconciliations::act($id, $request->user()->id, $data);

            return $this->show($request, $id);
        });
    }
}
