<?php

namespace App\Modules\Returns\Http;

use App\Modules\Returns\ReturnClaims;
use App\Modules\Returns\ReturnInspection;
use App\Modules\Returns\ReturnResolution;
use App\Support\Http\ApiInput;
use App\Support\Http\ApiResponse;
use App\Support\Http\CaseResponses;
use Dedoc\Scramble\Attributes\HeaderParameter;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

final class ReturnController
{
    private const ROW = 'array{number:string,order_number:string,shipment_number:string,status:string,reason_code:string,instructions:string|null,customer_tracking_number:string|null,resolution:string|null,outcome_note:string|null,refund_number:string|null,replacement_number:string|null,items:list<array{shipment_item_id:string,qty:int,accepted_qty:int,disposition:string|null}>,evidence:list<array{id:string,status:string}>,resolved_at:string|null}';

    private const ONE = 'array{success:bool,message:string,data:'.self::ROW.',meta:array{request_id:string}}';

    private function rows(Request $request): Builder
    {
        return DB::table('returns as c')->join('orders as o', 'o.id', '=', 'c.order_id')->join('shipments as s', 's.id', '=', 'c.shipment_id')->join('reason_codes as rc', 'rc.id', '=', 'c.reason_id')
            ->when(! $request->routeIs('returns.staff.*'), fn ($q) => $q->where('o.user_id', $request->user()->id))
            ->select('c.number', 'o.number as order_number', 's.number as shipment_number', 'c.status', 'rc.code as reason_code', 'c.instructions', 'c.customer_tracking_number', 'c.resolution', 'c.outcome_note', 'c.resolved_at')
            ->selectRaw("(SELECT number FROM refunds WHERE return_id=c.id) AS refund_number,
                (SELECT number FROM shipments WHERE replacement_return_id=c.id ORDER BY id DESC LIMIT 1) AS replacement_number,
                (SELECT json_agg(json_build_object('shipment_item_id',si.public_id,'qty',ri.qty,'accepted_qty',ri.accepted_qty,'disposition',ri.disposition) ORDER BY ri.id)
                FROM return_items ri JOIN shipment_items si ON si.id=ri.shipment_item_id WHERE ri.return_id=c.id) AS items,
                (SELECT json_agg(json_build_object('id',f.public_id,'status',f.status) ORDER BY f.id) FROM stored_files f
                WHERE f.owner_type='return' AND f.owner_id=c.id AND f.collection='return_evidence' AND f.status!='DELETED') AS evidence");
    }

    /** @return array<string,mixed> */
    private function present(object $r): array
    {
        $r->items = json_decode($r->items ?? '[]', true, flags: JSON_THROW_ON_ERROR);
        $r->evidence = json_decode($r->evidence ?? '[]', true, flags: JSON_THROW_ON_ERROR);
        $r->resolved_at = $r->resolved_at ? Carbon::parse($r->resolved_at)->toISOString() : null;

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
        $d = $request->validate(['order_number' => 'required|string|max:40', 'shipment_number' => 'required|string|max:40', 'reason_code' => 'required|string|max:48', 'items' => 'required|array|list|min:1|max:100', 'items.*' => 'required|array:shipment_item_id,qty', 'items.*.shipment_item_id' => 'required|string|ulid|distinct', 'items.*.qty' => 'required|integer|between:1,100000', 'note' => 'sometimes|string|max:1000']);

        return CaseResponses::write($request, $d, function () use ($request, $d) {
            $actor = $request->user()->id;
            $number = ReturnClaims::open($actor, $d, $request->routeIs('returns.staff.*') ? null : $actor);

            return $this->detail($request, $number);
        }, 201);
    }

    #[HeaderParameter('Idempotency-Key', required: true, type: 'string')]
    #[HeaderParameter('X-XSRF-TOKEN', required: true, type: 'string')]
    #[Response(200, type: self::ONE)]
    public function dispatch(Request $request, string $number): JsonResponse
    {
        $d = $request->validate(['tracking_number' => 'required|string|max:120']);

        return CaseResponses::write($request, $d, function () use ($request, $d, $number) {
            $actor = $request->user()->id;
            $r = ReturnClaims::lock($number, $actor);
            if ($r->status !== 'AWAITING_SHIPMENT') {
                throw ReturnClaims::invalid();
            }
            ReturnClaims::change($r, 'IN_TRANSIT', $actor, 'ReturnDispatched', ['customer_tracking_number' => $d['tracking_number']]);

            return $this->detail($request, $number);
        }, 200);
    }

    #[HeaderParameter('Idempotency-Key', required: true, type: 'string')]
    #[HeaderParameter('X-XSRF-TOKEN', required: true, type: 'string')]
    #[Response(200, type: self::ONE)]
    public function review(Request $request, string $number): JsonResponse
    {
        $d = $request->validate(['decision' => 'required|string|in:START,APPROVE,REJECT,GIVE_INSTRUCTIONS,WAIVE_SHIPMENT', 'reason_code' => 'required_if:decision,REJECT,WAIVE_SHIPMENT|string|max:48', 'instructions' => 'required_if:decision,GIVE_INSTRUCTIONS|string|max:5000']);

        return CaseResponses::write($request, $d, function () use ($request, $d, $number) {
            $actor = $request->user()->id;
            $r = ReturnClaims::lock($number, null);
            ReturnClaims::review($r, $actor, $d);

            return $this->detail($request, $number);
        }, 200);
    }

    #[HeaderParameter('Idempotency-Key', required: true, type: 'string')]
    #[HeaderParameter('X-XSRF-TOKEN', required: true, type: 'string')]
    #[Response(200, type: self::ONE)]
    public function receive(Request $request, string $number): JsonResponse
    {
        $d = $request->validate(['note' => 'sometimes|string|max:1000']);

        return CaseResponses::write($request, $d, function () use ($request, $d, $number) {
            $actor = $request->user()->id;
            $r = ReturnClaims::lock($number, null);
            if ($r->status !== 'IN_TRANSIT') {
                throw ReturnClaims::invalid();
            }
            ReturnClaims::change($r, 'RECEIVED', $actor, 'ReturnReceived', ['received_at' => now(), 'receipt_note' => $d['note'] ?? null]);

            return $this->detail($request, $number);
        }, 200);
    }

    #[HeaderParameter('Idempotency-Key', required: true, type: 'string')]
    #[HeaderParameter('X-XSRF-TOKEN', required: true, type: 'string')]
    #[Response(200, type: self::ONE)]
    public function inspect(Request $request, string $number): JsonResponse
    {
        $d = $request->validate(['result' => 'required|string|in:ACCEPT,FAIL', 'note' => 'required|string|max:5000', 'items' => 'required|array|list|min:1|max:100', 'items.*' => 'required|array:shipment_item_id,accepted_qty,disposition', 'items.*.shipment_item_id' => 'required|string|ulid|distinct', 'items.*.accepted_qty' => 'required|integer|between:0,100000', 'items.*.disposition' => 'required|string|in:RESTOCK,DAMAGE,NO_RECEIPT']);

        return CaseResponses::write($request, $d, function () use ($request, $d, $number) {
            $actor = $request->user()->id;
            $r = ReturnClaims::lock($number, null);
            ReturnInspection::apply($r, $actor, $d);

            return $this->detail($request, $number);
        }, 200);
    }

    #[HeaderParameter('Idempotency-Key', required: true, type: 'string')]
    #[HeaderParameter('X-XSRF-TOKEN', required: true, type: 'string')]
    #[Response(200, type: self::ONE)]
    public function resolve(Request $request, string $number): JsonResponse
    {
        $d = $request->validate(['resolution' => 'required|string|in:REFUND,REPLACEMENT,REPAIR,SHIP_MISSING_ITEM,REJECTED', 'note' => 'required|string|max:1000', 'work_completed' => 'sometimes|boolean']);

        return CaseResponses::write($request,$d,function () use ($request,$d,$number) {
            $actor = $request->user()->id;
            $r = ReturnClaims::lock($number,null);
            ReturnResolution::apply($r,$actor,$d);

            return $this->detail($request,$number);
        },200);
    }
}
