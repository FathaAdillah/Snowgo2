<?php

use App\Modules\Foundation\Files\ScanQueue;
use App\Modules\Foundation\Outbox\Relay;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('outbox:relay', function (Relay $relay) {
    $this->info('Events routed: '.$relay->publish());
})->purpose('Publish committed outbox events');

Artisan::command('outbox:reconcile', function (Relay $relay) {
    $this->info('Deliveries recovered: '.$relay->reconcile());
})->purpose('Recover pending deliveries after queue loss');

Schedule::command('outbox:relay')->everyMinute();
Schedule::command('outbox:reconcile')->everyMinute();
Artisan::command('files:dispatch-scans', function (ScanQueue $queue, Dispatcher $bus) {
    $this->info('File scans queued: '.$queue->dispatchDue($bus));
});
Artisan::command('files:prune', function (ScanQueue $queue) {
    $this->info('Unreferenced objects removed: '.$queue->prune());
});
Schedule::command('files:dispatch-scans')->everyMinute();
Schedule::command('files:prune')->daily()->withoutOverlapping();
Schedule::call(fn () => DB::table('idempotency_keys')->where('expires_at', '<=', now())->delete())
    ->name('prune-idempotency-keys')->daily();
