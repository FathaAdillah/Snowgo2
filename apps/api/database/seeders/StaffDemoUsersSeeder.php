<?php

namespace Database\Seeders;

use App\Modules\Identity\Staff\StaffInvitations;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Spatie\Permission\Models\Role;

final class StaffDemoUsersSeeder extends Seeder
{
    public function run(StaffInvitations $invitations): void
    {
        $allowed = ['local', 'testing'];
        if (! app()->environment($allowed) || ! in_array(config('app.env'), $allowed, true)) {
            throw new RuntimeException('Staff demo accounts are restricted to local and testing environments.');
        }

        DB::transaction(function () use ($invitations) {
            $this->call(PermissionSeeder::class);
            foreach (Role::query()->where('guard_name', 'web')->orderBy('id')->get() as $role) {
                $code = preg_match('/\A[a-z][a-z0-9_]{0,63}\z/', $role->name) ? $role->name : 'role-'.$role->getKey();
                $email = $code.'@staff.snowego.test';
                $user = $invitations->inviteIfMissing($email, $role->name);
                if ($user === null) {
                    $this->command?->line("Skipped existing address: {$email}");

                    continue;
                }
                $user->setAttribute('staff_display_name', 'Demo '.Str::headline($code))->save();
                $this->command?->line("Invitation queued: {$email}");
            }
        });
    }
}
