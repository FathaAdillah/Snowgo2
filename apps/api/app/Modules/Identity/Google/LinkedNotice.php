<?php

namespace App\Modules\Identity\Google;

use App\Models\User;
use App\Modules\Foundation\Outbox\EventHandler;
use App\Modules\Foundation\Outbox\RecordedEvent;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

final class LinkedNotice extends Notification implements EventHandler, ShouldQueue
{
    use Queueable;

    public function handle(RecordedEvent $event): void
    {
        User::query()->find($event->aggregateId)?->notify((new self)->onQueue('notifications'));
    }

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)->subject('Akun Google ditautkan ke Snowego')
            ->line('Akun Google baru saja ditautkan ke akun pelanggan Anda.')
            ->line('Jika ini bukan Anda, segera hubungi dukungan Snowego.');
    }
}
