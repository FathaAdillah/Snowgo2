<?php

use App\Modules\Foundation\Audit\Journal;
use App\Modules\Foundation\Audit\Subject;
use App\Modules\Foundation\Outbox\Outbox;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;

uses(DatabaseTruncation::class);

it('rolls back audit, history and event together with the domain transaction', function () {
    expect(fn () => DB::transaction(function () {
        app(Journal::class)->audit('order.created', Subject::Order, 1, null);
        app(Journal::class)->transition(Subject::Order, 1, 'state', null, 'OPEN', 'create');
        app(Outbox::class)->record('OrderCreated', 'order', 1, []);
        throw new RuntimeException('cancel');
    }))->toThrow(RuntimeException::class, 'cancel');
    foreach (['audit_logs', 'status_histories', 'outbox_events'] as $table) {
        $this->assertDatabaseEmpty($table);
    }
    expect(fn () => app(Outbox::class)->record('OrderCreated', 'order', 1, []))->toThrow(LogicException::class);
});

it('retains only allowed scalar audit fields and rejects changes to history', function () {
    DB::transaction(function () {
        app(Journal::class)->audit('payment.approved', Subject::Payment, 1, null, null, [
            'status' => 'APPROVED', 'password' => 'private', 'role' => ['token' => 'private'],
        ]);
        app(Journal::class)->transition(Subject::Payment, 1, 'state', 'PENDING', 'APPROVED', 'approval');
        app(Outbox::class)->record('PaymentApproved', 'payment', 1, []);
    });
    expect(json_decode(DB::table('audit_logs')->value('after'), true))->toBe(['status' => 'APPROVED'])
        ->and(fn () => DB::table('audit_logs')->delete())->toThrow(QueryException::class)
        ->and(fn () => DB::table('status_histories')->update(['to_status' => 'VOID']))->toThrow(QueryException::class)
        ->and(fn () => DB::transaction(fn () => app(Journal::class)->transition(Subject::Payment, 1, 'state', 'PENDING', 'APPROVED', 'approval')))->toThrow(QueryException::class)
        ->and(fn () => DB::table('outbox_events')->update(['payload' => '{}']))->toThrow(QueryException::class);
});
