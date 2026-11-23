<?php

namespace App\Modules\Identity\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class StaffOrigin
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $url = $request->headers->get('Origin') ?? $request->headers->get('Referer', '');
        $origin = parse_url($url);
        $host = ($origin['scheme'] ?? '').'://'.($origin['host'] ?? '').(isset($origin['port']) ? ':'.$origin['port'] : '');
        abort_unless($host === config('auth.admin_url') && $request->hasSession(), 403);

        $response = $next($request);
        $response->headers->set('Cache-Control', 'private, no-store');

        return $response;
    }
}
