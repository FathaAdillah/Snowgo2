<?php

namespace App\Modules\Identity\Http\Controllers;

use App\Models\User;
use App\Modules\Identity\Challenges\EmailVerifications;
use App\Modules\Identity\Google\GoogleAccounts;
use App\Modules\Identity\Google\GoogleOidc;
use App\Modules\Identity\Sessions\DeviceSessions;
use App\Modules\Identity\Surface;
use Dedoc\Scramble\Attributes\HeaderParameter;
use Dedoc\Scramble\Attributes\QueryParameter;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

final class GoogleController
{
    #[Response(302, 'Redirect to Google', mediaType: 'text/html', type: 'string')]
    public function redirect(GoogleOidc $google): RedirectResponse
    {
        return $google->begin()->header('Cache-Control', 'no-store');
    }

    #[QueryParameter('state', type: 'string', required: true)]
    #[QueryParameter('code', type: 'string')]
    #[QueryParameter('error', type: 'string')]
    #[Response(302, 'Return to storefront; a collision requires confirmation', mediaType: 'text/html', type: 'string')]
    #[Response(422, 'Invalid or expired challenge', type: AuthController::ERROR)]
    #[Response(409, 'Identity conflict', type: AuthController::ERROR)]
    public function callback(Request $request, GoogleOidc $google, GoogleAccounts $accounts, DeviceSessions $sessions): RedirectResponse
    {
        $result = $accounts->resolve($google->identity(), Auth::guard('web')->id());
        $url = rtrim((string) config('app.url'), '/').'/auth/google';
        if ($result instanceof User) {
            Auth::guard('web')->login($result);
            $sessions->start($request, Surface::Customer);
        } else {
            $request->session()->put('google.link', hash('sha256', $result));
            $url .= '#link='.$result;
        }

        return redirect()->away($url)->header('Cache-Control', 'no-store')->header('Referrer-Policy', 'no-referrer');
    }

    #[HeaderParameter('X-XSRF-TOKEN', required: true, type: 'string')]
    #[Response(200, 'Google account linked', type: AuthController::ME)]
    #[Response(422, 'Invalid or expired challenge', type: AuthController::ERROR)]
    #[Response(409, 'Identity conflict', type: AuthController::ERROR)]
    public function confirm(Request $request, GoogleAccounts $accounts, AuthController $auth): JsonResponse
    {
        $data = $request->validate(['token' => 'required|string|size:64', 'password' => 'required|string|max:128']);
        $hash = hash('sha256', $data['token']);
        if (! hash_equals((string) $request->session()->get('google.link'), $hash)) {
            throw EmailVerifications::invalid();
        }
        $user = $request->user();
        assert($user instanceof User);
        $user = $accounts->confirm($user->id, $hash, $data['password']);
        $request->session()->forget('google.link');
        Auth::guard('web')->login($user);

        return $auth->me($request);
    }
}
