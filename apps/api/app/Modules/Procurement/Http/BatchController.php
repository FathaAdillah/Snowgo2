<?php

namespace App\Modules\Procurement\Http;

use App\Modules\Foundation\Idempotency\IdempotentMutation;
use App\Modules\Procurement\BatchData;
use App\Modules\Procurement\BatchLifecycle;
use App\Modules\Procurement\BatchReceipts;
use App\Modules\Procurement\BatchSources;
use App\Support\Http\ApiInput;
use App\Support\Http\ApiResponse;
use Closure;
use Dedoc\Scramble\Attributes\HeaderParameter;
use Dedoc\Scramble\Attributes\IgnoreResponse;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

final class BatchController
{
    public const LIST = 'required|array|list|min:1|max:200';

    public const ITEM = 'required|string|ulid|distinct:ignore_case';

    public const REASON = 'sometimes|required|string|max:48';

    public const NOTE = 'sometimes|required|string|max:1000';

    public const INVALID = "array{success:false,code:'VALIDATION_FAILED'|'IDEMPOTENCY_KEY_REUSED',message:string,errors?:array<string,list<string>>,meta:array{request_id:string}}";

    #[Response(200, 'Batch list', type: BatchData::PAGE)]
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([...ApiInput::PAGE, 'status' => 'sometimes|required|string|in:OPEN,IN_PROGRESS,RECEIVED,CLOSED,CANCELLED',
            'from' => 'sometimes|required|date_format:Y-m-d', 'to' => 'sometimes|required|date_format:Y-m-d', 'number' => 'sometimes|required|string|max:40']);
        ApiInput::fields($request, $data);
        if (isset($data['from'], $data['to']) && $data['to'] < $data['from']) {
            throw ValidationException::withMessages(['to' => 'Must not precede from.']);
        }
        $q = BatchData::query()->when($data['status'] ?? null, fn ($q, $s) => $q->where('b.status', $s))
            ->when($data['number'] ?? null, fn ($q, $n) => $q->where('b.number', $n));
        if ($from = $request->date('from')) {
            $q->where('b.created_at', '>=', $from->startOfDay()->toIso8601String());
        }
        if ($to = $request->date('to')) {
            $q->where('b.created_at', '<', $to->startOfDay()->addDay()->toIso8601String());
        }

        return BatchData::page($q->orderByDesc('b.id'), $data);
    }

    #[Response(200, 'Purchasable units for batch assignment', type: BatchData::CHOICES)]
    public function choices(Request $request): JsonResponse
    {
        $data = $request->validate([...ApiInput::PAGE, 'number' => 'sometimes|required|string|max:40']);
        ApiInput::fields($request, $data);
        $q = BatchData::choices()->when($data['number'] ?? null, fn ($q, $n) => $q->where(fn ($q) => $q->where('order_number', $n)->orWhere('campaign_number', $n)->orWhere('request_number', $n)));

        return BatchData::page($q->orderBy('order_item_id'), $data);
    }

    #[Response(200, 'Batch details', type: BatchData::ONE)]
    #[Response(404, 'Unknown batch', type: ApiResponse::ERROR_SCHEMA)]
    public function show(Request $request, string $number): JsonResponse
    {
        ApiInput::fields($request, []);

        return BatchData::one($number);
    }

    #[HeaderParameter('Idempotency-Key', required: true, type: 'string')]
    #[HeaderParameter('X-XSRF-TOKEN', required: true, type: 'string')]
    #[IgnoreResponse(200)]
    #[Response(201, 'Batch created', type: BatchData::ONE)]
    #[Response(404, 'Source or item not found', type: ApiResponse::ERROR_SCHEMA)]
    #[Response(409, 'Quantity, state or request conflict', type: ApiResponse::ERROR_SCHEMA)]
    #[Response(419, 'CSRF mismatch', type: ApiResponse::ERROR_SCHEMA)]
    #[Response(422, 'Invalid input or reused key', type: self::INVALID)]
    public function create(Request $request, BatchLifecycle $lifecycle): JsonResponse
    {
        $data = $request->validate(['campaign_number' => 'required_without:request_number|prohibits:request_number|string|max:40',
            'request_number' => 'required_without:campaign_number|prohibits:campaign_number|string|max:40',
            'eta_date' => 'sometimes|required|date_format:Y-m-d', 'supplier_ref' => 'sometimes|required|string|max:160', 'notes' => self::NOTE,
            'items' => self::LIST, 'items.*' => 'required|array:order_item_id,qty', 'items.*.order_item_id' => self::ITEM, 'items.*.qty' => 'required|integer|min:1|max:2147483647']);

        return $this->mutate($request, $data, 'create', fn ($actor) => $lifecycle->create($data, $actor));
    }

    #[HeaderParameter('Idempotency-Key', required: true, type: 'string')]
    #[HeaderParameter('X-XSRF-TOKEN', required: true, type: 'string')]
    #[Response(200, 'Stage recorded', type: BatchData::ONE)]
    #[Response(404, 'Unknown batch', type: ApiResponse::ERROR_SCHEMA)]
    #[Response(409, 'State or request conflict', type: ApiResponse::ERROR_SCHEMA)]
    #[Response(419, 'CSRF mismatch', type: ApiResponse::ERROR_SCHEMA)]
    #[Response(422, 'Invalid input or reused key', type: self::INVALID)]
    public function stage(Request $request, string $number, BatchLifecycle $lifecycle): JsonResponse
    {
        $data = $request->validate(['stage' => 'required|string|in:PURCHASED,SELLER_SHIPPED,AT_ORIGIN_WAREHOUSE,INTERNATIONAL_TRANSIT,AT_DESTINATION_WAREHOUSE,IMPORT_FEE_ASSESSMENT,RECEIVED,FAILED',
            'note' => 'required_if:stage,FAILED|string|max:1000', 'eta_date' => 'sometimes|required|date_format:Y-m-d']);

        return $this->mutate($request, $data, 'stage', function ($actor) use ($number, $lifecycle, $data) {
            $lifecycle->stage(BatchSources::lockBatch($number), $data, $actor);

            return $number;
        });
    }

    #[HeaderParameter('Idempotency-Key', required: true, type: 'string')]
    #[HeaderParameter('X-XSRF-TOKEN', required: true, type: 'string')]
    #[Response(200, 'Receipt recorded', type: BatchData::ONE)]
    #[Response(404, 'Unknown batch or item', type: ApiResponse::ERROR_SCHEMA)]
    #[Response(409, 'Quantity, state or request conflict', type: ApiResponse::ERROR_SCHEMA)]
    #[Response(419, 'CSRF mismatch', type: ApiResponse::ERROR_SCHEMA)]
    #[Response(422, 'Invalid input or reused key', type: self::INVALID)]
    public function receipt(Request $request, string $number, BatchReceipts $receipts): JsonResponse
    {
        $data = $request->validate(['reason_code' => self::REASON, 'note' => self::NOTE, 'items' => self::LIST,
            'items.*' => 'required|array:order_item_id,received_qty,failed_qty', 'items.*.order_item_id' => self::ITEM,
            'items.*.received_qty' => 'required|integer|min:0|max:2147483647', 'items.*.failed_qty' => 'required|integer|min:0|max:2147483647']);

        return $this->mutate($request, $data, 'receipt', function ($actor) use ($number, $receipts, $data) {
            $receipts->apply(BatchSources::lockBatch($number), $data, $actor);

            return $number;
        });
    }

    #[HeaderParameter('Idempotency-Key', required: true, type: 'string')]
    #[HeaderParameter('X-XSRF-TOKEN', required: true, type: 'string')]
    #[Response(200, 'Batch closed or cancelled', type: BatchData::ONE)]
    #[Response(404, 'Unknown batch', type: ApiResponse::ERROR_SCHEMA)]
    #[Response(409, 'State or request conflict', type: ApiResponse::ERROR_SCHEMA)]
    #[Response(419, 'CSRF mismatch', type: ApiResponse::ERROR_SCHEMA)]
    #[Response(422, 'Invalid input or reused key', type: self::INVALID)]
    public function close(Request $request, string $number, BatchLifecycle $lifecycle): JsonResponse
    {
        $data = $request->validate(['reason_code' => self::REASON]);

        return $this->mutate($request, $data, 'close', function ($actor) use ($number, $lifecycle, $data) {
            $lifecycle->close(BatchSources::lockBatch($number), $data['reason_code'] ?? null, $actor);

            return $number;
        });
    }

    /** @param array<string,mixed> $data
     * @param Closure(int):string $write */
    private function mutate(Request $request, array $data, string $action, Closure $write): JsonResponse
    {
        ApiInput::fields($request, $data);
        $actor = $request->user();
        $authorize = function () use ($actor, $action) {
            abort_unless($actor->fresh()?->can(['procurement.batch.view', 'procurement.batch.manage', 'procurement.batch.'.$action]), 403);

            return 'user:'.$actor->id;
        };

        return app(IdempotentMutation::class)->run($request, $authorize, function () use ($authorize, $write, $actor, $action) {
            $authorize();

            return BatchData::one($write($actor->id), $action === 'create' ? 201 : 200);
        })->header('Cache-Control', 'private, no-store');
    }
}
