<?php

namespace App\Modules\RequestOrders\Http;

use App\Modules\Foundation\Idempotency\IdempotentMutation;
use App\Modules\RequestOrders\CaseData;
use App\Support\Http\ApiInput;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class CaseMutation
{
    public const INVALID = "array{success:false,code:'VALIDATION_FAILED'|'IDEMPOTENCY_KEY_REUSED',message:string,errors?:array<string,list<string>>,meta:array{request_id:string}}";

    public const CODE = 'string|max:48|regex:/\A[A-Za-z0-9_.-]+\z/D';

    /** @param array<string,mixed> $data
     * @param list<string>|null $actions
     * @param Closure(?object,int):JsonResponse $write */
    public static function run(Request $request, array $data, ?string $number, ?array $actions, Closure $write): JsonResponse
    {
        return self::authorized($request, $data, $number, $actions === null ? null : array_map(fn ($a) => 'request_orders.request.'.$a, ['view', 'review', ...$actions]), $write);
    }

    /** @param array<string,mixed> $data
     * @param list<string>|null $permissions
     * @param Closure(?object,int):JsonResponse $write */
    public static function authorized(Request $request, array $data, ?string $number, ?array $permissions, Closure $write): JsonResponse
    {
        ApiInput::fields($request, $data);
        $actor = $request->user();
        $owner = $permissions === null ? $actor->id : null;
        $authorize = function () use ($permissions, $actor, $owner, $number) {
            if ($permissions !== null) {
                abort_unless($actor->fresh()?->can($permissions), 403);
            }
            abort_if($number !== null && ! CaseData::scope($owner)->where('number', $number)->exists(), 404);

            return 'user:'.$actor->id;
        };

        return app(IdempotentMutation::class)->run($request, $authorize, function () use ($authorize, $number, $owner, $write, $actor) {
            $authorize();
            $case = $number === null ? null : CaseData::locked($number, $owner);

            return $write($case, $actor->id);
        })->header('Cache-Control', 'private, no-store');
    }
}
