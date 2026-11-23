<?php

namespace App\Modules\Identity\Staff;

use App\Models\User;
use App\Modules\Foundation\Audit\Journal;
use App\Modules\Foundation\Audit\Subject;
use App\Modules\Foundation\Outbox\Outbox;
use App\Modules\Identity\Challenges\EmailVerifications;
use App\Modules\Identity\Sessions\DeviceSessions;
use App\Modules\Identity\Surface;
use App\Support\Http\ApiProblem;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Laravel\Fortify\RecoveryCode;
use PragmaRX\Google2FA\Google2FA;

final class StaffMfa
{
    public function __construct(private readonly Google2FA $totp, private readonly DeviceSessions $sessions) {}

    /** @return array{secret:string,otpauth_uri:string,expires_at:string} */
    public function enroll(Request $request, string $token, string $password): array
    {
        $hash = hash('sha256', $token);
        $invitation = $this->invitation($hash)->join('users', 'users.id', '=', 'staff_invitations.user_id')->whereNull('users.disabled_at')
            ->first(['users.email', 'staff_invitations.expires_at']) ?? throw EmailVerifications::invalid();
        $secret = $this->totp->generateSecretKey(32);
        $request->session()->regenerate(true);
        $request->session()->put('staff.enroll', [
            'token' => $hash, 'secret' => Crypt::encryptString($secret), 'password' => Hash::make($password),
        ]);

        return [
            'secret' => $secret,
            'otpauth_uri' => $this->totp->getQRCodeUrl('Snowego Admin', $invitation->email, $secret),
            'expires_at' => Carbon::parse($invitation->expires_at)->toISOString(),
        ];
    }

    /** @return list<string> */
    public function confirm(Request $request, string $token, string $code): array
    {
        $hash = hash('sha256', $token);
        $pending = $request->session()->get('staff.enroll');
        if (! $pending || ! hash_equals($pending['token'], $hash)) {
            throw EmailVerifications::invalid();
        }
        $codes = DB::transaction(function () use ($hash, $pending, $code) {
            $id = $this->invitation($hash)->value('user_id') ?? throw EmailVerifications::invalid();
            $user = User::query()->lockForUpdate()->findOrFail($id);
            if ($user->account_type !== User::STAFF || $user->disabled_at || $user->two_factor_confirmed_at
                || ! DB::table('staff_invitations')->where('user_id', $id)->where('token_hash', $hash)->where('expires_at', '>', now())->delete()) {
                throw EmailVerifications::invalid();
            }
            $user->two_factor_secret = Crypt::decryptString($pending['secret']);
            $this->consumeTotp($user, $code);
            $codes = array_map(static fn () => RecoveryCode::generate(), range(1, 8));
            $user->forceFill(['password_hash' => $pending['password'], 'email_verified_at' => now(), 'two_factor_confirmed_at' => now(),
                'two_factor_recovery_codes' => array_map(static fn ($v) => hash('sha256', $v), $codes)])->save();
            $this->record($user, 'StaffMfaEnrolled');

            return $codes;
        });
        $request->session()->forget('staff.enroll');

        return $codes;
    }

    private function invitation(string $hash): Builder
    {
        return DB::table('staff_invitations')->where('token_hash', $hash)->where('expires_at', '>', now());
    }

    public function begin(Request $request, string $email, string $password): ?string
    {
        $request->session()->forget('staff.challenge');
        $guard = Auth::guard('web');
        if (! $guard->validate(['email' => $email, 'password' => $password, 'account_type' => User::STAFF,
            'disabled_at' => null, fn ($query) => $query->whereNotNull('two_factor_confirmed_at')])) {
            throw new ApiProblem(401, 'BAD_CREDENTIALS', 'Invalid email or password');
        }
        $user = $guard->getLastAttempted();
        assert($user instanceof User);
        $this->sessions->end($request);
        if (StaffAccess::passwordOnly($user)) {
            DB::transaction(fn () => $this->record($user, 'StaffSignedIn'));
            $this->startSession($user, $request, true);

            return null;
        }
        $expires = now()->addMinutes(5);
        $request->session()->put('staff.challenge', ['user' => $user->id, 'expires' => $expires->timestamp,
            'version' => $user->staff_session_version, 'credential' => hash('sha256', (string) $user->password_hash)]);

        return $expires->toISOString();
    }

    public function login(Request $request, string $code): User
    {
        $challenge = $request->session()->get('staff.challenge');
        if (! $challenge || $challenge['expires'] <= now()->timestamp) {
            throw EmailVerifications::invalid();
        }
        $user = DB::transaction(function () use ($challenge, $code) {
            $user = User::query()->where('account_type', User::STAFF)->whereNull('disabled_at')->whereNotNull('two_factor_confirmed_at')
                ->lockForUpdate()->find($challenge['user']);
            if (! $user || $challenge['version'] !== $user->staff_session_version || ! hash_equals($challenge['credential'], hash('sha256', (string) $user->password_hash))) {
                throw EmailVerifications::invalid();
            }
            if (preg_match('/^\d{6}$/D', $code)) {
                $this->consumeTotp($user, $code);
            } else {
                $codes = $user->two_factor_recovery_codes ?? [];
                $key = array_search(hash('sha256', $code), $codes, true);
                if ($key === false) {
                    throw EmailVerifications::invalid();
                }
                unset($codes[$key]);
                $user->two_factor_recovery_codes = array_values($codes);
            }
            $user->save();
            $this->record($user, 'StaffSignedIn');

            return $user;
        });
        $request->session()->forget('staff.challenge');
        $this->startSession($user, $request, false);

        return $user;
    }

    private function startSession(User $user, Request $request, bool $passwordOnly): void
    {
        Auth::guard('web')->login($user);
        $this->sessions->start($request, Surface::Staff);
        // Only MFA-authenticated sessions may carry the version key accepted by older releases.
        $versionKey = $passwordOnly ? StaffAccess::PASSWORD_ONLY_VERSION : 'staff.version';
        $request->session()->put([$versionKey => $user->staff_session_version, 'staff.started' => now()->timestamp, 'staff.seen' => now()->timestamp]);
    }

    public function stepUp(Request $request, string $password, string $code): void
    {
        DB::transaction(function () use ($request, $password, $code) {
            $user = User::query()->whereNull('disabled_at')->lockForUpdate()->findOrFail($request->user()?->getAuthIdentifier());
            $this->verifyCredentials($user, $password, $code);
            $this->record($user, 'StaffReauthenticated');
        });
        $request->session()->put('staff.step_up', now()->timestamp);
    }

    public function verifyCredentials(User $user, string $password, string $code): void
    {
        if (! Hash::check($password, $user->password_hash)) {
            throw EmailVerifications::invalid();
        }
        $this->consumeTotp($user, $code);
        $user->save();
    }

    private function consumeTotp(User $user, string $code): void
    {
        $step = $this->totp->verifyKeyNewer((string) $user->two_factor_secret, $code, $user->two_factor_last_step ?? 0, 1, intdiv(now()->timestamp, 30));
        if (! is_int($step)) {
            throw EmailVerifications::invalid();
        }
        $user->two_factor_last_step = $step;
    }

    private function record(User $user, string $event): void
    {
        app(Journal::class)->audit($event, Subject::User, $user->id, $user->id);
        app(Outbox::class)->record($event, 'user', $user->id, []);
    }
}
