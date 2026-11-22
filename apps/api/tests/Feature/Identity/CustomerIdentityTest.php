<?php

use App\Models\User;
use App\Modules\Foundation\Outbox\Relay;
use App\Modules\Identity\Mail\ResetPassword;
use App\Modules\Identity\Mail\VerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Testing\TestResponse;

uses(DatabaseTruncation::class);

beforeEach(function () {
    config(['queue.default' => 'sync', 'sanctum.stateful' => ['localhost']]);
    Mail::fake();
    // Each call is a separate browser request: only the session cookie carries state.
    $this->browser = function (string $method, string $uri, array $data = [], ?string $session = null, string $agent = 'Mozilla/5.0 (Windows NT 10.0) Chrome/130.0') {
        app('auth')->forgetGuards();
        app('session')->forgetDrivers();
        app()->forgetInstance('session.store');
        $this->defaultCookies = $session === null ? [] : [config('session.cookie') => $session];
        $this->defaultHeaders = ['Origin' => 'http://localhost', 'User-Agent' => $agent];

        return $this->withCredentials()->json($method, "/api/v1/$uri", $data);
    };
    $this->session = fn (TestResponse $response) => $response->getCookie(config('session.cookie'))?->getValue();
    $this->login = fn (string $email, string $password = UserFactory::PASSWORD, string $agent = 'Mozilla/5.0 (Windows NT 10.0) Chrome/130.0') => ($this->session)(($this->browser)('POST', 'auth/login', ['email' => $email, 'password' => $password], agent: $agent)->assertOk());
    $this->deliver = fn () => app(Relay::class)->publish();
});

it('registers once, keeps customer routes closed until the email challenge is used, and consumes it once', function () {
    $register = ['name' => 'Rani', 'email' => 'rani@example.test', 'password' => 'a long passphrase'];
    $first = ($this->browser)('POST', 'auth/register', $register)->assertOk()->assertJsonPath('data', null);
    $again = ($this->browser)('POST', 'auth/register', [...$register, 'email' => 'RANI@example.test', 'name' => 'Other'])->assertOk();
    expect($again->json('message'))->toBe($first->json('message'));
    ($this->browser)('POST', 'auth/register', [...$register, 'email' => 'short@example.test', 'password' => 'too-short'])
        ->assertUnprocessable()->assertJsonPath('code', 'VALIDATION_FAILED');
    $this->assertDatabaseCount('users', 1);
    $this->assertDatabaseHas('customer_profiles', ['display_name' => 'Rani']);
    $user = User::query()->sole();
    expect($user->account_type)->toBe('CUSTOMER')->and(Hash::info($user->password_hash)['algoName'])->toBe('argon2id');

    expect(($this->deliver)())->toBe(1);
    $token = null;
    Mail::assertSent(VerifyEmail::class, function (VerifyEmail $mail) use (&$token) {
        $token = $mail->token;

        return $mail->hasTo('rani@example.test');
    });
    $stored = DB::table('email_verification_tokens')->sole();
    expect($stored->delivery_ciphertext)->toBeNull()->and($stored->token_hash)->toBe(hash('sha256', $token));

    $session = ($this->login)('rani@example.test', 'a long passphrase');
    ($this->browser)('GET', 'auth/me', session: $session)->assertOk()
        ->assertJsonPath('data', ['surface' => 'CUSTOMER', 'display_name' => 'Rani', 'email' => 'rani@example.test', 'email_verified' => false]);
    $this->assertDatabaseHas('sessions', ['user_id' => $user->id, 'surface' => 'CUSTOMER']);
    ($this->browser)('GET', 'files/01J00000000000000000000000', session: $session)->assertForbidden()->assertJsonPath('code', 'EMAIL_NOT_VERIFIED');

    ($this->browser)('POST', 'auth/email-verifications', ['token' => $token])->assertOk();
    ($this->browser)('POST', 'auth/email-verifications', ['token' => $token])->assertUnprocessable()->assertJsonPath('code', 'INVALID_CHALLENGE');
    $this->assertDatabaseEmpty('email_verification_tokens');
    ($this->browser)('GET', 'files/01J00000000000000000000000', session: $session)->assertNotFound();
    ($this->browser)('POST', 'auth/email-verification-requests', session: $session)->assertOk();
    $this->assertDatabaseCount('outbox_events', 2);
    $this->assertDatabaseHas('outbox_events', ['event_type' => 'EmailVerified', 'aggregate_id' => $user->id]);
});

it('expires verification challenges and re-issues them only for unverified customers', function () {
    $user = User::factory()->unverified()->create();
    $session = ($this->login)($user->email);
    ($this->browser)('POST', 'auth/email-verification-requests', session: $session)->assertOk();
    ($this->browser)('POST', 'auth/email-verification-requests', session: $session)->assertTooManyRequests()->assertJsonPath('code', 'THROTTLED');
    ($this->deliver)();
    $token = null;
    Mail::assertSent(VerifyEmail::class, function (VerifyEmail $mail) use (&$token) {
        $token = $mail->token;

        return true;
    });
    $this->travel(61)->minutes();
    ($this->browser)('POST', 'auth/email-verifications', ['token' => $token])->assertUnprocessable()->assertJsonPath('code', 'INVALID_CHALLENGE');
    expect($user->fresh()->email_verified_at)->toBeNull();
});

it('rejects credentials generically, refuses staff and disabled accounts, and throttles failures per identifier', function () {
    $customer = User::factory()->create();
    $staff = User::factory()->staff()->create();
    $disabled = User::factory()->disabled()->create();
    $bodies = collect([[$customer->email, 'wrong password!'], ['nobody@example.test', UserFactory::PASSWORD],
        [$staff->email, UserFactory::PASSWORD], [$disabled->email, UserFactory::PASSWORD]])
        ->map(fn (array $c) => ($this->browser)('POST', 'auth/login', ['email' => $c[0], 'password' => $c[1]])
            ->assertUnauthorized()->json())
        ->map(fn (array $body) => array_diff_key($body, ['meta' => true]));
    expect($bodies->unique()->count())->toBe(1)->and($bodies->first()['code'])->toBe('BAD_CREDENTIALS');
    $this->assertDatabaseMissing('sessions', ['user_id' => $staff->id]);

    foreach (range(1, 4) as $_) {
        ($this->browser)('POST', 'auth/login', ['email' => $customer->email, 'password' => 'wrong password!'])->assertUnauthorized();
    }
    ($this->browser)('POST', 'auth/login', ['email' => $customer->email, 'password' => UserFactory::PASSWORD])
        ->assertTooManyRequests()->assertJsonPath('code', 'THROTTLED')->assertHeader('Retry-After');
    ($this->browser)('GET', 'auth/me')->assertUnauthorized()->assertJsonPath('code', 'UNAUTHENTICATED');
});

it('answers reset requests uniformly, resets once and revokes every session of the account', function () {
    $user = User::factory()->unverified()->create();
    $phone = ($this->login)($user->email);
    $laptop = ($this->login)($user->email);
    $known = ($this->browser)('POST', 'auth/password-reset-requests', ['email' => strtoupper($user->email)])->assertOk()->json();
    $unknown = ($this->browser)('POST', 'auth/password-reset-requests', ['email' => 'nobody@example.test'])->assertOk()->json();
    ($this->browser)('POST', 'auth/password-reset-requests', ['email' => $user->email])->assertOk();
    expect(array_diff_key($known, ['meta' => 1]))->toBe(array_diff_key($unknown, ['meta' => 1]));
    expect(($this->deliver)())->toBe(1);
    $token = null;
    Mail::assertSentCount(1);
    Mail::assertSent(ResetPassword::class, function (ResetPassword $mail) use (&$token, $user) {
        $token = $mail->token;

        return $mail->hasTo($user->email);
    });
    expect(DB::table('password_reset_tokens')->sole()->delivery_ciphertext)->toBeNull();

    $reset = ['email' => $user->email, 'token' => $token, 'password' => 'a brand new passphrase'];
    ($this->browser)('POST', 'auth/password-resets', [...$reset, 'token' => str_repeat('0', 64)])->assertUnprocessable()->assertJsonPath('code', 'INVALID_CHALLENGE');
    ($this->browser)('POST', 'auth/password-resets', $reset, session: $phone)->assertOk();
    ($this->browser)('POST', 'auth/password-resets', $reset)->assertUnprocessable()->assertJsonPath('code', 'INVALID_CHALLENGE');
    foreach ([$phone, $laptop] as $revoked) {
        ($this->browser)('GET', 'auth/me', session: $revoked)->assertUnauthorized();
    }
    $this->assertDatabaseMissing('sessions', ['user_id' => $user->id]);
    expect($user->fresh()->email_verified_at)->not->toBeNull();
    ($this->browser)('POST', 'auth/login', ['email' => $user->email, 'password' => UserFactory::PASSWORD])->assertUnauthorized();
    ($this->login)($user->email, 'a brand new passphrase');
    $this->assertDatabaseHas('outbox_events', ['event_type' => 'PasswordReset', 'aggregate_id' => $user->id]);
});

it('lists and revokes only the account own devices by opaque reference', function () {
    $user = User::factory()->create();
    $other = User::factory()->create();
    $phone = ($this->login)($user->email, agent: 'Mozilla/5.0 (Linux; Android 14) Firefox/131.0');
    $laptop = ($this->login)($user->email);
    $stranger = ($this->login)($other->email);

    $list = ($this->browser)('GET', 'auth/sessions', session: $laptop)->assertOk()
        ->assertJsonPath('meta.total', 2)->assertJsonPath('meta.page', 1)->json('data');
    expect(collect($list)->pluck('device_label')->sort()->values()->all())->toBe(['Chrome · Windows', 'Firefox · Android'])
        ->and(collect($list)->where('current', true)->sole()['device_label'])->toBe('Chrome · Windows')
        ->and(DB::table('sessions')->pluck('id')->intersect(collect($list)->pluck('session_ref')))->toBeEmpty();
    $phoneRef = collect($list)->firstWhere('current', false)['session_ref'];

    ($this->browser)('DELETE', "auth/sessions/$phoneRef", session: $stranger)->assertNotFound()->assertJsonPath('code', 'NOT_FOUND');
    ($this->browser)('GET', 'auth/me', session: $phone)->assertOk();
    ($this->browser)('DELETE', "auth/sessions/$phoneRef", session: $laptop)->assertOk();
    ($this->browser)('GET', 'auth/me', session: $phone)->assertUnauthorized();
    ($this->browser)('GET', 'auth/me', session: $laptop)->assertOk();
    $this->assertDatabaseHas('outbox_events', ['event_type' => 'SessionRevoked', 'aggregate_id' => $user->id]);

    ($this->browser)('POST', 'auth/logout', session: $laptop)->assertOk();
    ($this->browser)('GET', 'auth/me', session: $laptop)->assertUnauthorized();
    $this->assertDatabaseMissing('sessions', ['user_id' => $user->id]);
    ($this->browser)('GET', 'auth/me', session: $stranger)->assertOk();
});

it('ends sessions of an account disabled after sign-in and rehashes outdated Argon2id parameters', function () {
    $user = User::factory()->create();
    $outdated = password_hash(UserFactory::PASSWORD, PASSWORD_ARGON2ID, ['memory_cost' => 2048, 'time_cost' => 2, 'threads' => 1]);
    DB::table('users')->where('id', $user->id)->update(['password_hash' => $outdated]);
    $session = ($this->login)($user->email);
    expect($user->fresh()->password_hash)->not->toBe($outdated)->and(Hash::needsRehash($user->fresh()->password_hash))->toBeFalse();
    $user->forceFill(['disabled_at' => now()])->save();
    ($this->browser)('GET', 'auth/me', session: $session)->assertUnauthorized();
    $this->assertDatabaseMissing('sessions', ['user_id' => $user->id]);
});
