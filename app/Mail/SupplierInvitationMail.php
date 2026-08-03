<?php

namespace App\Mail;

use App\Models\Supplier;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class SupplierInvitationMail extends Mailable
{
    use Queueable, SerializesModels;

    public $supplier;
    public $senderUser;

    public function __construct(Supplier $supplier, $senderUser = null)
    {
        $this->supplier = $supplier;
        $this->senderUser = $senderUser;
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Convite para Registro - MOSAP3 Procurement',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.supplier_invitation',
        );
    }

    public function attachments(): array
    {
        return [];
    }
}
