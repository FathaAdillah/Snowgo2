<?php

namespace App\Modules\Inventory\Http;

use App\Modules\Foundation\Idempotency\IdempotentMutation;
use App\Support\Http\ApiInput;
use App\Support\Http\ApiProblem;
use Closure;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class InventoryMutation
{
    /** @param array<string,mixed> $data
     * @param Closure(int,string):JsonResponse $write */
    public static function run(Request $request, array $data, string $permission, Closure $write): JsonResponse
    {
        ApiInput::fields($request, $data);
        $actor = $request->user();
        $authorize = function () use ($actor, $permission, $data): string {
            $user = $actor->fresh();
            abort_unless($user?->can($permission), 403);
            if (isset($data['active']) && ! $data['active']) {
                abort_unless($user->can('inventory.warehouse.archive'), 403);
            }

            return 'user:'.$actor->id;
        };
        try {
            return app(IdempotentMutation::class)->run($request, $authorize, function () use ($authorize, $write, $actor, $request) {
                $authorize();

                return $write($actor->id, (string) $request->header('Idempotency-Key'));
            })->header('Cache-Control', 'private, no-store');
        } catch (UniqueConstraintViolationException) {
            throw new ApiProblem(409, 'DUPLICATE_RESOURCE', 'This warehouse code or stock operation already exists.');
        }
    }
}
