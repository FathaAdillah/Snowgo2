<?php

namespace App\Modules\Preorders\Http;

use App\Modules\Foundation\Idempotency\IdempotentMutation;
use App\Modules\Preorders\CampaignData;
use App\Support\Http\ApiInput;
use App\Support\Http\ApiProblem;
use Closure;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class CampaignMutation
{
    /** @param array<string,mixed> $data
     * @param Closure(int):string $write */
    public static function run(Request $request, array $data, string $action, Closure $write): JsonResponse
    {
        ApiInput::fields($request, $data);
        $actor = $request->user();
        $authorize = function () use ($actor, $action) {
            abort_unless($actor->fresh()?->can(['preorders.campaign.view', 'preorders.campaign.manage', 'preorders.campaign.'.$action]), 403);

            return 'user:'.$actor->id;
        };
        try {
            return app(IdempotentMutation::class)->run($request, $authorize, function () use ($authorize, $actor, $write, $action) {
                $authorize();

                return CampaignData::one($write($actor->id), true, $action === 'create' ? 201 : 200);
            })->header('Cache-Control', 'private, no-store');
        } catch (UniqueConstraintViolationException $e) {
            if (! str_contains($e->getMessage(), 'preorder_campaigns_slug_key')) {
                throw $e;
            }
            throw new ApiProblem(409, 'DUPLICATE_RESOURCE', 'Campaign slug already exists.');
        }
    }
}
