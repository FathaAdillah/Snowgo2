<?php

namespace App\Modules\Shipping\Http;

use App\Modules\Foundation\Audit\Journal;
use App\Modules\Foundation\Audit\Subject;
use App\Modules\Foundation\Outbox\Outbox;
use App\Support\Http\ApiInput;
use App\Support\Http\ApiProblem;
use App\Support\Http\ApiResponse;
use Dedoc\Scramble\Attributes\HeaderParameter;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class ShippingMasters
{
    private function index(Request $request, string $table): JsonResponse
    {
        $d = $request->validate([...ApiInput::PAGE, 'active' => 'sometimes|string|in:0,1,true,false', 'q' => 'sometimes|required|string|max:120']);
        ApiInput::fields($request, $d);
        $q = DB::table($table)->when(isset($d['active']), fn ($q) => $q->where('active', $request->boolean('active')));
        if (isset($d['q'])) {
            if ($table === 'shipping_rates') {
                $q->whereRaw('id::text=?', [$d['q']]);
            } else {
                $q->where(fn ($q) => $q->where('code', 'ilike', '%'.$d['q'].'%')->orWhere('name', 'ilike', '%'.$d['q'].'%'));
            }
        }
        $p = $q->orderByDesc('id')->paginate((int) ($d['per_page'] ?? 20), page: (int) ($d['page'] ?? 1));

        return ApiResponse::success($p->items(), meta: ['page' => $p->currentPage(), 'total' => $p->total(), 'per_page' => $p->perPage(), 'last_page' => $p->lastPage()]);
    }

    /** @param array<string,mixed> $d */
    private function save(Request $request, string $table, string $key, array $d, ?int $id = null): JsonResponse
    {
        return ShipmentController::write($request, $d, function () use ($request, $table, $key, $d, $id) {
            $prev = $id ? DB::table($table)->where('id', $id)->lockForUpdate()->first() ?? abort(404) : null;
            if (array_key_exists('active', $d) && ! (bool) $d['active']) {
                abort_unless($request->user()->fresh()?->can('shipping.'.$key.'.archive'), 403);
            }
            if (isset($d['code'])) {
                $d['code'] = strtoupper($d['code']);
            }
            if ($prev) {
                foreach (['courier_id', 'service_id', 'origin_region_id', 'destination_region_id'] as $field) {
                    if (isset($d[$field]) && (int) $d[$field] !== $prev->$field) {
                        throw new ApiProblem(409, 'INVALID_STATE_TRANSITION', 'Archive and create a new route or service.');
                    }
                }
            }
            if ($table === 'shipping_rates') {
                if (($d['eta_min_days'] ?? $prev->eta_min_days) > ($d['eta_max_days'] ?? $prev->eta_max_days)) {
                    throw ValidationException::withMessages(['eta_max_days' => 'Invalid delivery window.']);
                }
                foreach (['origin_region_id', 'destination_region_id'] as $k) {
                    if (isset($d[$k]) && ! DB::table('regions')->where(['id' => $d[$k], 'kind' => 'DISTRICT', 'active' => true])->sharedLock()->exists()) {
                        throw ValidationException::withMessages([$k => 'Select an active shipping district.']);
                    }
                }
            }
            try {
                if ($prev) {
                    DB::table($table)->where('id', $id)->update($d);
                } else {
                    $id = DB::table($table)->insertGetId($d);
                }
            } catch (UniqueConstraintViolationException) {
                throw new ApiProblem(409, 'DUPLICATE_RESOURCE', 'Duplicate shipping master.');
            }
            $row = DB::table($table)->where('id', $id)->firstOrFail();
            [$subject,$event] = match ($table) {
                'couriers' => [Subject::Courier, 'CourierChanged'],'courier_services' => [Subject::CourierService, 'CourierServiceChanged'],default => [Subject::ShippingRate, 'ShippingRateChanged']
            };
            app(Journal::class)->audit($event, $subject, $id, $request->user()->id, $prev ? (array) $prev : null, (array) $row);
            app(Outbox::class)->record($event, $subject->value, $id, ['active' => $row->active]);

            return $row;
        });
    }

    #[Response(200, type: 'array{success:bool,message:string,data:list<array{id:int,code:string,name:string,active:bool}>,meta:array{request_id:string,page:int,per_page:int,last_page:int,total:int}}')]
    public function listCourier(Request $request): JsonResponse
    {
        return $this->index($request, 'couriers');
    }

    #[Response(200, type: 'array{success:bool,message:string,data:array{id:int,code:string,name:string,active:bool},meta:array{request_id:string}}')]
    public function showCourier(Request $request, int $id): JsonResponse
    {
        ApiInput::fields($request, []);

        return ApiResponse::success(DB::table('couriers')->find($id) ?? abort(404));
    }

    #[HeaderParameter('Idempotency-Key', required: true, type: 'string')]
    #[HeaderParameter('X-XSRF-TOKEN', required: true, type: 'string')]
    #[Response(200, type: 'array{success:bool,message:string,data:array{id:int,code:string,name:string,active:bool},meta:array{request_id:string}}')]
    public function createCourier(Request $request): JsonResponse
    {
        $d = $request->validate(['code' => 'required|string|alpha_dash:ascii|max:32', 'name' => 'required|string|max:120', 'active' => 'required|boolean']);

        return $this->save($request, 'couriers', 'courier', $d);
    }

    #[HeaderParameter('Idempotency-Key', required: true, type: 'string')]
    #[HeaderParameter('X-XSRF-TOKEN', required: true, type: 'string')]
    #[Response(200, type: 'array{success:bool,message:string,data:array{id:int,code:string,name:string,active:bool},meta:array{request_id:string}}')]
    public function updateCourier(Request $request, int $id): JsonResponse
    {
        $d = $request->validate(['code' => 'sometimes|required|string|alpha_dash:ascii|max:32', 'name' => 'sometimes|required|string|max:120', 'active' => 'sometimes|required|boolean']);

        return $this->save($request, 'couriers', 'courier', $d, $id);
    }

    #[Response(200, type: 'array{success:bool,message:string,data:list<array{id:int,courier_id:int,code:string,name:string,active:bool}>,meta:array{request_id:string,page:int,per_page:int,last_page:int,total:int}}')]
    public function listService(Request $request): JsonResponse
    {
        return $this->index($request, 'courier_services');
    }

    #[Response(200, type: 'array{success:bool,message:string,data:array{id:int,courier_id:int,code:string,name:string,active:bool},meta:array{request_id:string}}')]
    public function showService(Request $request, int $id): JsonResponse
    {
        ApiInput::fields($request, []);

        return ApiResponse::success(DB::table('courier_services')->find($id) ?? abort(404));
    }

    #[HeaderParameter('Idempotency-Key', required: true, type: 'string')]
    #[HeaderParameter('X-XSRF-TOKEN', required: true, type: 'string')]
    #[Response(200, type: 'array{success:bool,message:string,data:array{id:int,courier_id:int,code:string,name:string,active:bool},meta:array{request_id:string}}')]
    public function createService(Request $request): JsonResponse
    {
        $d = $request->validate(['courier_id' => 'required|integer|exists:couriers,id', 'code' => 'required|string|alpha_dash:ascii|max:32', 'name' => 'required|string|max:120', 'active' => 'required|boolean']);

        return $this->save($request, 'courier_services', 'service', $d);
    }

    #[HeaderParameter('Idempotency-Key', required: true, type: 'string')]
    #[HeaderParameter('X-XSRF-TOKEN', required: true, type: 'string')]
    #[Response(200, type: 'array{success:bool,message:string,data:array{id:int,courier_id:int,code:string,name:string,active:bool},meta:array{request_id:string}}')]
    public function updateService(Request $request, int $id): JsonResponse
    {
        $d = $request->validate(['courier_id' => 'sometimes|required|integer|exists:couriers,id', 'code' => 'sometimes|required|string|alpha_dash:ascii|max:32', 'name' => 'sometimes|required|string|max:120', 'active' => 'sometimes|required|boolean']);

        return $this->save($request, 'courier_services', 'service', $d, $id);
    }

    #[Response(200, type: 'array{success:bool,message:string,data:list<array{id:int,base_amount:int,amount_per_kg:int,origin_region_id:int,destination_region_id:int,eta_min_days:int,eta_max_days:int,service_id:int,active:bool}>,meta:array{request_id:string,page:int,per_page:int,last_page:int,total:int}}')]
    public function listRate(Request $request): JsonResponse
    {
        return $this->index($request, 'shipping_rates');
    }

    #[Response(200, type: 'array{success:bool,message:string,data:array{id:int,base_amount:int,amount_per_kg:int,origin_region_id:int,destination_region_id:int,eta_min_days:int,eta_max_days:int,service_id:int,active:bool},meta:array{request_id:string}}')]
    public function showRate(Request $request, int $id): JsonResponse
    {
        ApiInput::fields($request, []);

        return ApiResponse::success(DB::table('shipping_rates')->find($id) ?? abort(404));
    }

    #[HeaderParameter('Idempotency-Key', required: true, type: 'string')]
    #[HeaderParameter('X-XSRF-TOKEN', required: true, type: 'string')]
    #[Response(200, type: 'array{success:bool,message:string,data:array{id:int,base_amount:int,amount_per_kg:int,origin_region_id:int,destination_region_id:int,eta_min_days:int,eta_max_days:int,service_id:int,active:bool},meta:array{request_id:string}}')]
    public function createRate(Request $request): JsonResponse
    {
        $d = $request->validate(['base_amount' => 'required|integer|between:0,1000000000000', 'amount_per_kg' => 'required|integer|between:0,1000000000000', 'origin_region_id' => 'required|integer|min:1', 'destination_region_id' => 'required|integer|min:1', 'eta_min_days' => 'required|integer|between:0,3650', 'eta_max_days' => 'required|integer|between:0,3650', 'service_id' => 'required|integer|exists:courier_services,id', 'active' => 'required|boolean']);

        return $this->save($request, 'shipping_rates', 'rate', $d);
    }

    #[HeaderParameter('Idempotency-Key', required: true, type: 'string')]
    #[HeaderParameter('X-XSRF-TOKEN', required: true, type: 'string')]
    #[Response(200, type: 'array{success:bool,message:string,data:array{id:int,base_amount:int,amount_per_kg:int,origin_region_id:int,destination_region_id:int,eta_min_days:int,eta_max_days:int,service_id:int,active:bool},meta:array{request_id:string}}')]
    public function updateRate(Request $request, int $id): JsonResponse
    {
        $d = $request->validate(['base_amount' => 'sometimes|required|integer|between:0,1000000000000', 'amount_per_kg' => 'sometimes|required|integer|between:0,1000000000000', 'origin_region_id' => 'sometimes|required|integer|min:1', 'destination_region_id' => 'sometimes|required|integer|min:1', 'eta_min_days' => 'sometimes|required|integer|between:0,3650', 'eta_max_days' => 'sometimes|required|integer|between:0,3650', 'service_id' => 'sometimes|required|integer|exists:courier_services,id', 'active' => 'sometimes|required|boolean']);

        return $this->save($request, 'shipping_rates', 'rate', $d, $id);
    }
}
