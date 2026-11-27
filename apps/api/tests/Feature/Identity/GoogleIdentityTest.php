<?php

use App\Models\User;
use App\Modules\Foundation\Outbox\Relay;
use App\Modules\Identity\Google\GoogleOidc;
use App\Modules\Notifications\LifecycleMail;
use Database\Factories\UserFactory;
use Firebase\JWT\JWT;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

uses(DatabaseTruncation::class);

beforeEach(function () {
    config(['sanctum.stateful' => ['localhost'], 'queue.default' => 'sync']);
    Mail::fake();
    $google = new MockHandler;
    $this->google = $google;
    $this->app->bind(GoogleOidc::class, fn () => (new GoogleOidc(request(), 'snowego-test', 'test',
        'http://localhost/api/v1/auth/google/callback'))->enablePKCE()
        ->setHttpClient(new Client(['handler' => HandlerStack::create($google)])));
    $this->browser = function ($method, $path, $data = []) {
        app('auth')->forgetGuards();
        app('session')->forgetDrivers();
        app()->forgetInstance('session.store');
        $this->defaultHeaders = $method === 'POST' || $path === 'me' ? ['Origin' => 'http://localhost'] : [];
        $response = $this->withCredentials()->json($method, '/api/v1/auth/'.$path, $data);
        $this->defaultCookies = [config('session.cookie') => $response->getCookie(config('session.cookie'))?->getValue()];

        return $response;
    };
    $this->begin = function () {
        $redirect = ($this->browser)('GET', 'google/redirect')->assertRedirect()->headers->get('Location');
        expect(parse_url($redirect, PHP_URL_HOST))->toBe('accounts.google.com');
        parse_str(parse_url($redirect, PHP_URL_QUERY), $params);
        expect($params['code_challenge_method'])->toBe('S256');

        return $params['state'];
    };
    $this->issue = function (array $changes = []) use ($google) {
        static $key;
        $key ??= openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        $rsa = openssl_pkey_get_details($key)['rsa'];
        $jwk = ['alg' => 'RS256', 'kty' => 'RSA', 'kid' => 'test',
            'n' => JWT::urlsafeB64Encode($rsa['n']), 'e' => JWT::urlsafeB64Encode($rsa['e'])];
        $jwt = JWT::encode($changes + ['iss' => 'https://accounts.google.com', 'aud' => 'snowego-test',
            'sub' => 'subject-A', 'email_verified' => true, 'email' => 'first@example.test', 'exp' => time() + 300],
            $key, 'RS256', 'test');
        $google->append(new Response(200, [], json_encode(['id_token' => $jwt])), new Response(200, [], json_encode(['keys' => [$jwk]])));
    };
    $this->callback = fn ($state) => ($this->browser)('GET', 'google/callback?code=test&state='.$state);
});

it('provisions a verified customer then recognizes the same subject without reassigning the account by email', function () {
    ($this->issue)();
    ($this->callback)(($this->begin)())->assertRedirect(config('app.url').'/auth/google');
    ($this->browser)('GET', 'me')->assertOk()->assertJsonPath('data.email_verified', true);
    $this->assertDatabaseCount('customer_profiles', 1);
    $this->assertDatabaseHas('social_accounts', ['subject' => 'subject-A', 'provider' => 'google']);
    $this->defaultCookies = [];
    ($this->issue)(['email' => 'changed@example.test']);
    ($this->callback)(($this->begin)())->assertRedirect();
    ($this->browser)('GET', 'me')->assertJsonPath('data.email', 'first@example.test');
    $this->assertDatabaseCount('users', 1);
});

it('requires both the legacy login and fresh password to confirm a single-use link and rotates the session', function () {
    $user = User::factory()->unverified()->create(['email' => 'first@example.test']);
    ($this->issue)();
    $location = ($this->callback)(($this->begin)())->assertRedirect()->headers->get('Location');
    parse_str(parse_url($location, PHP_URL_FRAGMENT), $params);
    $payload = ['token' => $params['link'], 'password' => UserFactory::PASSWORD];
    $this->assertDatabaseCount('social_accounts', 0);
    $this->assertDatabaseHas('oauth_link_requests', ['token_hash' => hash('sha256', $payload['token']), 'used_at' => null]);
    ($this->browser)('POST', 'google/links', $payload)->assertUnauthorized();
    ($this->browser)('POST', 'login', ['email' => $user->email, 'password' => UserFactory::PASSWORD])->assertOk();
    $before = $this->defaultCookies;
    ($this->browser)('POST', 'google/links', ['password' => 'wrong'] + $payload)->assertUnprocessable();
    ($this->browser)('POST', 'google/links', $payload)->assertOk()->assertJsonPath('data.email_verified', true);
    expect($this->defaultCookies)->not->toBe($before);
    ($this->browser)('POST', 'google/links', $payload)->assertUnprocessable();
    $this->assertDatabaseHas('social_accounts', ['user_id' => $user->id, 'subject' => 'subject-A']);
    app(Relay::class)->publish();
    Mail::assertSent(LifecycleMail::class, fn (LifecycleMail $mail) => $mail->hasTo($user->email) && $mail->notice['title'] === 'Akun Google ditautkan ke Snowego');
});

it('rejects bad and expired states before exchanging a code', function () {
    $state = ($this->begin)();
    ($this->callback)('mismatch')->assertUnprocessable();
    ($this->callback)($state)->assertUnprocessable();
    $state = ($this->begin)();
    $this->travel(11)->minutes();
    ($this->callback)($state)->assertUnprocessable();
    $this->assertDatabaseCount('users', 0);
});

it('keeps a link bound to its original browser and customer', function () {
    $owner = User::factory()->create(['email' => 'first@example.test']);
    $other = User::factory()->create();
    ($this->issue)();
    $url = ($this->callback)(($this->begin)())->headers->get('Location');
    parse_str(parse_url($url, PHP_URL_FRAGMENT), $params);
    $payload = ['token' => $params['link'], 'password' => UserFactory::PASSWORD];
    ($this->browser)('POST', 'login', ['email' => $other->email, 'password' => UserFactory::PASSWORD])->assertOk();
    ($this->browser)('POST', 'google/links', $payload)->assertUnprocessable();
    $this->defaultCookies = [];
    ($this->browser)('POST', 'login', ['email' => $owner->email, 'password' => UserFactory::PASSWORD])->assertOk();
    ($this->browser)('POST', 'google/links', $payload)->assertUnprocessable();
    $this->assertDatabaseCount('social_accounts', 0);
});

it('rejects identities without the required verified claims', function (array $claims) {
    ($this->issue)($claims);
    ($this->callback)(($this->begin)())->assertUnprocessable()->assertJsonPath('code', 'INVALID_CHALLENGE');
    $this->assertDatabaseCount('social_accounts', 0);
})->with([[['iss' => 'other']], [['aud' => 'other']], [['email_verified' => false]], [['sub' => '']]]);

it('does not offer a link for a disabled or staff account', function (array $changes) {
    User::factory()->create($changes + ['email' => 'first@example.test']);
    ($this->issue)();
    ($this->callback)(($this->begin)())->assertConflict();
    $this->assertDatabaseCount('oauth_link_requests', 0);
})->with([[['account_type' => User::STAFF]], [['disabled_at' => '2026-01-01']]]);

it('rejects a link after expiry even with the correct legacy session and password', function () {
    $user = User::factory()->create(['email' => 'first@example.test']);
    ($this->issue)();
    $url = ($this->callback)(($this->begin)())->headers->get('Location');
    parse_str(parse_url($url, PHP_URL_FRAGMENT), $params);
    ($this->browser)('POST', 'login', ['email' => $user->email, 'password' => UserFactory::PASSWORD])->assertOk();
    $this->travel(11)->minutes();
    ($this->browser)('POST', 'google/links', ['token' => $params['link'], 'password' => UserFactory::PASSWORD])->assertUnprocessable();
    expect(DB::table('social_accounts')->exists())->toBeFalse();
});
