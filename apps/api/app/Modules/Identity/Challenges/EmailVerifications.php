<?php

namespace App\Modules\Identity\Challenges;

use App\Models\User;
use App\Modules\Foundation\Audit\Journal;
use App\Modules\Foundation\Audit\Subject;
use App\Modules\Foundation\Outbox\Outbox;
use App\Support\Http\ApiProblem;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use LogicException;

final class EmailVerifications
{
    public const TTL_MINUTES = 60;

    public function __construct(private readonly Outbox $outbox, private readonly Journal $journal) {}

    /** Replace any pending challenge; only its hash is matchable, the ciphertext is for the mail job. */
    public function issue(User $user): void
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('Issue the challenge in the domain transaction.');
        }
        $token = bin2hex(random_bytes(32));
        DB::table('email_verification_tokens')->upsert([[
            'user_id' => $user->id, 'token_hash' => hash('sha256', $token),
            'delivery_ciphertext' => Crypt::encryptString($token),
            'expires_at' => now()->addMinutes(self::TTL_MINUTES), 'created_at' => now(),
        ]], ['user_id'], ['token_hash', 'delivery_ciphertext', 'expires_at', 'created_at']);
        $this->outbox->record('EmailVerificationRequested', 'user', $user->id, []);
    }

    public function resend(User $user): void
    {
        DB::transaction(function () use ($user) {
            $fresh = User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();
            if ($fresh->email_verified_at === null) {
                $this->issue($fresh);
            }
        });
    }

    public function consume(string $token): void
    {
        DB::transaction(function () use ($token) {
            $hash = hash('sha256', $token);
            // Lock order matches resend(): user row first, then the challenge.
            $userId = DB::table('email_verification_tokens')->where('token_hash', $hash)->value('user_id');
            if ($userId !== null) {
                User::query()->whereKey($userId)->lockForUpdate()->first();
            }
            $row = DB::table('email_verification_tokens')->where('token_hash', $hash)->lockForUpdate()->first();
            if ($row === null || now()->greaterThanOrEqualTo($row->expires_at)) {
                throw self::invalid();
            }
            DB::table('email_verification_tokens')->where('user_id', $row->user_id)->delete();
            $verified = User::query()->whereKey($row->user_id)->whereNull('email_verified_at')->update(['email_verified_at' => now()]);
            if ($verified === 1) {
                $this->journal->audit('identity.email_verified', Subject::User, $row->user_id, $row->user_id);
                $this->outbox->record('EmailVerified', 'user', $row->user_id, []);
            }
        });
    }

    public static function invalid(): ApiProblem
    {
        return new ApiProblem(422, 'INVALID_CHALLENGE', 'Invalid or expired challenge');
    }
}
