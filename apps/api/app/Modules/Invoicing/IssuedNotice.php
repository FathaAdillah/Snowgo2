<?php

namespace App\Modules\Invoicing;

use App\Models\User;
use App\Modules\Foundation\Outbox\EventHandler;
use App\Modules\Foundation\Outbox\RecordedEvent;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\DB;

final class IssuedNotice extends Notification implements EventHandler, ShouldQueue
{
    use Queueable;

    public function __construct(private readonly string $number = '') {}

    public function handle(RecordedEvent $event): void
    {
        $invoice = DB::table('invoices as i')->join('orders as o', 'o.id', '=', 'i.order_id')->where('i.id', $event->aggregateId)->first(['i.number', 'o.user_id']);
        if ($invoice) {
            User::findOrFail($invoice->user_id)->notify((new self($invoice->number))->onQueue('notifications')->beforeCommit());
        }
    }

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)->subject('Invoice Snowego '.$this->number)->line('Tagihan '.$this->number.' sudah diterbitkan. Silakan masuk ke akun Snowego untuk melihat detail.');
    }
}
