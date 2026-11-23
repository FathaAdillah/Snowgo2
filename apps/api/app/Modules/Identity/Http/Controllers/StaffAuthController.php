<?php

namespace App\Modules\Identity\Http\Controllers;

use App\Models\User;
use App\Modules\Identity\Staff\StaffAccess;
use App\Modules\Identity\Staff\StaffMfa;
use App\Support\Http\ApiResponse;
use Dedoc\Scramble\Attributes\HeaderParameter;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class StaffAuthController
{
    private const ME = 'array{success:bool,message:string,data:array{id:int,surface:string,display_name:string,email:string,login_mfa_required:bool,roles:list<string>,permissions:list<string>},meta:array{request_id:string}}';

    #[HeaderParameter('X-XSRF-TOKEN', required: true, type: 'string')]
    #[Response(200, 'Provision authenticator for the invited account', type: 'array{success:bool,message:string,data:array{otpauth_uri:string,expires_at:string,secret:string},meta:array{request_id:string}}')]
    public function enroll(Request $request, StaffMfa $mfa): JsonResponse
    {
        $data = $request->validate(['token' => 'required|string|size:64', 'password' => 'required|string|min:12|max:128']);

        return ApiResponse::success($mfa->enroll($request, $data['token'], $data['password']));
    }

    #[HeaderParameter('X-XSRF-TOKEN', required: true, type: 'string')]
    #[Response(200, 'Recovery codes displayed only once', type: 'array{success:bool,message:string,data:array{recovery_codes:list<string>},meta:array{request_id:string}}')]
    public function confirm(Request $request, StaffMfa $mfa): JsonResponse
    {
        $data = $request->validate(['token' => 'required|string|size:64', 'code' => 'required|string|digits:6']);

        return ApiResponse::success(['recovery_codes' => $mfa->confirm($request, $data['token'], $data['code'])]);
    }

    #[HeaderParameter('X-XSRF-TOKEN', required: true, type: 'string')]
    #[Response(200, 'Password accepted; check whether an MFA challenge is required', type: 'array{success:bool,message:string,data:array{challenge_required:bool,expires_at:string|null},meta:array{request_id:string}}')]
    public function login(Request $request, StaffMfa $mfa): JsonResponse
    {
        $data = $request->validate(['email' => 'required|email|max:254', 'password' => 'required|string|max:128']);

        $expiry = $mfa->begin($request, $data['email'], $data['password']);

        return ApiResponse::success(['challenge_required' => $expiry !== null, 'expires_at' => $expiry]);
    }

    #[HeaderParameter('X-XSRF-TOKEN', required: true, type: 'string')]
    #[Response(200, 'Staff session established', type: self::ME)]
    public function verify(Request $request, StaffMfa $mfa): JsonResponse
    {
        $data = $request->validate(['code' => 'required|string|max:64']);

        return $this->identity($mfa->login($request, $data['code']));
    }

    #[HeaderParameter('X-XSRF-TOKEN', required: true, type: 'string')]
    #[Response(200, 'Staff reauthenticated', type: AuthController::ACK)]
    public function stepUp(Request $request, StaffMfa $mfa): JsonResponse
    {
        $data = $request->validate(['password' => 'required|string|max:128', 'code' => 'required|string|digits:6']);
        $mfa->stepUp($request, $data['password'], $data['code']);

        return ApiResponse::success(null);
    }

    #[Response(200, 'Current staff session and effective grants', type: self::ME)]
    public function me(Request $request): JsonResponse
    {
        $user = $request->user();
        assert($user instanceof User);

        return $this->identity($user);
    }

    private function identity(User $user): JsonResponse
    {
        $permissions = StaffAccess::permissions($user);
        if (! app()->environment('local')) {
            $permissions = array_values(array_diff($permissions, [StaffAccess::LOGS, StaffAccess::DB]));
        }

        return ApiResponse::success(['id' => $user->id, 'surface' => User::STAFF, 'display_name' => $user->getAttribute('staff_display_name') ?? $user->email,
            'email' => $user->email, 'login_mfa_required' => ! StaffAccess::passwordOnly($user), 'roles' => $user->getRoleNames()->values(), 'permissions' => $permissions]);
    }
}
