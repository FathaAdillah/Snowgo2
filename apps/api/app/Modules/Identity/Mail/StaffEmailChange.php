<?php

namespace App\Modules\Identity\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

final class StaffEmailChange extends Mailable
{
    public function __construct(public readonly string $token) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Verifikasi email login baru');
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.identity.staff-email-change');
    }
}
