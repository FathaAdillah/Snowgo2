<?php

namespace App\Modules\Preorders\Http;

use App\Modules\Preorders\CampaignData as Data;
use App\Modules\Preorders\CampaignEditor;
use App\Modules\Preorders\CampaignWindow;
use App\Support\Http\ApiInput;
use App\Support\Http\ApiResponse;
use Dedoc\Scramble\Attributes\HeaderParameter;
use Dedoc\Scramble\Attributes\IgnoreResponse;
use Dedoc\Scramble\Attributes\PathParameter;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

final class StaffCampaignController
{
    public const SLUG = 'string|max:160|regex:/\A[a-z0-9]+(?:-[a-z0-9]+)*\z/D';

    public const DATETIME = 'regex:/\A\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d(?:\.\d{1,6})?(?:Z|[+-]\d\d:\d\d)\z/D';

    public const SCHEME = 'string|in:FULL_ONLY,DEPOSIT_ONLY,FULL_OR_DEPOSIT';

    public const VARIANTS = [
        'variants.*' => 'required|array:sku,unit_amount,deposit_unit_amount,quota,min_qty,max_qty',
        'variants.*.sku' => 'required|string|max:80|distinct',
        'variants.*.unit_amount' => 'required|integer|min:1|max:1000000000000',
        'variants.*.deposit_unit_amount' => 'sometimes|nullable|integer|min:1|max:1000000000000',
        'variants.*.quota' => 'required|integer|min:0|max:2147483647',
        'variants.*.min_qty' => 'required|integer|min:1|max:2147483647',
        'variants.*.max_qty' => 'required|integer|min:1|max:2147483647',
    ];

    public const INVALID = "array{success:false,message:string,code:'VALIDATION_FAILED'|'IDEMPOTENCY_KEY_REUSED',errors?:array<string,list<string>>,meta:array{request_id:string}}";

    #[Response(200, 'Campaigns and quota counters', type: Data::STAFF_PAGE)]
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([...ApiInput::PAGE, 'status' => 'sometimes|required|string|in:'.Data::STATES, 'from' => 'sometimes|required|date_format:Y-m-d', 'to' => 'sometimes|required|date_format:Y-m-d']);
        ApiInput::fields($request, $data);
        if (isset($data['from'],$data['to']) && $data['from'] > $data['to']) {
            throw ValidationException::withMessages(['to' => 'Must not precede from.']);
        }
        $q = Data::query(true, true)->when($data['status'] ?? null, fn ($q, $s) => $q->where('c.status', $s));
        if ($from = $request->date('from')) {
            $q->where('c.starts_at', '>=', $from->startOfDay()->toIso8601String());
        }
        if ($to = $request->date('to')) {
            $q->where('c.starts_at', '<', $to->startOfDay()->addDay()->toIso8601String());
        }

        return Data::page($q, $data, 'c.id');
    }

    #[Response(200, 'Campaign with quota counters', type: Data::STAFF_ONE)]
    #[Response(404, 'Campaign not found', type: ApiResponse::ERROR_SCHEMA)]
    public function show(Request $request, string $number): JsonResponse
    {
        ApiInput::fields($request, []);

        return Data::one($number, true);
    }

    #[HeaderParameter('Idempotency-Key', required: true, type: 'string')]
    #[HeaderParameter('X-XSRF-TOKEN', required: true, type: 'string')]
    #[IgnoreResponse(200)]
    #[Response(201, 'Draft created', type: Data::STAFF_ONE)]
    #[Response(404, 'SKU not found', type: ApiResponse::ERROR_SCHEMA)]
    #[Response(409, 'Duplicate or request in progress', type: ApiResponse::ERROR_SCHEMA)]
    #[Response(419, 'CSRF mismatch', type: ApiResponse::ERROR_SCHEMA)]
    #[Response(422, 'Invalid input or key reused', type: self::INVALID)]
    public function create(Request $request, CampaignEditor $editor): JsonResponse
    {
        $data = $request->validate(['slug' => 'required|'.self::SLUG, 'name' => 'required|string|max:160', 'starts_at' => ['required', 'string', 'date', self::DATETIME], 'ends_at' => ['required', 'string', 'date', self::DATETIME],
            'ready_eta_from' => 'required|date_format:Y-m-d', 'ready_eta_to' => 'required|date_format:Y-m-d', 'ship_eta_from' => 'required|date_format:Y-m-d', 'ship_eta_to' => 'required|date_format:Y-m-d',
            'origin' => 'required|string|max:80', 'transport_mode' => 'required|string|in:AIR,SEA,OTHER', 'payment_scheme' => 'required|'.self::SCHEME, 'terms' => 'required|string|max:5000', 'variants' => 'required|array|list|min:1|max:200', ...self::VARIANTS]);

        return CampaignMutation::run($request, $data, 'create', fn ($actor) => $editor->save(null, $data, $actor));
    }

    #[HeaderParameter('Idempotency-Key', required: true, type: 'string')]
    #[HeaderParameter('X-XSRF-TOKEN', required: true, type: 'string')]
    #[Response(200, 'Campaign updated', type: Data::STAFF_ONE)]
    #[Response(404, 'Campaign or SKU not found', type: ApiResponse::ERROR_SCHEMA)]
    #[Response(409, 'Quota, slug, state or request conflict', type: ApiResponse::ERROR_SCHEMA)]
    #[Response(419, 'CSRF mismatch', type: ApiResponse::ERROR_SCHEMA)]
    #[Response(422, 'Invalid input or key reused', type: self::INVALID)]
    public function update(Request $request, CampaignEditor $editor, string $number): JsonResponse
    {
        $data = $request->validate(['slug' => 'sometimes|required|'.self::SLUG, 'name' => 'sometimes|required|string|max:160', 'starts_at' => ['sometimes', 'required', 'string', 'date', self::DATETIME], 'ends_at' => ['sometimes', 'required', 'string', 'date', self::DATETIME],
            'ready_eta_from' => 'sometimes|required|date_format:Y-m-d', 'ready_eta_to' => 'sometimes|required|date_format:Y-m-d', 'ship_eta_from' => 'sometimes|required|date_format:Y-m-d', 'ship_eta_to' => 'sometimes|required|date_format:Y-m-d',
            'origin' => 'sometimes|required|string|max:80', 'transport_mode' => 'sometimes|required|string|in:AIR,SEA,OTHER', 'payment_scheme' => 'sometimes|required|'.self::SCHEME, 'terms' => 'sometimes|required|string|max:5000', 'variants' => 'sometimes|required|array|list|min:1|max:200', ...self::VARIANTS]);

        return CampaignMutation::run($request, $data, 'update', fn ($actor) => $editor->save($number, $data, $actor));
    }

    #[HeaderParameter('Idempotency-Key', required: true, type: 'string')]
    #[HeaderParameter('X-XSRF-TOKEN', required: true, type: 'string')]
    #[PathParameter('action', type: "'SCHEDULE'|'OPEN'|'CLOSE'|'START_PROCUREMENT'|'FULFILL'|'COMPLETE'|'CANCEL'")]
    #[Response(200, 'Campaign transition', type: Data::STAFF_ONE)]
    #[Response(404, 'Campaign or action not found', type: ApiResponse::ERROR_SCHEMA)]
    #[Response(409, 'Invalid transition or request in progress', type: ApiResponse::ERROR_SCHEMA)]
    #[Response(419, 'CSRF mismatch', type: ApiResponse::ERROR_SCHEMA)]
    #[Response(422, 'Invalid input or key reused', type: self::INVALID)]
    public function action(Request $request, CampaignWindow $window, string $number, string $action): JsonResponse
    {
        abort_unless($request->user()->can('preorders.campaign.'.strtolower($action)), 403);
        $data = $request->validate(['reason_code' => 'sometimes|required|string|max:48|regex:/\A[A-Za-z0-9_.-]+\z/D', 'note' => 'sometimes|required|string|max:1000']);

        return CampaignMutation::run($request, $data, strtolower($action), function ($actor) use ($window, $number, $action, $data) {
            $window->act($number, $action, $actor, $data);

            return $number;
        });
    }
}
