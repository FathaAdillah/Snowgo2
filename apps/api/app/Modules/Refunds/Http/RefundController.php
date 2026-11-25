<?php

namespace App\Modules\Refunds\Http;

use App\Modules\Foundation\Http\CaseResponses;
use App\Modules\Refunds\RefundExecution;
use App\Modules\Refunds\Refunds;
use App\Support\Http\ApiInput;
use App\Support\Http\ApiResponse;
use Dedoc\Scramble\Attributes\HeaderParameter;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

final class RefundController
{
    private const ROW = 'array{number:string,order_number:string,status:string,kind:string,reason_code:string,requested_amount:int,approved_amount:int|null,cancel_order:bool,requested_by_me:bool,reviewed_by_me:bool,executor_is_me:bool,evidence:list<array{id:string,status:string}>,items:list<array{order_item_id:string,qty:int,requested_amount:int,approved_amount:int|null}>,completed_at:string|null}';

    private const ONE = 'array{success:bool,message:string,data:'.self::ROW.',meta:array{request_id:string}}';

    private function rows(Request $request): Builder
    {
        return DB::table('refunds as c')->join('orders as o', 'o.id', '=', 'c.order_id')->join('reason_codes as rc', 'rc.id', '=', 'c.reason_id')
            ->when(! $request->routeIs('refunds.staff.*'), fn ($q) => $q->where('o.user_id', $request->user()->id))
            ->select('c.number', 'o.number as order_number', 'c.status', 'c.kind', 'rc.code as reason_code', 'c.requested_amount', 'c.approved_amount', 'c.cancel_order', 'c.completed_at')
            ->selectRaw('c.requested_by=? as requested_by_me, coalesce(c.reviewed_by=?,false) as reviewed_by_me, coalesce(c.executor_id=?,false) as executor_is_me', array_fill(0, 3, $request->user()->id))
            ->selectRaw("(SELECT json_agg(json_build_object('id',f.public_id,'status',f.status) ORDER BY f.id) FROM stored_files f WHERE f.owner_type='refund' AND f.collection='refund_evidence' AND f.owner_id=c.id AND f.status!='DELETED') AS evidence")
            ->selectRaw("(SELECT json_agg(json_build_object('order_item_id',i.public_id,'qty',r.qty,'requested_amount',r.requested_amount,'approved_amount',r.approved_amount) ORDER BY r.id)
                FROM refund_items r JOIN order_items i ON i.id=r.order_item_id WHERE r.refund_id=c.id) AS items");
    }

    /** @return array<string,mixed> */
    private function present(object $r): array
    {
        $r->evidence = json_decode($r->evidence ?? '[]', true, flags: JSON_THROW_ON_ERROR);
        $r->items = json_decode($r->items ?? '[]', true, flags: JSON_THROW_ON_ERROR);
        $r->completed_at = $r->completed_at ? Carbon::parse($r->completed_at)->toISOString() : null;

        return (array) $r;
    }

    /** @return array<string,mixed> */
    private function detail(Request $request, string $number): array
    {
        return $this->present($this->rows($request)->where('c.number', $number)->first() ?? abort(404));
    }

    #[Response(200, type: 'array{success:bool,message:string,data:list<'.self::ROW.'>,meta:array{request_id:string,page:int,per_page:int,last_page:int,total:int}}')]
    public function index(Request $request): JsonResponse
    {
        return CaseResponses::page($request, $this->rows($request), $this->present(...));
    }

    #[Response(200, type: self::ONE)]
    public function show(Request $request, string $number): JsonResponse
    {
        ApiInput::fields($request, []);

        return ApiResponse::success($this->detail($request, $number))->header('Cache-Control', 'private, no-store');
    }

    #[HeaderParameter('Idempotency-Key', required: true, type: 'string')]
    #[HeaderParameter('X-XSRF-TOKEN', required: true, type: 'string')]
    #[Response(201, type: self::ONE)]
    public function create(Request $request): JsonResponse
    {
        $d = $request->validate(['order_number' => 'required|string|max:40', 'kind' => 'required|string|in:FULL,PARTIAL,ITEM', 'requested_amount' => 'required|integer|between:1,999999999999', 'reason_code' => 'required|string|max:48', 'cancel_order' => 'sometimes|boolean', 'note' => 'sometimes|string|max:1000', 'items' => 'required_if:kind,ITEM|array|list|min:1|max:100', 'items.*' => 'required|array:order_item_id,qty,requested_amount', 'items.*.order_item_id' => 'required|string|ulid|distinct', 'items.*.qty' => 'required|integer|between:1,100000', 'items.*.requested_amount' => 'required|integer|between:1,999999999999']);

        return CaseResponses::write($request, $d, function () use ($request, $d) {
            $number = Refunds::request($request->user()->id, $d, $request->routeIs('refunds.staff.*') ? null : $request->user()->id);

            return $this->detail($request, $number);
        }, 201);
    }

    #[HeaderParameter('Idempotency-Key', required: true, type: 'string')]
    #[HeaderParameter('X-XSRF-TOKEN', required: true, type: 'string')]
    #[Response(200, type: self::ONE)]
    public function review(Request $request, string $number): JsonResponse
    {
        $d = $request->validate(['decision' => 'required|string|in:START,APPROVE,REJECT', 'approved_amount' => 'required_if:decision,APPROVE|integer|between:1,999999999999', 'reason_code' => 'required_if:decision,REJECT|string|max:48', 'note' => 'required|string|max:1000', 'items' => 'sometimes|array|list|max:100', 'items.*' => 'required|array:order_item_id,approved_amount', 'items.*.order_item_id' => 'required|string|ulid|distinct', 'items.*.approved_amount' => 'required|integer|between:0,999999999999']);

        return CaseResponses::write($request, $d, function () use ($request, $d, $number) {
            Refunds::review(Refunds::locked($number), $request->user()->id, $d);

            return $this->detail($request, $number);
        }, 200);
    }

    #[HeaderParameter('Idempotency-Key', required: true, type: 'string')]
    #[HeaderParameter('X-XSRF-TOKEN', required: true, type: 'string')]
    #[Response(200, type: self::ONE)]
    public function attempt(Request $request, string $number): JsonResponse
    {
        $d = $request->validate(['action' => 'required|string|in:START,FAIL,RETRY', 'bank_checked' => 'required_unless:action,FAIL|boolean', 'reason_code' => 'required_if:action,FAIL|string|max:48']);

        return CaseResponses::write($request, $d, function () use ($request, $d, $number) {
            RefundExecution::attempt(Refunds::locked($number), $request->user()->id, $d);

            return $this->detail($request, $number);
        }, 200);
    }

    #[HeaderParameter('Idempotency-Key', required: true, type: 'string')]
    #[HeaderParameter('X-XSRF-TOKEN', required: true, type: 'string')]
    #[Response(200, type: self::ONE)]
    public function transfer(Request $request, string $number): JsonResponse
    {
        $d = $request->validate(['transfer_ref' => 'required|string|max:160', 'proof_file_id' => 'required|string|ulid', 'transferred_at' => 'required|date|before_or_equal:now', 'allocations' => 'required|array|list|min:1|max:100', 'allocations.*' => 'required|array:payment_number,invoice_number,amount', 'allocations.*.payment_number' => 'required|string|max:40', 'allocations.*.invoice_number' => 'required|string|max:40|distinct', 'allocations.*.amount' => 'required|integer|between:1,999999999999']);

        return CaseResponses::write($request, $d, function () use ($request, $d, $number) {
            RefundExecution::transfer(Refunds::locked($number), $request->user()->id, $d);

            return $this->detail($request, $number);
        }, 200);
    }

    #[HeaderParameter('Idempotency-Key', required: true, type: 'string')]
    #[HeaderParameter('X-XSRF-TOKEN', required: true, type: 'string')]
    #[Response(200, type: self::ONE)]
    public function cancel(Request $request, string $number): JsonResponse
    {
        $d = $request->validate(['reason_code' => 'required|string|max:48', 'note' => 'required|string|max:1000', 'bank_checked' => 'sometimes|boolean']);

        return CaseResponses::write($request, $d, function () use ($request, $d, $number) {
            RefundExecution::cancel(Refunds::locked($number), $request->user()->id, $d);

            return $this->detail($request, $number);
        }, 200);
    }
}
