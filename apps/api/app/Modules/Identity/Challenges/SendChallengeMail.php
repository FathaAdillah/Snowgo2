<?php

namespace App\Modules\Identity\Challenges;

use App\Models\User;
use App\Modules\Identity\Mail\ResetPassword;
use App\Modules\Identity\Mail\StaffEmailChange;
use App\Modules\Identity\Mail\StaffInvitation;
use App\Modules\Identity\Mail\VerifyEmail;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Query\Builder;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Throwable;

final class SendChallengeMail implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    /** @var list<int> */
    public array $backoff = [10, 60, 300, 900];

    /** Challenges issued after this instant belong to a newer delivery job. */
    public readonly string $issuedBy;

    public function __construct(public readonly string $event, public readonly int $userId)
    {
        $this->issuedBy = now()->format('Y-m-d H:i:s.uP');
        $this->onQueue('notifications');
    }

    public function handle(): void
    {
        $user = User::query()->whereKey($this->userId)->whereNull('disabled_at')->first();
        $challenge = $user ? $this->active($this->challenge($user))->whereNotNull('delivery_ciphertext')->first() : null;
        if ($user === null || $challenge === null) {
            $this->failed(null);

            return;
        }
        $token = Crypt::decryptString($challenge->delivery_ciphertext);
        Mail::to($this->event === 'StaffEmailChangeRequested' ? $challenge->email : $user->email)->send(match ($this->event) {
            'StaffEmailChangeRequested' => new StaffEmailChange($token),
            'PasswordResetRequested' => new ResetPassword($user->email, $token),
            'StaffInvited' => new StaffInvitation($token),
            default => new VerifyEmail($token),
        });
        // A newer challenge keeps its own ciphertext for its own delivery.
        $this->challenge($user)->where('delivery_ciphertext', $challenge->delivery_ciphertext)->update(['delivery_ciphertext' => null]);
    }

    /** Remove this job's challenge material once delivery is final, successful, failed or expired. */
    public function failed(?Throwable $exception): void
    {
        $user = User::query()->find($this->userId);
        if ($user !== null) {
            $this->challenge($user)->where('created_at', '<=', $this->issuedBy)->update(['delivery_ciphertext' => null]);
        }
    }

    private function isReset(): bool
    {
        return $this->event === 'PasswordResetRequested';
    }

    private function challenge(User $user): Builder
    {
        return match ($this->event) {
            'PasswordResetRequested' => DB::table('password_reset_tokens')->where('email', $user->email),
            'StaffInvited' => DB::table('staff_invitations')->where('user_id', $user->id),
            'StaffEmailChangeRequested' => DB::table('staff_email_changes')->where('user_id', $user->id)->where('session_version', $user->staff_session_version),
            default => DB::table('email_verification_tokens')->where('user_id', $user->id),
        };
    }

    private function active(Builder $challenge): Builder
    {
        return $this->isReset()
            ? $challenge->where('created_at', '>', now()->subMinutes((int) config('auth.passwords.users.expire')))
            : $challenge->where('expires_at', '>', now());
    }
}
