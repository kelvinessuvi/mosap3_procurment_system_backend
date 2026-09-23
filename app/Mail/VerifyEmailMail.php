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
    public $verificationUrl;
    public $expiresInHours;

    public function __construct(User $user, string $verificationUrl, int $expiresInHours)
    {
        $this->user = $user;
        $this->verificationUrl = $verificationUrl;
        $this->expiresInHours = $expiresInHours;
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Defina a sua senha e active a conta - MOSAP3 Procurement',
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
