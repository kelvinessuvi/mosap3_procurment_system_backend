<?php

namespace App\Mail;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class VerifyEmailMail extends Mailable
{
    use Queueable, SerializesModels;

    public $user;
    public $code;
    public $expiresInMinutes;

    public function __construct(User $user, string $code, int $expiresInMinutes)
    {
        $this->user = $user;
        $this->code = $code;
        $this->expiresInMinutes = $expiresInMinutes;
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Código de confirmação: ' . $this->code . ' - MOSAP3 Procurement',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.verify_email',
        );
    }

    public function attachments(): array
    {
        return [];
    }
}
