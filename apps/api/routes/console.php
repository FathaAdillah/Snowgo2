<?php

use App\Modules\Foundation\Files\ScanQueue;
use App\Modules\Foundation\Outbox\Relay;
use App\Modules\Identity\Staff\StaffInvitations;
use App\Modules\Inventory\StockExpiry;
use App\Modules\Inventory\StockReservations;
use App\Modules\Invoicing\InvoiceDocuments;
use App\Modules\Orders\ChargeConsents;
use App\Modules\Orders\OrderExpiry;
use App\Modules\Payments\PaymentFlow;
use App\Modules\Payments\Settlement;
use App\Modules\Preorders\AllocationLifecycle;
use App\Modules\Preorders\CampaignWindow;
use App\Modules\RequestOrders\QuotationLifecycle;
use Database\Seeders\PermissionSeeder;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('staff:invite {email} {--role=admin}', function (StaffInvitations $invitations) {
    $this->callSilent('db:seed', ['--class' => PermissionSeeder::class, '--force' => true]);
    $data = Validator::make(['email' => $this->argument('email'), 'role' => $this->option('role')], [
        'email' => 'required|email|max:254', 'role' => ['required', Rule::exists('roles', 'name')->where('guard_name', 'web')],
    ])->validate();
    $invitations->invite($data['email'], $data['role']);
    $this->info('Activation email queued. Open the inbox to set a password and enroll MFA.');
})->purpose('Invite a new staff member or reissue a pending invitation without changing its role');

Artisan::command('outbox:relay', function (Relay $relay) {
    $this->info('Events routed: '.$relay->publish());
})->purpose('Publish committed outbox events');

Artisan::command('outbox:reconcile', function (Relay $relay) {
    $this->info('Deliveries recovered: '.$relay->reconcile());
})->purpose('Recover pending deliveries after queue loss');

Schedule::command('outbox:relay')->everyMinute();
Schedule::command('horizon:snapshot')->everyFiveMinutes();
Schedule::command('outbox:reconcile')->everyMinute();
Artisan::command('inventory:expire-holds', function (StockExpiry $expiry, StockReservations $stock) {
    $this->info('Expired RTS orders: '.$expiry->run($stock));
})->purpose('Release expired ACTIVE RTS stock holds once per order');
Schedule::command('inventory:expire-holds')->everyMinute();
Schedule::call(fn () => PaymentFlow::expireDue())->name('payments:expire')->everyMinute()->withoutOverlapping();
Schedule::call(fn () => Settlement::closeWindows())->name('payments:close-windows')->hourly()->withoutOverlapping();
Artisan::command('preorders:advance-windows', function (CampaignWindow $window) {
    $this->info('Campaign windows advanced: '.$window->advanceDue());
});
Artisan::command('preorders:expire-holds', function (AllocationLifecycle $allocations) {
    $this->info('Expired preorder orders: '.$allocations->expireDue());
});
Schedule::command('preorders:advance-windows')->everyMinute();
Schedule::command('preorders:expire-holds')->everyMinute();
Artisan::command('quotations:expire', function () {
    $this->info('Expired quotations: '.QuotationLifecycle::expireDue());
});
Schedule::command('quotations:expire')->everyMinute();
Artisan::command('orders:reconcile-expired-stock', function (OrderExpiry $expiry) {
    $this->info('Reconciled expired RTS orders: '.$expiry->reconcileReleased());
})->purpose('Close unpaid checkouts whose stock expired before order lifecycle tracking was installed');
Artisan::command('files:dispatch-scans', function (ScanQueue $queue, Dispatcher $bus) {
    $this->info('File scans queued: '.$queue->dispatchDue($bus));
});
Artisan::command('files:prune', function (ScanQueue $queue) {
    $this->info('Unreferenced objects removed: '.$queue->prune());
});
Schedule::command('files:dispatch-scans')->everyMinute();
Artisan::command('invoices:dispatch-pdfs', function (Dispatcher $bus) {
    $this->info('Invoice PDFs queued: '.InvoiceDocuments::dispatchDue($bus));
});
Schedule::command('invoices:dispatch-pdfs')->everyMinute();
Schedule::command('files:prune')->daily()->withoutOverlapping();
Schedule::call(fn () => DB::table('idempotency_keys')->where('expires_at', '<=', now())->delete())
    ->name('prune-idempotency-keys')->daily();
Schedule::command('auth:clear-resets')->hourly();
Schedule::call(function () {
    DB::table('checkout_previews')->where('expires_at', '<', now()->subDay())->delete();
    DB::table('shipping_quotes')->whereNull('used_at')->where('expires_at', '<', now()->subDay())->delete();
})->name('prune-checkout-quotes')->hourly();
Schedule::call(fn () => DB::table('staff_email_changes')->where('expires_at', '<=', now())->delete())
    ->name('prune-staff-email-changes')->hourly();
Schedule::call(fn () => DB::table('staff_invitations')->where('expires_at', '<=', now())->delete())
    ->name('prune-staff-invitations')->hourly();
Schedule::call(fn () => DB::table('oauth_link_requests')->where('expires_at', '<=', now())->delete())
    ->name('prune-google-link-requests')->hourly();
Schedule::call(fn () => DB::table('email_verification_tokens')->where('expires_at', '<=', now())->delete())
    ->name('prune-email-verification-tokens')->hourly();

Schedule::call(fn () => ChargeConsents::expire())->name('orders:expire-charges')->everyMinute()->withoutOverlapping();
