<?php

namespace App\Modules\Identity\Accounts;

use App\Models\User;
use App\Modules\Foundation\Audit\Journal;
use App\Modules\Foundation\Audit\Subject;
use App\Modules\Identity\Challenges\EmailVerifications;
use App\Modules\Identity\Contracts\CustomerDirectory;
use App\Modules\Identity\Sessions\DeviceSessions;
use App\Modules\Identity\Surface;
use App\Support\Http\ApiProblem;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;

final class CustomerAccounts
{
    private const KEY_FAILURES = 5;

    private const IP_FAILURES = 30;

    public function __construct(
        private readonly CustomerDirectory $directory,
        private readonly EmailVerifications $verifications,
        private readonly DeviceSessions $sessions,
        private readonly Journal $journal,
    ) {}

    /** An existing address gets the same acknowledgement and no second account. */
    public function register(string $displayName, string $email, string $password): void
    {
        $hash = Hash::make($password);
        try {
            DB::transaction(function () use ($displayName, $email, $hash) {
                if (User::query()->where('email', $email)->exists()) {
                    return;
                }
                $user = new User;
                $user->forceFill(['email' => $email, 'password_hash' => $hash, 'account_type' => User::CUSTOMER])->save();
                $this->directory->open($user->id, $displayName);
                $this->journal->audit('identity.customer_registered', Subject::User, $user->id, $user->id);
                $this->verifications->issue($user);
            });
        } catch (UniqueConstraintViolationException) {
            // A concurrent registration for the same address won.
        }
    }

    public function login(Request $request, string $email, string $password): User
    {
        $limits = [
            'identity-login:'.hash('sha256', mb_strtolower($email).'|'.$request->ip()) => self::KEY_FAILURES,
            'identity-login-ip:'.$request->ip() => self::IP_FAILURES,
        ];
        foreach ($limits as $key => $max) {
            if (RateLimiter::tooManyAttempts($key, $max)) {
                throw new ThrottleRequestsException('Too many requests', headers: ['Retry-After' => RateLimiter::availableIn($key)]);
            }
        }
        $guard = Auth::guard('web');
        $credentials = ['email' => $email, 'password' => $password, 'account_type' => User::CUSTOMER, 'disabled_at' => null];
        if (! $guard->attempt($credentials)) {
            foreach (array_keys($limits) as $key) {
                RateLimiter::hit($key, 60);
            }
            throw new ApiProblem(401, 'BAD_CREDENTIALS', 'Invalid email or password');
        }
        RateLimiter::clear(array_key_first($limits));
        $this->sessions->start($request, Surface::Customer);
        $user = $guard->user();
        assert($user instanceof User);

        return $user;
    }
}
