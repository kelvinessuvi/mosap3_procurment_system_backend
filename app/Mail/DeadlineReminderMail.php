<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class DeadlineReminderMail extends Mailable
{
    use Queueable, SerializesModels;

    public string $entityType;

    public Model $entity;

    public string $trigger;

    public string $recipientKind;

    public ?string $token;

    public ?string $recipientName;

    public function __construct(
        string $entityType,
        Model $entity,
        string $trigger,
        string $recipientKind = 'internal',
        ?string $token = null,
        ?string $recipientName = null
    ) {
        $this->entityType = $entityType;
        $this->entity = $entity;
        $this->trigger = $trigger;
        $this->recipientKind = $recipientKind;
        $this->token = $token;
        $this->recipientName = $recipientName;
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->composeSubject());
    }

    public function content(): Content
    {
        return new Content(view: 'emails.deadline_reminder');
    }

    public function attachments(): array
    {
        return [];
    }

    protected function composeSubject(): string
    {
        $isQuotation = $this->entityType === 'quotation';
        $ref = $this->entity->reference_number;

        if ($this->trigger === 'overdue') {
            $daysOverdue = \Carbon\Carbon::today()->diffInDays(
                $isQuotation ? $this->entity->deadline : $this->entity->expected_delivery_date
            );

            return $isQuotation
                ? "ALERTA: Prazo da Cotação {$ref} EXCEDIDO há {$daysOverdue} dia(s)"
                : "ALERTA: Entrega do Pedido {$ref} ATRASADA há {$daysOverdue} dia(s)";
        }

        $prefix = $isQuotation ? 'Prazo da Cotação' : 'Entrega do Pedido';

        return match ($this->trigger) {
            't_minus_2' => "Lembrete: {$prefix} {$ref} termina em 2 dias",
            't_minus_1' => "Lembrete: {$prefix} {$ref} termina amanhã",
            'due_date' => "Lembrete: {$prefix} {$ref} termina HOJE",
            default => "Lembrete: {$prefix} {$ref}",
        };
    }
}
