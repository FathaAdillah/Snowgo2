<?php

namespace App\Modules\Identity\Staff;

use App\Models\User;
use App\Modules\Foundation\Audit\Journal;
use App\Modules\Foundation\Audit\Subject;
use App\Modules\Foundation\Outbox\Outbox;
use App\Support\Http\ApiProblem;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;

final class StaffInvitations
{
    public function invite(string $email, string $role, ?int $actor = null): User
    {
        $token = Str::random(64);

        return DB::transaction(function () use ($email, $role, $token, $actor) {
            $grant = Role::findByName($role, 'web');
            $user = User::query()->firstOrCreate(['email' => $email], ['account_type' => User::STAFF]);
            $new = $user->wasRecentlyCreated;
            $user = User::query()->lockForUpdate()->findOrFail($user->id);
            if ($user->account_type !== User::STAFF || $user->disabled_at || $user->two_factor_confirmed_at
                || (! $new && ! $user->hasRole($grant))) {
                throw new ApiProblem(409, 'IDENTITY_CONFLICT', 'Not a pending staff invitation for this role');
            }
            $user->assignRole($grant);
            DB::table('staff_invitations')->updateOrInsert(['user_id' => $user->id], [
                'token_hash' => hash('sha256', $token), 'expires_at' => now()->addHour(),
                'delivery_ciphertext' => Crypt::encryptString($token), 'created_at' => now()->format('Y-m-d H:i:s.uP'),
            ]);
            app(Journal::class)->audit('staff.invited', Subject::User, $user->id, $actor, after: ['role' => $role]);
            app(Outbox::class)->record('StaffInvited', 'user', $user->id, []);

            return $user;
        });
    }
}
