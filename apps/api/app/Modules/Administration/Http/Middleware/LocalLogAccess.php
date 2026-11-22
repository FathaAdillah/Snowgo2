<?php

namespace App\Modules\Administration\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class LocalLogAccess
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless(app()->environment('local') && $request->getHost() === config('log-viewer.route_domain'), 404);
        $user = (string) config('log-viewer.operator');
        $password = (string) config('log-viewer.password');
        if ($user === '' || $password === '' || ! hash_equals($user, (string) $request->getUser())
            || ! hash_equals($password, (string) $request->getPassword())) {
            return response('Operator login required', 401, ['WWW-Authenticate' => 'Basic realm="Snowego development logs"']);
        }
        abort_unless($request->isMethodSafe() && ! $request->is('*/download', '*/download/*'), 403);

        $response = $next($request);
        $response->headers->set('Cache-Control', 'private, no-store');

        return $response;
    }
}
