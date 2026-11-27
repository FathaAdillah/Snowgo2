<?php

namespace App\Modules\Identity\Google;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

final class LinkedNotice extends Notification implements ShouldQueue
{
    use Queueable;

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
