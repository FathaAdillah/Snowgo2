<?php

namespace App\Modules\Orders\Http;

use App\Modules\Foundation\Idempotency\IdempotentMutation;
use App\Modules\Orders\OrderDirectory;
use App\Modules\Orders\OrderLifecycle;
use App\Support\Http\ApiInput;
use App\Support\Http\ApiProblem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class OrderMutation
{
    /** @param array<string,string> $data */
    public static function run(Request $request, string $number, string $action, array $data, bool $staff = false): JsonResponse
    {
        ApiInput::fields($request, $data);
        $actor = $request->user();
        $owner = $staff ? null : $actor->id;
        $authorize = function () use ($actor, $owner, $number, $staff, $action) {
            if ($staff) {
                abort_unless($actor->fresh()?->can(['orders.order.view', 'orders.order.manage', 'orders.order.'.strtolower($action)]), 403);
            }
            abort_unless(OrderDirectory::scope($owner)->where('number', $number)->exists(), 404);

            return 'user:'.$actor->id;
        };

        return app(IdempotentMutation::class)->run($request, $authorize, function () use ($authorize, $number, $owner, $actor, $action, $data, $staff) {
            $authorize();
            try {
                app(OrderLifecycle::class)->apply($number, $owner, $actor->id, $action, $data);

                return OrderResponse::one($number, $owner, $staff);
            } catch (ApiProblem $e) {
                return $e->render();
            }
        })->header('Cache-Control', 'private, no-store');
    }
}
