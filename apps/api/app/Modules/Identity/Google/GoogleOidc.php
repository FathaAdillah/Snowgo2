<?php

namespace App\Modules\Identity\Google;

use App\Modules\Identity\Challenges\EmailVerifications;
use Exception;
use Illuminate\Http\RedirectResponse;
use Laravel\Socialite\Two\GoogleProvider;

class GoogleOidc extends GoogleProvider
{
    public function begin(): RedirectResponse
    {
        $this->request->session()->put('google.expires', now()->addMinutes(10)->timestamp);

        return $this->redirect();
    }

    /** @return array{subject:string,email:string,name:string} */
    public function identity(): array
    {
        $validState = ! $this->hasInvalidState();
        $expires = $this->request->session()->pull('google.expires', 0);
        $code = $this->request->query('code');
        if (! $validState || $expires <= now()->timestamp || ! is_string($code) || $code === '' || $this->request->has('error')) {
            throw EmailVerifications::invalid();
        }
        try {
            $jwt = $this->getAccessTokenResponse($code)['id_token'] ?? null;
            // Do not fall back to userinfo/access-token claims. Socialite verifies JWKS, issuer and audience.
            $claims = is_string($jwt) ? $this->getUserFromJwtToken($jwt) : [];
        } catch (Exception) {
            throw EmailVerifications::invalid();
        }
        $sub = $claims['sub'] ?? null;
        $email = $claims['email'] ?? null;
        if (! is_string($sub) || $sub === '' || strlen($sub) > 255
            || ! is_string($email) || strlen($email) > 254 || ! filter_var($email, FILTER_VALIDATE_EMAIL)
            || ($claims['email_verified'] ?? null) !== true || ($claims['exp'] ?? 0) <= now()->timestamp) {
            throw EmailVerifications::invalid();
        }

        return ['subject' => $sub, 'email' => $email, 'name' => mb_substr((string) ($claims['name'] ?? $email), 0, 120)];
    }
}
