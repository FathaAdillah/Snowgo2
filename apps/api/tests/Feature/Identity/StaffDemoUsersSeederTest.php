<?php

use App\Models\User;
use App\Modules\Identity\Staff\StaffAccess;
use Database\Seeders\StaffDemoUsersSeeder;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;

uses(DatabaseTruncation::class);

it('queues one MFA invitation per web role and preserves pending links on rerun', function () {
    Role::create(['name' => 'packing', 'guard_name' => 'web']);
    $legacy = Role::create(['name' => 'Older Staff Role', 'guard_name' => 'web']);
    Role::create(['name' => 'external', 'guard_name' => 'api']);
    $this->seed(StaffDemoUsersSeeder::class);

    $codes = [StaffAccess::SUPER, ...StaffAccess::DEFAULT_ROLES, 'packing'];
    $roles = array_combine($codes, $codes) + ['role-'.$legacy->id => $legacy->name];
    $staff = User::query()->with('roles')->where('account_type', User::STAFF)->get()->keyBy('email');
    expect($staff)->toHaveCount(count($roles));
    foreach ($roles as $code => $role) {
        $account = $staff->get($code.'@staff.snowego.test');
        expect($account->getRoleNames()->all())->toBe([$role])
            ->and($account->password_hash)->toBeNull()
            ->and($account->two_factor_confirmed_at)->toBeNull();
    }
    $tokens = fn () => DB::table('staff_invitations')->orderBy('user_id')->pluck('token_hash', 'user_id')->all();
    $firstRun = $tokens();
    expect($firstRun)->toHaveCount(count($roles));
    $this->seed(StaffDemoUsersSeeder::class);
    expect($tokens())->toBe($firstRun)
        ->and(User::count())->toBe(count($roles))
        ->and(DB::table('outbox_events')->where('event_type', 'StaffInvited')->count())->toBe(count($roles))
        ->and(DB::table('audit_logs')->where('action', 'staff.invited')->count())->toBe(count($roles));
});

it('does not claim existing customer or staff addresses or change their grants', function () {
    $customer = User::factory()->create(['email' => 'SUPER_ADMIN@staff.snowego.test']);
    $staff = User::factory()->staff()->disabled()->create(['email' => 'finance@staff.snowego.test', 'staff_display_name' => 'Existing']);
    $staff->assignRole(Role::create(['name' => 'packing', 'guard_name' => 'web']));

    $this->seed(StaffDemoUsersSeeder::class);

    expect($customer->fresh()->account_type)->toBe(User::CUSTOMER)
        ->and($customer->fresh()->getRoleNames())->toBeEmpty()
        ->and($staff->fresh()->disabled_at)->not->toBeNull()
        ->and($staff->fresh()->getAttribute('staff_display_name'))->toBe('Existing')
        ->and($staff->fresh()->getRoleNames()->all())->toBe(['packing']);
    foreach ([$staff, $customer] as $unchanged) {
        $this->assertDatabaseMissing('staff_invitations', ['user_id' => $unchanged->id]);
    }
});

it('refuses to seed staff outside local and testing even with force', function (string $env) {
    app()->detectEnvironment(fn () => $env);
    try {
        expect(fn () => $this->artisan('db:seed', ['--class' => StaffDemoUsersSeeder::class, '--force' => true])->run())
            ->toThrow(RuntimeException::class, 'Staff demo accounts are restricted');
    } finally {
        app()->detectEnvironment(fn () => 'testing');
    }
    $this->assertDatabaseEmpty('users');
    $this->assertDatabaseEmpty('staff_invitations');
})->with(['production', 'staging']);
