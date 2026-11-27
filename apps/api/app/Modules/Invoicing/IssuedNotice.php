<?php

namespace App\Modules\Invoicing;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

final class IssuedNotice extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(private readonly string $number = '') {}

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
