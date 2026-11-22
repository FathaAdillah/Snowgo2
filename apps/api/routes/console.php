<?php

use App\Modules\Foundation\Outbox\Relay;
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
Schedule::call(fn () => DB::table('idempotency_keys')->where('expires_at', '<=', now())->delete())
    ->name('prune-idempotency-keys')->daily();
