<?php

use App\Models\User;
use App\Modules\Administration\Http\Middleware\LocalOperatorAccess;
use App\Modules\Foundation\Outbox\Relay;
use App\Modules\Identity\Mail\StaffInvitation;
use App\Modules\Identity\Staff\StaffAccess;
use Database\Factories\UserFactory;
use Database\Seeders\PermissionSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Route;
use PragmaRX\Google2FA\Google2FA;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

uses(DatabaseTruncation::class);

beforeEach(function () {
    config(['queue.default' => 'sync', 'sanctum.stateful' => ['admin.localhost', 'localhost'], 'auth.admin_url' => 'http://admin.localhost']);
    $this->seed(PermissionSeeder::class);
    Mail::fake();
    $this->cookie = null;
    $this->send = function ($method, $path, $data = [], $origin = 'http://admin.localhost', $headers = []) {
        app('auth')->forgetGuards();
        app('session')->forgetDrivers();
        app()->forgetInstance('session.store');
        $this->defaultCookies = $this->cookie ? [config('session.cookie') => $this->cookie] : [];
        $this->defaultHeaders = ['Origin' => $origin, 'Idempotency-Key' => 'staff-test-key', ...$headers];
        $response = $this->withCredentials()->json($method, $origin.$path, $data);
        $this->cookie = $response->getCookie(config('session.cookie'))?->getValue() ?? $this->cookie;

        return $response;
    };
    $this->auth = fn ($path, $data = []) => ($this->send)('POST', '/api/v1/admin/auth/'.$path, $data);
    $this->otp = fn ($secret) => app(Google2FA::class)->oathTotp($secret, intdiv(now()->timestamp, 30));
    $this->staff = function ($role) {
        $user = User::factory()->staff()->create(['two_factor_secret' => app(Google2FA::class)->generateSecretKey(32), 'two_factor_confirmed_at' => now()]);
        $user->assignRole($role);

        return $user;
    };
    $this->login = function ($user) {
        ($this->auth)('login', ['email' => $user->email, 'password' => UserFactory::PASSWORD])->assertOk();

        return ($this->auth)('mfa-challenges', ['code' => ($this->otp)($user->two_factor_secret)])->assertOk();
    };
});

it('invites a superadmin without a default password and consumes enrollment and recovery exactly once', function () {
    $this->freezeTime();
    $email = 'root@example.test';
    $this->artisan('staff:invite', ['email' => $email, '--role' => StaffAccess::SUPER])->assertSuccessful();
    $credentials = ['email' => $email, 'password' => UserFactory::PASSWORD];
    ($this->auth)('login', $credentials)->assertUnauthorized();
    app(Relay::class)->publish();
    $token = Mail::sent(StaffInvitation::class)->sole()->token;
    $enroll = ($this->auth)('mfa-enrollments', ['token' => $token, 'password' => UserFactory::PASSWORD])->assertOk();
    $enroll->assertHeaderContains('Cache-Control', 'no-store');
    $uri = parse_url($enroll->json('data.otpauth_uri'));
    expect([$uri['scheme'], $uri['host'], rawurldecode($uri['path'])])->toBe(['otpauth', 'totp', '/Snowego Admin:'.$email]);
    parse_str($uri['query'], $options);
    expect([$options['issuer'], $options['digits'], $options['period'], $options['algorithm']])->toBe(['Snowego Admin', '6', '30', 'SHA1']);
    $secret = $options['secret'];
    expect($secret)->toBe($enroll->json('data.secret'));
    $code = ($this->otp)($secret);
    $step = intdiv(now()->timestamp, 30);
    $valid = array_map(fn ($offset) => app(Google2FA::class)->oathTotp($secret, $step + $offset), [-1, 0, 1]);
    $wrong = collect(['000000', '000001', '000002', '000003'])->first(fn ($candidate) => ! in_array($candidate, $valid, true));
    ($this->auth)('mfa-enrollment-verifications', ['token' => $token, 'code' => 'xxxxxx'])->assertUnprocessable();
    ($this->auth)('mfa-enrollment-verifications', ['token' => $token, 'code' => $wrong])->assertUnprocessable()->assertJsonPath('code', 'INVALID_CHALLENGE')->assertJsonMissingPath('data.recovery_codes');
    $codes = ($this->auth)('mfa-enrollment-verifications', compact('token', 'code'))->assertOk()->json('data.recovery_codes');
    expect($codes)->toHaveCount(8);
    expect(DB::table('users')->sole()->two_factor_secret)->not->toContain($secret);
    expect(DB::table('users')->sole()->two_factor_recovery_codes)->not->toContain($codes[0]);
    $this->assertDatabaseEmpty('staff_invitations');
    ($this->auth)('mfa-enrollment-verifications', compact('token', 'code'))->assertUnprocessable();
    ($this->auth)('login', $credentials)->assertOk()->assertJsonPath('data.challenge_required', true);
    ($this->send)('GET', '/api/v1/admin/auth/me')->assertUnauthorized();
    ($this->auth)('mfa-challenges', compact('code'))->assertUnprocessable();
    $before = $this->cookie;
    ($this->auth)('mfa-challenges', ['code' => $codes[0]])->assertOk()->assertJsonPath('data.surface', 'STAFF');
    expect($this->cookie)->not->toBe($before);
    ($this->send)('GET', '/api/v1/admin/auth/me')->assertOk();
    ($this->auth)('logout')->assertOk();
    ($this->auth)('login', $credentials)->assertOk();
    ($this->auth)('mfa-challenges', ['code' => $codes[0]])->assertUnprocessable();
    $this->travel(6)->minutes();
    ($this->auth)('mfa-challenges', ['code' => $codes[1]])->assertUnprocessable();
});

it('rejects a customer session, incomplete MFA, disabled staff, bad passwords and the storefront origin', function () {
    ($this->send)('GET', '/api/v1/admin/auth/me')->assertUnauthorized();
    $customer = User::factory()->create();
    $staff = ($this->staff)(StaffAccess::SUPER);
    foreach ([$customer, User::factory()->staff()->create(), User::factory()->staff()->disabled()->create(['two_factor_confirmed_at' => now()])] as $account) {
        ($this->auth)('login', ['email' => $account->email, 'password' => UserFactory::PASSWORD])->assertUnauthorized()->assertJsonPath('code', 'BAD_CREDENTIALS');
    }
    ($this->auth)('login', ['email' => $staff->email, 'password' => 'wrong'])->assertUnauthorized();
    ($this->send)('POST', '/api/v1/admin/auth/login', ['email' => $staff->email, 'password' => UserFactory::PASSWORD], 'http://localhost')->assertForbidden();
    ($this->send)('POST', '/api/v1/auth/login', ['email' => $customer->email, 'password' => UserFactory::PASSWORD], 'http://localhost')->assertOk();
    ($this->send)('GET', '/api/v1/admin/auth/me')->assertForbidden();
    ($this->send)('GET', '/api/v1/admin/staff')->assertForbidden();
});

it('retains the MFA account limit across a new password challenge', function () {
    $staff = ($this->staff)('admin');
    $credentials = ['email' => $staff->email, 'password' => UserFactory::PASSWORD];
    ($this->auth)('login', $credentials)->assertOk();
    foreach (range(1, 5) as $_) {
        ($this->auth)('mfa-challenges', ['code' => 'invalid-recovery'])->assertUnprocessable();
    }
    ($this->auth)('login', $credentials)->assertOk();
    ($this->auth)('mfa-challenges', ['code' => ($this->otp)($staff->two_factor_secret)])->assertTooManyRequests()->assertJsonPath('code', 'THROTTLED');
    ($this->send)('GET', '/api/v1/admin/auth/me')->assertUnauthorized();
    $this->travel(5)->minutes();
    ($this->login)($staff);
});

it('expires an idle or absolutely expired staff session', function (bool $keepActive) {
    ($this->login)(($this->staff)('admin'));
    if ($keepActive) {
        foreach (range(1, 24) as $_) {
            $this->travel(29)->minutes();
            ($this->send)('GET', '/api/v1/admin/auth/me')->assertOk();
        }
    }
    $this->travel($keepActive ? 24 : 30)->minutes();
    ($this->send)('GET', '/api/v1/admin/auth/me')->assertUnauthorized();
})->with([false, true]);

it('keeps each action default-deny and never delegates operator consoles to staff', function () {
    $staff = ($this->staff)('admin');
    $staff->givePermissionTo('admin.staff.view', StaffAccess::LOGS, StaffAccess::DB);
    $effective = ($this->login)($staff)->json('data.permissions');
    expect($effective)->toContain('admin.staff.view')->not->toContain('admin.staff.create', 'admin.staff.sessions.delete', StaffAccess::LOGS, StaffAccess::DB);
    ($this->send)('GET', '/api/v1/admin/staff')->assertOk()->assertJsonPath('data.0.email', $staff->email);
    ($this->send)('POST', '/api/v1/admin/staff', [])->assertForbidden();
    ($this->send)('DELETE', "/api/v1/admin/staff/{$staff->id}/sessions")->assertForbidden();
    $staff->revokePermissionTo('admin.staff.view');
    ($this->send)('GET', '/api/v1/admin/staff')->assertForbidden();
});

it('applies the registered permission bypass only after MFA and protects the last superadmin', function () {
    $root = ($this->staff)(StaffAccess::SUPER);
    $other = ($this->staff)('admin');
    Permission::findOrCreate('future.feature.create', 'web');
    expect($root->getAllPermissions())->toBeEmpty();
    expect(($this->login)($root)->json('data.permissions'))->toContain('future.feature.create')->not->toContain(StaffAccess::LOGS, StaffAccess::DB);
    ($this->send)('GET', '/api/v1/admin/staff')->assertOk();
    ($this->send)('PATCH', "/api/v1/admin/staff/{$other->id}", ['active' => false])->assertForbidden()->assertJsonPath('code', 'STEP_UP_REQUIRED');
    $this->travel(31)->seconds();
    ($this->auth)('step-up', ['password' => UserFactory::PASSWORD, 'code' => ($this->otp)($root->two_factor_secret)])->assertOk();
    ($this->send)('PATCH', "/api/v1/admin/staff/{$root->id}", ['active' => false])->assertConflict();
    ($this->send)('PATCH', "/api/v1/admin/staff/{$root->id}", ['roles' => ['admin']])->assertConflict();
    ($this->send)('PATCH', "/api/v1/admin/staff/{$other->id}", ['active' => false])->assertOk();
    ($this->send)('POST', '/api/v1/admin/staff', ['email' => 'new@example.test', 'role' => 'admin'])->assertCreated();
    ($this->send)('DELETE', "/api/v1/admin/staff/{$other->id}/sessions")->assertOk();
    $this->travel(10)->minutes();
    ($this->send)('DELETE', "/api/v1/admin/staff/{$other->id}/sessions")->assertForbidden();
});

it('advertises operator actions only to a local MFA-authenticated superadmin', function (string $env, string $role) {
    $user = ($this->staff)($role);
    if ($role !== StaffAccess::SUPER) {
        $user->givePermissionTo(StaffAccess::LOGS, StaffAccess::DB);
    }
    ($this->login)($user);
    $previousEnv = app()->environment();
    app()->detectEnvironment(fn () => $env);
    try {
        $permissions = ($this->send)('GET', '/api/v1/admin/auth/me')->assertOk()->json('data.permissions');
        expect($permissions)->toContain('admin.profile.view');
        foreach ([StaffAccess::LOGS, StaffAccess::DB] as $ability) {
            expect(in_array($ability, $permissions, true))->toBe($env === 'local' && $role === StaffAccess::SUPER);
        }
    } finally {
        app()->detectEnvironment(fn () => $previousEnv);
    }
})->with([['local', StaffAccess::SUPER], ['production', StaffAccess::SUPER], ['local', 'admin']]);

it('guards each operator console even with explicit operator grants or basic credentials', function ($ability) {
    Route::get('/operator-check', fn () => response()->noContent())->middleware(['web', LocalOperatorAccess::class.':'.$ability]);
    $console = function ($expected) {
        app()->detectEnvironment(fn () => 'local');
        try {
            ($this->send)('GET', '/operator-check', headers: ['Authorization' => 'Basic '.base64_encode('dbadmin:test')])->assertStatus($expected);
        } finally {
            app()->detectEnvironment(fn () => 'testing');
        }
    };
    $console(401);
    $customer = User::factory()->create();
    ($this->send)('POST', '/api/v1/auth/login', ['email' => $customer->email, 'password' => UserFactory::PASSWORD], 'http://localhost')->assertOk();
    $console(401);
    $staff = ($this->staff)('admin')->givePermissionTo($ability);
    ($this->login)($staff);
    $console(403);
    $root = ($this->staff)(StaffAccess::SUPER);
    ($this->auth)('login', ['email' => $root->email, 'password' => UserFactory::PASSWORD])->assertOk();
    $console(401);
    ($this->auth)('mfa-challenges', ['code' => ($this->otp)($root->two_factor_secret)])->assertOk();
    $console(204);
    ($this->send)('GET', '/operator-check')->assertNotFound();
    $root->removeRole(StaffAccess::SUPER);
    $console(403);
})->with([StaffAccess::LOGS, StaffAccess::DB]);

it('requires every admin business endpoint to have a seeded permission and preserves custom role grants on reseed', function () {
    $role = Role::findByName('admin', 'web');
    $role->revokePermissionTo('foundation.file.create');
    $count = Permission::count();
    $this->seed(PermissionSeeder::class);
    expect(Permission::count())->toBe($count)->and($role->fresh()->hasPermissionTo('foundation.file.create'))->toBeFalse();
    foreach (Route::getRoutes() as $route) {
        if (! str_starts_with($route->uri(), 'api/v1/admin/') || str_starts_with($route->uri(), 'api/v1/admin/auth/')) {
            continue;
        }
        $names = collect($route->gatherMiddleware())->filter(fn ($m) => str_starts_with($m, 'can:') && ! str_starts_with($m, 'can:files.surface.'));
        expect($names->isNotEmpty())->toBeTrue($route->uri());
        foreach ($names as $name) {
            $this->assertDatabaseHas('permissions', ['name' => substr($name, 4), 'guard_name' => 'web']);
        }
    }
});

it('rejects bypass on another role and refuses to promote a preexisting role with the reserved name', function () {
    expect(fn () => Role::findByName('admin', 'web')->update(['bypasses_permissions' => true]))
        ->toThrow(QueryException::class);
    $reserved = Role::findByName(StaffAccess::SUPER, 'web');
    $reserved->update(['bypasses_permissions' => false]);
    expect(fn () => app(PermissionSeeder::class)->run())->toThrow(LogicException::class);
    expect($reserved->fresh()->getAttribute('bypasses_permissions'))->toBeFalse();
});
