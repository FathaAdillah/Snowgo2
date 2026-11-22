<?php

namespace App\Modules\Identity\Http\Middleware;

use App\Models\User;
use App\Modules\Identity\Sessions\DeviceSessions;
use App\Modules\Identity\Surface;
use App\Support\Http\ApiProblem;
use Closure;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * `session`: any established surface; `customer`: verified customer; `customer-unverified`: verification exemptions.
 */
final class EnsureIdentity
{
    public function __construct(private readonly DeviceSessions $sessions) {}

    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next, string $mode = 'session'): Response
    {
        $user = $request->user();
        $surface = DeviceSessions::surface($request);
        if (! $user instanceof User || $surface === null || $user->disabled_at !== null
            || ($surface === Surface::Customer) !== ($user->account_type === User::CUSTOMER)) {
            if ($user !== null) {
                $this->sessions->end($request);
            }
            throw new AuthenticationException;
        }
        if ($mode !== 'session' && $surface !== Surface::Customer) {
            throw new ApiProblem(403, 'FORBIDDEN', 'Forbidden');
        }
        if ($mode === 'customer' && $user->email_verified_at === null) {
            throw new ApiProblem(403, 'EMAIL_NOT_VERIFIED', 'Email address is not verified');
        }

        return $next($request);
    }
}
