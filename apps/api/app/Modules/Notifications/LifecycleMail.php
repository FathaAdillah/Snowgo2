<?php

namespace App\Modules\Notifications;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

final class LifecycleMail extends Mailable
{
    /** @param array{title:string,body:string,link:string} $notice */
    public function __construct(public readonly array $notice) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->notice['title']);
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.notification', with: [
            'body' => $this->notice['body'],
            'url' => $this->notice['link'] === '' ? null : rtrim((string) config('app.url'), '/').$this->notice['link'],
        ]);
    }
}
