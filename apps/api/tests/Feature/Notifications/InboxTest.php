<?php

use App\Models\User;
use App\Modules\Cart\CustomerCart;
use App\Modules\Foundation\Outbox\ConsumeEvent;
use App\Modules\Foundation\Outbox\Outbox;
use App\Modules\Notifications\LifecycleMail;
use App\Modules\Notifications\SendNotificationEmail;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\Mailer\Exception\UnexpectedResponseException;
use Tests\Feature\Checkout\PreparesCheckout;

uses(DatabaseTruncation::class, PreparesCheckout::class);

beforeEach(function () {
    Bus::fake();
    $this->consume = function (int $event) {
        $receipt = DB::table('event_consumptions')->where('event_id', $event)->where('handler', '!=', 'invoicing.pdf')->value('id');
        (new ConsumeEvent($receipt))->handle();
    };
    $this->record = fn (User $user) => DB::transaction(fn () => app(Outbox::class)->record('GoogleAccountLinked', 'user', $user->id, []));
});

it('projects real checkout events once and scopes cursor reads and read receipts to the owner', function () {
    $this->prepareCheckout();
    ($this->login)($this->buyer);
    app(CustomerCart::class)->set($this->buyer->id, $this->rts);
    [$quote] = ($this->finalQuote)($this->body);
    $number = ($this->confirm)($quote)->assertCreated()->json('data.order_numbers.0');
    $events = DB::table('outbox_events')->whereIn('event_type', ['OrderCreated', 'InvoiceIssued'])->pluck('id');
    expect($events)->toHaveCount(2);
    foreach ($events as $event) {
        ($this->consume)($event);
        ($this->consume)($event);
    }
    $this->assertDatabaseCount('notifications', 2);
    $this->assertDatabaseCount('notification_deliveries', 2);
    $this->assertDatabaseHas('notification_deliveries', ['status' => 'PENDING', 'template' => 'OrderCreated']);

    $base = '/api/v1/notifications';
    $first = $this->getJson($base.'?per_page=1')->assertOk()->assertJsonCount(1, 'data');
    $cursor = $first->json('meta.next_cursor');
    expect($cursor)->not->toBeNull();
    $second = $this->getJson($base.'?per_page=1&cursor='.rawurlencode($cursor))->assertOk()->assertJsonCount(1, 'data');
    expect($second->json('data.0.id'))->not->toBe($first->json('data.0.id'));
    $this->getJson($base.'?cursor=invalid')->assertUnprocessable();
    $order = $this->getJson($base)->assertOk()->collect('data')->firstWhere('template', 'OrderCreated');
    expect($order['link'])->toBe('/pesanan/'.$number)->and($order['read_at'])->toBeNull();

    $receipt = $base.'/'.$order['id'].'/read-receipt';
    $readAt = $this->putJson($receipt)->assertOk()->json('data.read_at');
    expect($readAt)->not->toBeNull();
    $this->travel(1)->minutes();
    $this->putJson($receipt)->assertOk()->assertJsonPath('data.read_at', $readAt);
    $this->getJson($base.'?unread=true')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.template', 'InvoiceIssued');

    ($this->login)(User::factory()->create());
    $this->getJson($base)->assertOk()->assertJsonPath('data', []);
    $this->putJson($receipt)->assertNotFound()->assertJsonPath('code', 'NOT_FOUND');
});

it('retries an explicit temporary SMTP refusal and never resends a sent row', function () {
    $user = User::factory()->create();
    ($this->consume)(($this->record)($user));
    $id = DB::table('notification_deliveries')->value('id');
    $job = new SendNotificationEmail($id);
    $mailer = Mail::getFacadeRoot();
    Mail::shouldReceive('to')->once()->with($user->email)->andThrow(new UnexpectedResponseException('Try again later', 451));
    $job->handle();
    $job->handle();
    $this->assertDatabaseHas('notification_deliveries', ['id' => $id, 'status' => 'PENDING', 'attempts' => 1]);

    $this->travel(11)->seconds();
    $this->artisan('notifications:dispatch')->expectsOutput('Notification emails queued: 1')->assertSuccessful();
    Mail::swap($mailer);
    $mail = Mail::fake();
    $job->handle();
    $job->handle();
    $mail->assertSent(LifecycleMail::class, 1);
    $mail->assertSent(LifecycleMail::class, fn (LifecycleMail $notice) => $notice->hasTo($user->email));
    $this->assertDatabaseHas('notification_deliveries', ['id' => $id, 'status' => 'SENT', 'attempts' => 2]);
    $this->assertDatabaseCount('notifications', 1);
});

it('quarantines crashed SMTP attempts instead of automatically sending them again', function () {
    ($this->consume)(($this->record)(User::factory()->create()));
    $id = DB::table('notification_deliveries')->value('id');
    DB::table('notification_deliveries')->where('id', $id)->update([
        'status' => 'SENDING', 'lease_until' => now()->subSecond(), 'attempts' => 1, 'provider_ref' => 'test-ref',
    ]);
    $this->artisan('notifications:dispatch')->expectsOutput('Notification emails queued: 0')->assertSuccessful();
    $this->assertDatabaseHas('notification_deliveries', ['id' => $id, 'status' => 'AMBIGUOUS', 'attempts' => 1, 'provider_ref' => 'test-ref']);
    $this->artisan('notifications:reconcile', ['id' => $id, 'outcome' => 'sent'])->expectsOutput('Delivery reconciled: SENT')->assertSuccessful();
    Mail::fake();
    (new SendNotificationEmail($id))->handle();
    Mail::assertNothingSent();
    $this->assertDatabaseCount('notifications', 1);
});
