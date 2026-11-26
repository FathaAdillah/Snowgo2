<?php

namespace App\Modules\Shipping\Http;

use App\Modules\Foundation\Idempotency\IdempotentMutation;
use App\Modules\Shipping\PackageRates;
use App\Modules\Shipping\Packages;
use App\Modules\Shipping\ProviderFreight;
use App\Modules\Shipping\Tracking;
use App\Support\Http\ApiInput;
use App\Support\Http\ApiProblem;
use App\Support\Http\ApiResponse;
use Closure;
use Dedoc\Scramble\Attributes\HeaderParameter;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

final class ShipmentController
{
    #[Response(200, type: 'array{success:bool,message:string,data:list<array{service_id:int,service_code:string,carrier_label:string,amount:int,source:string,rate_key:string}>,meta:array{request_id:string}}')]
    public function options(Request $request): JsonResponse
    {
        $d = $request->validate(['order_number' => 'required|string|max:40', 'warehouse_id' => 'required|integer|min:1', 'measured_weight_g' => 'required|integer|between:1,2147483647']);
        ApiInput::fields($request, $d);
        $offers = ProviderFreight::withLookup(function (bool $lookup) use ($d) {
            $order = DB::table('orders')->where('number', $d['order_number'])->first() ?? abort(404);
            if ($order->status !== 'CONFIRMED' || $order->selling_method === 'READY_TO_SHIP') {
                throw Packages::invalid();
            }
            $warehouse = DB::table('warehouses')->where(['id' => $d['warehouse_id'], 'active' => true])->firstOrFail();

            return PackageRates::available($order, $warehouse, (int) $d['measured_weight_g'], $lookup)
                ->map(fn ($r) => ['service_id' => $r->service_id, 'service_code' => $r->service_code, 'carrier_label' => $r->carrier_label,
                    'amount' => $r->amount, 'source' => $r->source, 'rate_key' => $r->rate_key]);
        });
        if ($offers->isEmpty()) {
            throw new ApiProblem(409, 'NO_SHIPPING_RATE', 'Ongkir belum tersedia untuk rute ini.');
        }

        return ApiResponse::success($offers->all())->header('Cache-Control', 'private, no-store');
    }

    private const ROW = 'array{number:string,order_number:string,method:string,status:string,packing_status:string,carrier_label:string,service_code:string,tracking_number:string|null,shipping_amount:int,shipped_at:string|null,items:list<array{id:string,order_item_id:string,name:string,qty:int}>,tracking:list<array{status:string,occurred_at:string}>}';

    private const ONE = 'array{success:bool,message:string,data:'.self::ROW.',meta:array{request_id:string}}';

    #[Response(200, type: 'array{success:bool,message:string,data:list<'.self::ROW.'>,meta:array{request_id:string,page:int,last_page:int,total:int,per_page:int}}')]
    public function index(Request $request): JsonResponse
    {
        $d = $request->validate([...ApiInput::PAGE, 'status' => 'sometimes|required|string|in:PENDING,PICKED_UP,IN_TRANSIT,OUT_FOR_DELIVERY,FAILED,RETURNED,DELIVERED,CANCELLED', 'from' => 'sometimes|required|date', 'to' => 'sometimes|required|date']);
        ApiInput::fields($request, $d);
        $p = DB::table('shipments')->when($d['status'] ?? null, fn ($q, $v) => $q->where('status', $v))->when($d['from'] ?? null, fn ($q, $v) => $q->where('created_at', '>=', $v))->when($d['to'] ?? null, fn ($q, $v) => $q->where('created_at', '<=', $v))->orderByDesc('id')->paginate((int) ($d['per_page'] ?? 20), ['number'], page: (int) ($d['page'] ?? 1));

        return ApiResponse::success(array_map(fn ($s) => Packages::detail($s->number), $p->items()), meta: ['page' => $p->currentPage(), 'total' => $p->total(), 'last_page' => $p->lastPage(), 'per_page' => $p->perPage()]);
    }

    #[Response(200, type: self::ONE)]
    public function show(Request $request, string $number): JsonResponse
    {
        ApiInput::fields($request, []);

        return ApiResponse::success(Packages::detail($number, $request->routeIs('shipping.customer') ? $request->user()->id : null))->header('Cache-Control', 'private, no-store');
    }

    /** @param array<string,mixed> $d */
    public static function write(Request $request, array $d, Closure $f): JsonResponse
    {
        ApiInput::fields($request, $d);
        $auth = function () use ($request) {
            $u = $request->user()->fresh();
            foreach ($request->route()->gatherMiddleware() as $m) {
                if (str_starts_with($m, 'can:')) {
                    abort_unless($u?->can(substr($m, 4)), 403);
                }
            }

            return 'user:'.$request->user()->id;
        };

        return app(IdempotentMutation::class)->run($request, $auth, function () use ($auth, $f) {
            $auth();

            return ApiResponse::success($f());
        })->header('Cache-Control', 'private, no-store');
    }

    #[HeaderParameter('Idempotency-Key', required: true, type: 'string')]
    #[HeaderParameter('X-XSRF-TOKEN', required: true, type: 'string')]
    #[Response(200, type: self::ONE)]
    public function track(Request $request, string $number): JsonResponse
    {
        $d = $request->validate([
            'status' => 'required|string|in:IN_TRANSIT,OUT_FOR_DELIVERY,DELIVERED,FAILED,RETURNED',
            'occurred_at' => 'required|date|before_or_equal:now',
            'note' => 'required_if:status,FAILED,RETURNED|nullable|string|max:1000',
        ]);

        return self::write($request, $d, function () use ($request, $number, $d) {
            app(Tracking::class)->append($number, 'manual:'.hash('sha256', $request->user()->id.':'.$request->header('Idempotency-Key')), $d['status'], Carbon::parse($d['occurred_at']), $request->user()->id, $d['note'] ?? null);

            return Packages::detail($number);
        });
    }

    #[HeaderParameter('Idempotency-Key', required: true, type: 'string')]
    #[HeaderParameter('X-XSRF-TOKEN', required: true, type: 'string')]
    #[Response(200, type: self::ONE)]
    public function create(Request $request): JsonResponse
    {
        $d = $request->validate(['order_number' => 'required|string|max:40', 'service_code' => 'required|string|max:32', 'service_id' => 'sometimes|required|integer|min:1', 'rate_key' => 'sometimes|required|string|size:64', 'warehouse_id' => 'required|integer|min:1', 'measured_weight_g' => 'sometimes|required|integer|between:1,2147483647', 'items' => 'required|array|list|min:1|max:100', 'items.*' => 'required|array:order_item_id,qty', 'items.*.order_item_id' => 'required|string|ulid|distinct', 'items.*.qty' => 'required|integer|between:1,100000']);

        return ProviderFreight::withLookup(fn (bool $lookup) => self::write($request, $d, fn () => Packages::detail(Packages::plan($d, $request->user()->id, $lookup))));
    }

    #[HeaderParameter('Idempotency-Key', required: true, type: 'string')]
    #[HeaderParameter('X-XSRF-TOKEN', required: true, type: 'string')]
    #[Response(200, type: self::ONE)]
    public function dispatch(Request $request, string $number): JsonResponse
    {
        $d = $request->validate(['service_code' => 'required|string|max:32', 'tracking_number' => 'required|string|max:120']);

        return self::write($request, $d, fn () => Packages::detail(Packages::act($number, 'dispatch', $d, $request->user()->id)));
    }

    #[HeaderParameter('Idempotency-Key', required: true, type: 'string')]
    #[HeaderParameter('X-XSRF-TOKEN', required: true, type: 'string')]
    #[Response(200, type: self::ONE)]
    public function cancel(Request $request, string $number): JsonResponse
    {
        $d = $request->validate(['reason_code' => 'required|string|max:48']);

        return self::write($request, $d, fn () => Packages::detail(Packages::act($number, 'cancel', $d, $request->user()->id)));
    }

    #[HeaderParameter('Idempotency-Key', required: true, type: 'string')]
    #[HeaderParameter('X-XSRF-TOKEN', required: true, type: 'string')]
    #[Response(200, type: self::ONE)]
    public function pack(Request $request, string $number): JsonResponse
    {
        $d = $request->validate(['shipment_number' => 'required|string|max:40', 'action' => 'required|string|in:PACK_START,PACK_FINISH,READY_FOR_DISPATCH']);

        return self::write($request, $d, fn () => Packages::detail(Packages::act($d['shipment_number'], 'pack', $d + ['order_number' => $number], $request->user()->id)));
    }

    #[HeaderParameter('Idempotency-Key', required: true, type: 'string')]
    #[HeaderParameter('X-XSRF-TOKEN', required: true, type: 'string')]
    #[Response(200, type: 'array{success:bool,message:string,data:list<array{id:string,amount:int,service_code:string,carrier_label:string,expires_at:string}>,meta:array{request_id:string}}')]
    public function quote(Request $request): JsonResponse
    {
        $d = $request->validate(['order_number' => 'required|string|max:40', 'shipment_number' => 'required|string|max:40']);

        return self::write($request, $d, fn () => [Packages::quote($d['order_number'], $d['shipment_number'])]);
    }
}
