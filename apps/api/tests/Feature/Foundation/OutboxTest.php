<?php

use App\Modules\Foundation\Audit\Journal;
use App\Modules\Foundation\Audit\Subject;
use App\Modules\Foundation\Outbox\ConsumeEvent;
use App\Modules\Foundation\Outbox\EventHandler;
use App\Modules\Foundation\Outbox\Outbox;
use App\Modules\Foundation\Outbox\RecordedEvent;
use App\Modules\Foundation\Outbox\Relay;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

uses(DatabaseTruncation::class);

final class FixtureOrderHandler implements EventHandler
{
    public function handle(RecordedEvent $event): void
    {
        app(Journal::class)->audit('fixture.received', Subject::Order, $event->aggregateId, null);
        if (config('fixture.fail')) {
            throw new RuntimeException('Fixture delivery failed');
        }
    }
}

beforeEach(function () {
    config(['outbox.handlers' => ['OrderCreated' => ['fixture' => FixtureOrderHandler::class]]]);
    Context::scope(fn () => DB::transaction(fn () => app(Outbox::class)->record('OrderCreated', 'order', 41, ['source' => 'fixture'])), ['request_id' => 'edge-correlation']);
});

it('recovers a publish crash and a lost queue delivery without repeating handler effects', function () {
    Bus::fake();
    $relay = app(Relay::class);
    expect($relay->publish())->toBe(1);
    $receipt = DB::table('event_consumptions')->first();
    $job = new ConsumeEvent($receipt->id);
    Bus::assertDispatched(ConsumeEvent::class, fn ($sent) => $sent->consumptionId === $receipt->id);
    expect($relay->publish())->toBe(0)->and($relay->reconcile())->toBe(0);

    DB::table('outbox_events')->update(['published_at' => null, 'lease_until' => now()->addMinute()]);
    expect($relay->publish())->toBe(0);
    DB::table('outbox_events')->update(['lease_until' => now()->subMinute()]);
    expect($relay->publish())->toBe(1);
    DB::table('event_consumptions')->update(['lease_until' => now()->subMinute()]);
    expect($relay->reconcile())->toBe(1)->and($relay->reconcile())->toBe(0);
    Bus::assertDispatchedTimes(ConsumeEvent::class, 3);

    $job->handle();
    $job->handle();
    $job->failed(new RuntimeException('Late duplicate'));
    $this->assertDatabaseCount('audit_logs', 1);
    $this->assertDatabaseHas('audit_logs', ['request_id' => 'edge-correlation', 'subject_id' => 41]);
    $this->assertDatabaseHas('event_consumptions', ['status' => 'COMPLETED', 'attempts' => 1]);
    expect($relay->reconcile())->toBe(0);
});

it('skips an event locked by a concurrent publisher', function () {
    Bus::fake();
    config(['database.connections.publisher' => config('database.connections.pgsql')]);
    $other = DB::connection('publisher');
    $other->beginTransaction();
    try {
        $other->table('outbox_events')->lockForUpdate()->first();
        expect(app(Relay::class)->publish())->toBe(0);
        Bus::assertNothingDispatched();
    } finally {
        $other->rollBack();
    }
    expect(app(Relay::class)->publish())->toBe(1);
});

it('leaves an event recoverable when enqueue throws', function () {
    Bus::shouldReceive('dispatch')->once()->andThrow(new RuntimeException('Redis unavailable'));
    expect(fn () => app(Relay::class)->publish())->toThrow(RuntimeException::class, 'Redis unavailable');
    $this->assertDatabaseHas('outbox_events', ['published_at' => null, 'attempts' => 1]);
});

it('records exhausted Redis jobs for operators and permits a successful manual retry', function () {
    // A private queue prevents the running dev Horizon supervisor from taking test work.
    $queue = 'foundation-test-'.Str::uuid();
    Exceptions::fake();
    $job = new ConsumeEvent(DB::table('event_consumptions')->value('id'));
    Queue::connection('redis')->pushOn($queue, $job);
    config(['fixture.fail' => true]);
    $options = ['connection' => 'redis', '--queue' => $queue, '--once' => true, '--tries' => 1, '--sleep' => 0];
    $this->artisan('queue:work', $options)->assertSuccessful();
    $this->assertDatabaseEmpty('audit_logs');
    $this->assertDatabaseHas('event_consumptions', ['status' => 'FAILED', 'attempts' => 1]);
    $failed = DB::table('failed_jobs')->where('queue', $queue)->first();
    expect($failed)->not->toBeNull()->and($failed->payload)->not->toContain('edge-correlation', 'OrderCreated');
    config(['fixture.fail' => false]);
    $this->artisan('queue:retry', ['id' => [$failed->uuid]])->assertSuccessful();
    $this->artisan('queue:work', $options)->assertSuccessful();
    $this->assertDatabaseEmpty('failed_jobs');
    $this->assertDatabaseCount('audit_logs', 1);
    $this->assertDatabaseHas('event_consumptions', ['status' => 'COMPLETED', 'attempts' => 2]);
});
