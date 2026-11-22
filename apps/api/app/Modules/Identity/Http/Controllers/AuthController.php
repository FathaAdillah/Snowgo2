<?php

namespace App\Modules\Identity\Http\Controllers;

use App\Models\User;
use App\Modules\Identity\Accounts\CustomerAccounts;
use App\Modules\Identity\Contracts\CustomerDirectory;
use App\Modules\Identity\Sessions\DeviceSessions;
use App\Support\Http\ApiResponse;
use Dedoc\Scramble\Attributes\HeaderParameter;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rules\Password;

final class AuthController
{
    public const ACK = 'array{success:bool,message:string,data:null,meta:array{request_id:string}}';

    public const ERROR = 'array{success:false,code:string,message:string,meta:array{request_id:string}}';

    private const ME = 'array{success:bool,message:string,data:array{surface:string,display_name:string,email:string,email_verified:bool},meta:array{request_id:string}}';

    public function __construct(private readonly CustomerDirectory $directory) {}

    /**
     * Register a customer; the response is the same for an address that already has an account.
     */
    #[HeaderParameter('X-XSRF-TOKEN', required: true, type: 'string')]
    #[Response(200, 'Registration accepted', type: self::ACK)]
    #[Response(419, 'CSRF token mismatch', type: self::ERROR)]
    #[Response(429, 'Throttled', type: self::ERROR)]
    public function register(Request $request, CustomerAccounts $accounts): JsonResponse
    {
        $data = $request->validate([
            'name' => 'required|string|max:120',
            'email' => 'required|string|email|max:254',
            'password' => ['required', 'string', Password::min(12)->max(128)],
        ]);
        $accounts->register($data['name'], $data['email'], $data['password']);

        return ApiResponse::success(null, 'Check your inbox to verify the email address');
    }

    /**
     * Sign in a customer and start a new CUSTOMER session.
     */
    #[HeaderParameter('X-XSRF-TOKEN', required: true, type: 'string')]
    #[Response(200, 'Signed in', type: self::ME)]
    #[Response(401, 'Bad credentials', type: self::ERROR)]
    #[Response(419, 'CSRF token mismatch', type: self::ERROR)]
    #[Response(429, 'Throttled', type: self::ERROR)]
    public function login(Request $request, CustomerAccounts $accounts): JsonResponse
    {
        $data = $request->validate(['email' => 'required|string|email|max:254', 'password' => 'required|string|max:128']);

        return $this->identity($accounts->login($request, $data['email'], $data['password']), $request);
    }

    /**
     * The identity behind the current session.
     */
    #[Response(200, 'Current session', type: self::ME)]
    #[Response(401, 'Unauthenticated', type: self::ERROR)]
    public function me(Request $request): JsonResponse
    {
        $user = $request->user();
        assert($user instanceof User);

        return $this->identity($user, $request);
    }

    /**
     * End the current session and rotate the CSRF token.
     */
    #[HeaderParameter('X-XSRF-TOKEN', required: true, type: 'string')]
    #[Response(200, 'Signed out', type: self::ACK)]
    #[Response(401, 'Unauthenticated', type: self::ERROR)]
    public function logout(Request $request, DeviceSessions $sessions): JsonResponse
    {
        $sessions->end($request);

        return ApiResponse::success(null, 'Signed out');
    }

    private function identity(User $user, Request $request): JsonResponse
    {
        return ApiResponse::success([
            'surface' => DeviceSessions::surface($request)?->value,
            'display_name' => $this->directory->displayName($user->id) ?? $user->email,
            'email' => $user->email,
            'email_verified' => $user->email_verified_at !== null,
        ])->header('Cache-Control', 'private, no-store');
    }
}
