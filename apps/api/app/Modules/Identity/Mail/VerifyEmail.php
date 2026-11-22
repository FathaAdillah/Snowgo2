<?php

namespace App\Modules\Identity\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

final class VerifyEmail extends Mailable
{
    public function __construct(public readonly string $token) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Verifikasi email akun Snowego');
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.identity.verify-email', with: [
            'url' => $this->link(),
        ]);
    }

    private function link(): string
    {
        return rtrim((string) config('app.url'), '/').'/verify-email?'.http_build_query(['token' => $this->token]);
    }
}
