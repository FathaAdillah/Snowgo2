<?php

namespace App\Modules\Identity\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

final class ResetPassword extends Mailable
{
    public function __construct(public readonly string $email, public readonly string $token) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Atur ulang password Snowego');
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.identity.reset-password', with: [
            'url' => $this->link(),
        ]);
    }

    private function link(): string
    {
        return rtrim((string) config('app.url'), '/').'/reset-password?'.http_build_query(['email' => $this->email, 'token' => $this->token]);
    }
}
