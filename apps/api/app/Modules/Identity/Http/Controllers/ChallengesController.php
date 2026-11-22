<?php

namespace App\Modules\Identity\Http\Controllers;

use App\Models\User;
use App\Modules\Identity\Challenges\EmailVerifications;
use App\Modules\Identity\Challenges\PasswordResets;
use App\Modules\Identity\Sessions\DeviceSessions;
use App\Support\Http\ApiResponse;
use Dedoc\Scramble\Attributes\HeaderParameter;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rules\Password;

#[HeaderParameter('X-XSRF-TOKEN', required: true, type: 'string')]
final class ChallengesController
{
    /**
     * Consume an email verification challenge once.
     */
    #[Response(200, 'Email verified', type: AuthController::ACK)]
    #[Response(422, 'Invalid or expired challenge', type: AuthController::ERROR)]
    #[Response(429, 'Throttled', type: AuthController::ERROR)]
    public function verify(Request $request, EmailVerifications $verifications): JsonResponse
    {
        $data = $request->validate(['token' => 'required|string|max:128']);
        $verifications->consume($data['token']);

        return ApiResponse::success(null, 'Email verified');
    }

    /**
     * Send a new verification email to the signed-in customer if still unverified.
     */
    #[Response(200, 'Queued when still unverified', type: AuthController::ACK)]
    #[Response(401, 'Unauthenticated', type: AuthController::ERROR)]
    #[Response(429, 'Throttled', type: AuthController::ERROR)]
    public function requestVerification(Request $request, EmailVerifications $verifications): JsonResponse
    {
        $user = $request->user();
        assert($user instanceof User);
        $verifications->resend($user);

        return ApiResponse::success(null, 'Check your inbox to verify the email address');
    }

    /**
     * Request a password reset email; the response does not reveal whether the address exists.
     */
    #[Response(200, 'Uniform acknowledgement', type: AuthController::ACK)]
    #[Response(429, 'Throttled', type: AuthController::ERROR)]
    public function requestReset(Request $request, PasswordResets $resets): JsonResponse
    {
        $data = $request->validate(['email' => 'required|string|email|max:254']);
        $resets->request($data['email']);

        return ApiResponse::success(null, 'If the address belongs to an account, a reset link is on its way');
    }

    /**
     * Reset the password with a single-use challenge and revoke every session of the account.
     */
    #[Response(200, 'Password changed', type: AuthController::ACK)]
    #[Response(422, 'Invalid or expired challenge', type: AuthController::ERROR)]
    #[Response(429, 'Throttled', type: AuthController::ERROR)]
    public function reset(Request $request, PasswordResets $resets, DeviceSessions $sessions): JsonResponse
    {
        $data = $request->validate([
            'email' => 'required|string|email|max:254',
            'token' => 'required|string|max:128',
            'password' => ['required', 'string', Password::min(12)->max(128)],
        ]);
        $resets->reset($data['email'], $data['token'], $data['password']);
        $sessions->end($request);

        return ApiResponse::success(null, 'Password changed; sign in again');
    }
}
