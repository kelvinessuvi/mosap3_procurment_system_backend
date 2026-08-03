<?php

namespace App\Mail;

use App\Models\NegotiationNotification;
use App\Models\QuotationRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class NegotiationNotificationMail extends Mailable
{
    use Queueable, SerializesModels;

    public $quotation;
    public $notification;
    public $token;
    public $senderUser;

    public function __construct(QuotationRequest $quotation, NegotiationNotification $notification, string $token, $senderUser = null)
    {
        $this->quotation = $quotation;
        $this->notification = $notification;
        $this->token = $token;
        $this->senderUser = $senderUser;
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Solicitação de Revisão: ' . $this->quotation->reference_number,
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.negotiation_notification',
        );
    }
}
