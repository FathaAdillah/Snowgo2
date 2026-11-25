<?php

namespace App\Modules\Administration\Http\Middleware;

use App\Models\User;
use App\Modules\Foundation\Audit\Journal;
use App\Modules\Foundation\Audit\Subject;
use App\Modules\Identity\Staff\StaffAccess;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

final class LocalOperatorAccess
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next, string $ability = StaffAccess::LOGS): Response
    {
        abort_unless(app()->environment('local') && $request->getHost() === config('log-viewer.route_domain'), 404);
        $user = $request->user('web');
        abort_unless($user instanceof User && StaffAccess::active($user, $request), 401);
        abort_unless(StaffAccess::super($user) && $user->can($ability), 403);
        abort_unless($request->isMethodSafe() && ! $request->is('*/download', '*/download/*'), 403);
        if ($ability !== StaffAccess::HORIZON || ! $request->is('horizon/api/*')) {
            $request->session()->put('staff.seen', now()->timestamp);
        }

        if (! $request->session()->has("staff.opened.$ability")) {
            DB::transaction(fn () => app(Journal::class)->audit($ability, Subject::User, $user->id, $user->id));
            $request->session()->put("staff.opened.$ability", true);
        }
        $response = $next($request);
        $response->headers->set('Cache-Control', 'private, no-store');

        return $response;
    }
}
