<?php

namespace App\Mail;

use App\Models\QuotationResponse;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class ProposalRejectedMail extends Mailable
{
    use Queueable, SerializesModels;

    public $quotationResponse;
    public $supplier;
    public $quotationRequest;

    /**
     * Create a new message instance.
     */
    public function __construct(QuotationResponse $quotationResponse)
    {
        $this->quotationResponse = $quotationResponse;
        $this->supplier = $quotationResponse->quotationSupplier->supplier;
        $this->quotationRequest = $quotationResponse->quotationSupplier->quotationRequest;
    }

    /**
     * Get the message envelope.
     */
    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Proposta Recusada - ' . $this->quotationRequest->reference_number,
        );
    }

    /**
     * Get the message content definition.
     */
    public function content(): Content
    {
        return new Content(
            view: 'emails.proposal_rejected',
        );
    }

    /**
     * Get the attachments for the message.
     *
     * @return array<int, \Illuminate\Mail\Mailables\Attachment>
     */
    public function attachments(): array
    {
        return [];
    }
}
