<?php

namespace App\Modules\Identity;

use App\Models\User;
use App\Modules\Identity\Google\GoogleOidc;
use App\Modules\Identity\Sessions\DeviceSessions;
use App\Modules\Identity\Sessions\SurfaceSessionHandler;
use App\Modules\Identity\Staff\StaffAccess;
use App\Support\Http\ApiProblem;
use App\Support\Modules\ModuleServiceProvider;
use Illuminate\Auth\Access\Response;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Session;
use Laravel\Fortify\Fortify;

final class IdentityServiceProvider extends ModuleServiceProvider
{
    public function register(): void
    {
        Fortify::ignoreRoutes();
        $this->app->bind(GoogleOidc::class, function () {
            $config = config('services.google');
            if (empty($config['client_id']) || empty($config['client_secret'])) {
                throw new ApiProblem(503, 'OAUTH_UNAVAILABLE', 'Google login is not configured');
            }

            return (new GoogleOidc(request(), $config['client_id'], $config['client_secret'], $config['redirect'],
                ['timeout' => 6, 'connect_timeout' => 3]))->enablePKCE();
        });
    }

    public function boot(): void
    {
        parent::boot();

        Session::extend('database', fn (Application $app) => new SurfaceSessionHandler(
            $app->make('db')->connection(config('session.connection')),
            (string) config('session.table'), (int) config('session.lifetime'), $app,
        ));

        RateLimiter::for('identity-challenges', function (Request $request) {
            $email = mb_strtolower((string) $request->input('email'));

            return array_filter([
                Limit::perMinute(10)->by('ip:'.$request->ip()),
                $email === '' ? null : Limit::perMinute(5)->by('email:'.hash('sha256', $email)),
            ]);
        });
        RateLimiter::for('identity-resend', fn (Request $request) => [
            Limit::perMinute(1)->by('minute:'.$request->user()?->getAuthIdentifier()),
            Limit::perHour(6)->by('hour:'.$request->user()?->getAuthIdentifier()),
        ]);

        Gate::before(static fn (User $user, string $ability, array $arguments) => $arguments === [] && str_contains($ability, '.') && ! Gate::has($ability)
            ? StaffAccess::check($user, $ability) : null);
        Gate::define('files.surface.staff', static function (User $user) {
            if (! StaffAccess::active($user, request())) {
                return false;
            }
            request()->session()->put('staff.seen', now()->timestamp);

            return true;
        });
        Gate::define('files.surface.customer', function (User $user) {
            if ($user->disabled_at !== null || $user->account_type !== User::CUSTOMER
                || DeviceSessions::surface(request()) !== Surface::Customer) {
                return Response::deny();
            }

            return $user->email_verified_at === null
                ? Response::deny('Email address is not verified', 'EMAIL_NOT_VERIFIED')
                : Response::allow();
        });
    }
}
