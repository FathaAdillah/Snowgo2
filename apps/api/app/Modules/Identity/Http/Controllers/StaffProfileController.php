<?php

namespace App\Modules\Identity\Http\Controllers;

use App\Models\User;
use App\Modules\Foundation\Audit\Journal;
use App\Modules\Foundation\Audit\Subject;
use App\Modules\Foundation\Outbox\Outbox;
use App\Modules\Identity\Challenges\EmailVerifications;
use App\Modules\Identity\Sessions\DeviceSessions;
use App\Modules\Identity\Staff\StaffAccess;
use App\Modules\Identity\Staff\StaffMfa;
use App\Support\Http\ApiProblem;
use App\Support\Http\ApiResponse;
use Closure;
use Dedoc\Scramble\Attributes\HeaderParameter;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

final class StaffProfileController
{
    public const PROOF = ['current_password' => 'required|string|max:128', 'code' => 'required|string|digits:6'];

    public function __construct(private StaffMfa $mfa, private DeviceSessions $sessions) {}

    #[HeaderParameter('X-XSRF-TOKEN', required: true, type: 'string')]
    #[Response(200, 'Display name updated', type: AuthController::ACK)]
    public function name(Request $request): JsonResponse
    {
        $data = $request->validate(['display_name' => 'required|string|max:100']);

        return $this->change($request, 'update', 'StaffDisplayNameChanged', fn (User $user) => $user->forceFill(['staff_display_name' => $data['display_name']])->save());
    }

    #[HeaderParameter('X-XSRF-TOKEN', required: true, type: 'string')]
    #[Response(200, 'Password changed; sign in again', type: AuthController::ACK)]
    public function password(Request $request): JsonResponse
    {
        $data = $request->validate([...self::PROOF, 'password' => 'required|string|min:12|max:128|different:current_password|confirmed']);

        return $this->change($request, 'change_password', 'StaffPasswordChanged', function (User $user) use ($data) {
            $this->mfa->verifyCredentials($user, $data['current_password'], $data['code']);
            $user->forceFill(['password_hash' => Hash::make($data['password'])])->save();
        }, true);
    }

    #[HeaderParameter('X-XSRF-TOKEN', required: true, type: 'string')]
    #[Response(200, 'Login MFA preference changed; sign in again', type: AuthController::ACK)]
    public function mfa(Request $request): JsonResponse
    {
        $data = $request->validate([...self::PROOF, 'enabled' => 'required|boolean']);

        return $this->change($request, 'manage_mfa', $data['enabled'] ? 'StaffLoginMfaEnabled' : 'StaffLoginMfaDisabled', function (User $user) use ($data) {
            abort_if(StaffAccess::super($user), 403);
            $this->mfa->verifyCredentials($user, $data['current_password'], $data['code']);
            $user->forceFill(['staff_password_only' => ! $data['enabled']])->save();
        }, true);
    }

    #[HeaderParameter('X-XSRF-TOKEN', required: true, type: 'string')]
    #[Response(200, 'Verification code queued for the new address', type: AuthController::ACK)]
    public function email(Request $request): JsonResponse
    {
        $data = $request->validate([...self::PROOF, 'email' => 'required|string|email|max:254']);

        return $this->change($request, 'change_email', 'StaffEmailChangeRequested', function (User $user) use ($data) {
            $this->mfa->verifyCredentials($user, $data['current_password'], $data['code']);
            $this->ensureAvailable($data['email']);
            $token = Str::random(24);
            DB::table('staff_email_changes')->updateOrInsert(['user_id' => $user->id], [
                'email' => $data['email'], 'session_version' => $user->staff_session_version,
                'token_hash' => hash('sha256', $token), 'delivery_ciphertext' => Crypt::encryptString($token),
                'created_at' => now()->format('Y-m-d H:i:s.uP'), 'expires_at' => now()->addMinutes(15),
            ]);
        });
    }

    #[HeaderParameter('X-XSRF-TOKEN', required: true, type: 'string')]
    #[Response(200, 'New login email confirmed; sign in again', type: AuthController::ACK)]
    public function confirmEmail(Request $request): JsonResponse
    {
        $data = $request->validate(['token' => 'required|string|size:24']);
        try {
            return $this->change($request, 'change_email', 'StaffEmailChanged', function (User $user) use ($data) {
                $change = DB::table('staff_email_changes')->where('user_id', $user->id)->where('expires_at', '>', now())
                    ->where('session_version', $user->staff_session_version)->where('token_hash', hash('sha256', $data['token']))->first()
                    ?? throw EmailVerifications::invalid();
                $this->ensureAvailable($change->email);
                $user->forceFill(['email' => $change->email, 'email_verified_at' => now()])->save();
            }, true);
        } catch (UniqueConstraintViolationException) {
            throw $this->conflict();
        }
    }

    private function ensureAvailable(string $email): void
    {
        if (User::query()->where('email', $email)->exists()) {
            throw $this->conflict();
        }
    }

    private function conflict(): ApiProblem
    {
        return new ApiProblem(409, 'IDENTITY_CONFLICT', 'Alamat email tidak tersedia.');
    }

    /** @param Closure(User):mixed $apply */
    private function change(Request $request, string $action, string $event, Closure $apply, bool $signOut = false): JsonResponse
    {
        DB::transaction(function () use ($request, $action, $event, $apply, $signOut) {
            $user = User::query()->lockForUpdate()->findOrFail($request->user()?->getAuthIdentifier());
            abort_unless(StaffAccess::active($user, $request) && $user->can('admin.profile.view') && $user->can('admin.profile.'.$action), 403);
            $apply($user);
            if ($signOut) {
                $user->increment('staff_session_version');
                $this->sessions->revokeAll($user->id);
                DB::table('staff_email_changes')->where('user_id', $user->id)->delete();
            }
            app(Journal::class)->audit($event, Subject::User, $user->id, $user->id);
            app(Outbox::class)->record($event, 'user', $user->id, []);
        });
        if ($signOut) {
            $this->sessions->end($request);
        }

        return ApiResponse::success();
    }
}
