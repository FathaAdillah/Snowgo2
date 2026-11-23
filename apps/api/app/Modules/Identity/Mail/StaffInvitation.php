<?php

namespace App\Modules\Identity\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

final class StaffInvitation extends Mailable
{
    public function __construct(public readonly string $token) {}

    /**
     * Get the message envelope.
     */
    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Aktivasi staf Snowego',
        );
    }

    /**
     * Get the message content definition.
     */
    public function content(): Content
    {
        return new Content(
            markdown: 'mail.identity.staff-invitation',
            with: ['url' => config('auth.admin_url').'/activate#token='.$this->token],
        );
    }
}
