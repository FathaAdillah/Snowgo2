<?php

namespace App\Modules\Identity\Challenges;

use App\Models\User;
use App\Modules\Foundation\Audit\Journal;
use App\Modules\Foundation\Audit\Subject;
use App\Modules\Foundation\Outbox\Outbox;
use App\Modules\Identity\Sessions\DeviceSessions;
use Illuminate\Auth\Passwords\PasswordBroker;
use Illuminate\Auth\Passwords\PasswordBrokerManager;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Support\Timebox;

final class PasswordResets
{
    private const UNIFORM_MICROSECONDS = 400_000;

    public function __construct(
        private readonly PasswordBrokerManager $brokers,
        private readonly Outbox $outbox,
        private readonly Journal $journal,
        private readonly DeviceSessions $sessions,
    ) {}

    /** Same response and similar latency whether or not the address is an eligible customer. */
    public function request(string $email): void
    {
        (new Timebox)->call(function () use ($email) {
            $broker = $this->broker();
            $user = User::query()->where('email', $email)->where('account_type', User::CUSTOMER)->whereNull('disabled_at')->first();
            if ($user === null || $broker->getRepository()->recentlyCreatedToken($user)) {
                return;
            }
            DB::transaction(function () use ($broker, $user) {
                $token = $broker->createToken($user);
                DB::table('password_reset_tokens')->where('email', $user->email)
                    ->update(['delivery_ciphertext' => Crypt::encryptString($token)]);
                $this->outbox->record('PasswordResetRequested', 'user', $user->id, []);
            });
        }, self::UNIFORM_MICROSECONDS);
    }

    public function reset(string $email, string $token, string $password): void
    {
        $credentials = ['email' => $email, 'token' => $token, 'password' => $password,
            'account_type' => User::CUSTOMER, 'disabled_at' => null];
        $status = DB::transaction(fn () => $this->broker()->reset($credentials, function (User $user, string $password) {
            // Completing the emailed challenge also proves ownership of the address.
            $user->forceFill(['password_hash' => $password, 'email_verified_at' => $user->email_verified_at ?? now(),
                'remember_token' => Str::random(60)])->save();
            $this->sessions->revokeAll($user->id);
            $this->journal->audit('identity.password_reset', Subject::User, $user->id, $user->id);
            $this->outbox->record('PasswordReset', 'user', $user->id, []);
        }));
        if ($status !== PasswordBroker::PASSWORD_RESET) {
            throw EmailVerifications::invalid();
        }
    }

    private function broker(): PasswordBroker
    {
        $broker = $this->brokers->broker();
        assert($broker instanceof PasswordBroker);

        return $broker;
    }
}
