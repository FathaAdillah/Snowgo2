<?php

namespace App\Modules\Identity\Google;

use App\Models\User;
use App\Modules\Foundation\Audit\Journal;
use App\Modules\Foundation\Audit\Subject;
use App\Modules\Foundation\Outbox\Outbox;
use App\Modules\Identity\Challenges\EmailVerifications;
use App\Modules\Identity\Contracts\CustomerDirectory;
use App\Support\Http\ApiProblem;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

final class GoogleAccounts
{
    public function __construct(private readonly CustomerDirectory $directory, private readonly Outbox $outbox, private readonly Journal $journal) {}

    /** @param array{subject:string,email:string,name:string} $identity */
    public function resolve(array $identity, ?int $signedInId): User|string
    {
        try {
            return DB::transaction(function () use ($identity, $signedInId) {
                $linkedId = DB::table('social_accounts')->where(['provider' => 'google', 'subject' => $identity['subject']])->value('user_id');
                $user = User::query()->when($linkedId !== null, fn ($q) => $q->whereKey($linkedId),
                    fn ($q) => $q->where('email', $identity['email']))->lockForUpdate()->first();
                if (($signedInId !== null && $user?->id !== $signedInId) || ($user !== null && ! $this->eligible($user))) {
                    throw self::conflict();
                }
                if ($user === null) {
                    $user = new User;
                    $user->forceFill(['email' => $identity['email'], 'email_verified_at' => now(), 'account_type' => User::CUSTOMER])->save();
                    $this->directory->open($user->id, $identity['name']);
                    $this->attach($user, $identity['subject']);
                } elseif ($linkedId === null) {
                    if (DB::table('social_accounts')->where(['user_id' => $user->id, 'provider' => 'google'])->exists()) {
                        throw self::conflict();
                    }
                    $token = Str::random(64);
                    DB::table('oauth_link_requests')->insert(['user_id' => $user->id, 'subject' => $identity['subject'],
                        'email' => $user->email, 'token_hash' => hash('sha256', $token), 'expires_at' => now()->addMinutes(10)]);
                    $this->outbox->record('GoogleIdentityVerified', 'user', $user->id, []);

                    return $token;
                }
                $this->outbox->record('GoogleIdentityVerified', 'user', $user->id, []);

                return $user;
            });
        } catch (UniqueConstraintViolationException) {
            throw self::conflict();
        }
    }

    public function confirm(int $userId, string $hash, string $password): User
    {
        try {
            return DB::transaction(function () use ($userId, $hash, $password) {
                $user = User::query()->whereKey($userId)->lockForUpdate()->firstOrFail();
                $query = DB::table('oauth_link_requests')->where(['user_id' => $userId, 'token_hash' => $hash])
                    ->whereNull('used_at')->where('expires_at', '>', now());
                $challenge = (clone $query)->lockForUpdate()->first();
                if (! $this->eligible($user) || $challenge === null || $user->email !== $challenge->email
                    || $user->password_hash === null || ! Hash::check($password, $user->password_hash)) {
                    throw EmailVerifications::invalid();
                }
                $this->attach($user, $challenge->subject);
                $query->update(['used_at' => now()]);
                $user->forceFill(['email_verified_at' => $user->email_verified_at ?? now()])->save();
                $this->journal->audit('identity.google_linked', Subject::User, $userId, $userId);
                $this->outbox->record('GoogleAccountLinked', 'user', $userId, []);

                return $user;
            });
        } catch (UniqueConstraintViolationException) {
            throw self::conflict();
        }
    }

    private function attach(User $user, string $subject): void
    {
        DB::table('social_accounts')->insert(['user_id' => $user->id, 'provider' => 'google', 'subject' => $subject]);
    }

    private function eligible(User $user): bool
    {
        return $user->account_type === User::CUSTOMER && $user->disabled_at === null;
    }

    private static function conflict(): ApiProblem
    {
        return new ApiProblem(409, 'IDENTITY_CONFLICT', 'This Google identity cannot be used');
    }
}
