<?php

namespace App\Mail;

use App\Models\Supplier;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class SupplierApprovedMail extends Mailable
{
    use Queueable, SerializesModels;

    public $supplier;

    public function __construct(Supplier $supplier)
    {
        $this->supplier = $supplier;
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Registro Aprovado - MOSAP3 Procurement',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.supplier_approved',
        );
    }

    public function attachments(): array
    {
        return [];
    }
}
