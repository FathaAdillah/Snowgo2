<?php

use App\Modules\Foundation\Health\ReadinessProbe;

it('reports liveness in the shared envelope', function () {
    $this->getJson('/api/v1/health')
        ->assertOk()
        ->assertHeader('X-Request-Id')
        ->assertJson([
            'success' => true,
            'data' => ['status' => 'ok'],
        ])
        ->assertJsonStructure(['message', 'meta' => ['request_id']]);
});

it('echoes a safe upstream request id', function () {
    $this->getJson('/api/v1/health', ['X-Request-Id' => 'edge-0123456789'])
        ->assertHeader('X-Request-Id', 'edge-0123456789')
        ->assertJsonPath('meta.request_id', 'edge-0123456789');
});

it('replaces an unsafe upstream request id', function () {
    $response = $this->getJson('/api/v1/health', ['X-Request-Id' => "bad id\n"]);

    expect($response->headers->get('X-Request-Id'))->not->toBe("bad id\n")
        ->and($response->json('meta.request_id'))->toBe($response->headers->get('X-Request-Id'));
});

it('returns ready when every dependency passes', function () {
    $probe = Mockery::mock(ReadinessProbe::class);
    $probe->shouldReceive('run')->andReturn([
        ['component' => 'database', 'status' => 'ok'],
        ['component' => 'redis', 'status' => 'ok'],
        ['component' => 'storage', 'status' => 'ok'],
    ]);
    $this->app->instance(ReadinessProbe::class, $probe);

    $this->getJson('/api/v1/internal/readiness')
        ->assertOk()
        ->assertJsonPath('data.status', 'ok')
        ->assertJsonCount(3, 'data.checks');
});

it('returns 503 DEPENDENCY_UNAVAILABLE when a dependency fails', function () {
    $probe = Mockery::mock(ReadinessProbe::class);
    $probe->shouldReceive('run')->andReturn([
        ['component' => 'database', 'status' => 'ok'],
        ['component' => 'redis', 'status' => 'fail'],
        ['component' => 'storage', 'status' => 'ok'],
    ]);
    $this->app->instance(ReadinessProbe::class, $probe);

    $this->getJson('/api/v1/internal/readiness')
        ->assertStatus(503)
        ->assertHeader('Retry-After', '10')
        ->assertJson([
            'success' => false,
            'code' => 'DEPENDENCY_UNAVAILABLE',
            'meta' => ['health' => ['status' => 'degraded']],
        ]);
});

it('checks real dependencies in the readiness probe', function () {
    $this->artisan('migrate:fresh');

    $checks = collect(app(ReadinessProbe::class)->run())->pluck('status', 'component');

    expect($checks['database'])->toBe('ok')
        ->and($checks['redis'])->toBe('ok')
        ->and($checks['storage'])->toBe('ok');
})->group('integration');

it('renders unknown api routes as NOT_FOUND envelope', function () {
    $this->getJson('/api/v1/does-not-exist')
        ->assertNotFound()
        ->assertJson(['success' => false, 'code' => 'NOT_FOUND'])
        ->assertJsonStructure(['meta' => ['request_id']]);
});
