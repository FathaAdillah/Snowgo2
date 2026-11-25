<?php

namespace App\Support\Http;

use App\Modules\Foundation\Idempotency\IdempotentMutation;
use App\Modules\Identity\Staff\StaffAccess;
use Closure;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class CaseResponses
{
    /** @param array<string,mixed> $data
     * @param Closure():array<string,mixed> $action */
    public static function write(Request $request, array $data, Closure $action, int $status = 200): JsonResponse
    {
        ApiInput::fields($request, $data);
        $authorize = static function () use ($request): string {
            $user = $request->user()->fresh() ?? abort(401);
            foreach ($request->route()->gatherMiddleware() as $middleware) {
                if (str_starts_with($middleware, 'can:')) {
                    abort_unless($user->can(substr($middleware, 4)) && ! $request->session()->has(StaffAccess::PASSWORD_ONLY_VERSION), 403);
                }
            }

            if ($request->routeIs('refunds.staff.*') && $request->session()->get('staff.step_up', 0) <= now()->subMinutes(10)->timestamp) {
                throw new ApiProblem(403, 'STEP_UP_REQUIRED', 'Confirm password and a fresh TOTP code');
            }

            return 'user:'.$user->id;
        };

        return app(IdempotentMutation::class)->run($request, $authorize, function () use ($action, $authorize, $status) {
            $authorize();

            return ApiResponse::success($action(), status: $status);
        })->header('Cache-Control', 'private, no-store');
    }

    /** @param Closure(object):array<string,mixed> $present */
    public static function page(Request $request, Builder $rows, Closure $present): JsonResponse
    {
        $d = $request->validate([...ApiInput::PAGE, 'status' => 'sometimes|required|string|max:24', 'order' => 'sometimes|required|string|max:40',
            'from' => 'sometimes|required|date_format:Y-m-d', 'to' => 'sometimes|required|date_format:Y-m-d']);
        ApiInput::fields($request, $d);
        $rows->when($d['status'] ?? null, fn ($q, $v) => $q->where('c.status', $v))
            ->when($d['order'] ?? null, fn ($q, $v) => $q->where('o.number', $v));
        if ($from = $request->date('from', tz: 'Asia/Jakarta')) {
            $rows->where('c.created_at', '>=', $from->startOfDay()->toISOString());
        }
        if ($to = $request->date('to', tz: 'Asia/Jakarta')) {
            $rows->where('c.created_at', '<', $to->startOfDay()->addDay()->toISOString());
        }
        $p = $rows->orderByDesc('c.id')->paginate((int) ($d['per_page'] ?? 20));

        return ApiResponse::success(array_map($present, $p->items()), meta: ['page' => $p->currentPage(), 'last_page' => $p->lastPage(), 'total' => $p->total(), 'per_page' => $p->perPage()])->header('Cache-Control', 'private, no-store');
    }
}
