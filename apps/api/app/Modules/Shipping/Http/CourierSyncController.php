<?php

namespace App\Modules\Shipping\Http;

use App\Modules\Foundation\Idempotency\IdempotentMutation;
use App\Modules\Shipping\CourierMasterSync;
use App\Support\Http\ApiInput;
use App\Support\Http\ApiResponse;
use Dedoc\Scramble\Attributes\HeaderParameter;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class CourierSyncController
{
    #[HeaderParameter('Idempotency-Key', required: true, type: 'string')]
    #[HeaderParameter('X-XSRF-TOKEN', required: true, type: 'string')]
    #[Response(200, type: 'array{success:bool,message:string,data:array{count:int},meta:array{request_id:string}}')]
    #[Response(503, 'Provider error', type: 'array{is_success:false,message:string}')]
    public function __invoke(Request $request): JsonResponse
    {
        $d = $request->validate(['group' => 'required|string|in:couriers,provinces,cities,districts', 'parent' => 'sometimes|required|string|max:4']);
        ApiInput::fields($request, $d);
        $auth = function () use ($request, $d) {
            $u = $request->user()->fresh();
            abort_unless($u?->can('shipping.courier.view') && $u->can('shipping.provider.sync')
                && ($d['group'] === 'couriers'
                    ? $u->can('shipping.courier.manage') && $u->can('shipping.courier.create') && $u->can('shipping.courier.update')
                    : $u->can('geography.region.import')), 403);

            return 'user:'.$u->id;
        };

        return app(IdempotentMutation::class)->runPrepared($request, $auth, function () use ($d, $auth, $request) {
            $rows = CourierMasterSync::fetch($d['group'], $d['parent'] ?? null);

            return function () use ($auth, $d, $rows, $request) {
                $auth();

                return ApiResponse::success(['count' => CourierMasterSync::apply($d['group'], $d['parent'] ?? null, $rows, $request->user()->id)]);
            };
        });
    }
}
