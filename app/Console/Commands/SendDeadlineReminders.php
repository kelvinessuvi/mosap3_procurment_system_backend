<?php

namespace App\Console\Commands;

use App\Mail\DeadlineReminderMail;
use App\Models\Acquisition;
use App\Models\Notification;
use App\Models\QuotationRequest;
use App\Models\ReminderLog;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Mail;

class SendDeadlineReminders extends Command
{
    protected $signature = 'app:send-deadline-reminders';

    protected $description = 'Send automatic deadline and delivery reminders (T-2, T-1, due date, overdue)';

    public function handle(): int
    {
        $today = Carbon::today();
        $count = 0;

        QuotationRequest::whereIn('status', ['sent', 'in_progress'])
            ->whereNotNull('deadline')
            ->with(['user', 'quotationSuppliers.supplier'])
            ->get()
            ->each(function (QuotationRequest $qr) use ($today, &$count) {
                $count += $this->processQuotationRequest($qr, $today);
            });

        Acquisition::whereIn('status', ['pending', 'in_progress'])
            ->whereNotNull('expected_delivery_date')
            ->with(['user', 'supplier', 'quotationRequest'])
            ->get()
            ->each(function (Acquisition $acq) use ($today, &$count) {
                $count += $this->processAcquisition($acq, $today);
            });

        $this->info("Reminders processed: {$count}");

        return Command::SUCCESS;
    }

    protected function determineTrigger(Carbon $deadline, Carbon $today): ?string
    {
        $daysUntil = $today->startOfDay()->diffInDays($deadline->startOfDay(), false);

        return match (true) {
            $daysUntil < 0 => 'overdue',
            $daysUntil === 0 => 'due_date',
            $daysUntil === 1 => 't_minus_1',
            $daysUntil === 2 => 't_minus_2',
            default => null,
        };
    }

    protected function processQuotationRequest(QuotationRequest $qr, Carbon $today): int
    {
        $trigger = $this->determineTrigger($qr->deadline, $today);

        if (!$trigger) {
            return 0;
        }

        $count = 0;
        $ref = $qr->reference_number;
        $deadline = $qr->deadline;
        $daysOverdue = max(0, -$today->startOfDay()->diffInDays($deadline->startOfDay(), false));

        $title = match ($trigger) {
            't_minus_2' => 'Prazo em 2 dias',
            't_minus_1' => 'Prazo amanhã',
            'due_date' => 'Prazo termina HOJE',
            'overdue' => 'Prazo EXCEDIDO',
        };

        $message = match ($trigger) {
            't_minus_2' => "Faltam 2 dias para o prazo da cotação #{$ref}. O prazo termina em {$deadline->format('d/m/Y H:i')}.",
            't_minus_1' => "Amanhã termina o prazo da cotação #{$ref}. O prazo limite é {$deadline->format('d/m/Y H:i')}.",
            'due_date' => "O prazo da cotação #{$ref} termina HOJE às {$deadline->format('H:i')}.",
            'overdue' => "O prazo da cotação #{$ref} foi EXCEDIDO há {$daysOverdue} dia(s). Prazo limite era {$deadline->format('d/m/Y H:i')}.",
        };

        $data = [
            'quotation_request_id' => $qr->id,
            'reference_number' => $ref,
            'title' => $qr->title,
            'deadline' => $deadline->format('Y-m-d H:i:s'),
            'reminder_type' => $trigger,
        ];

        if ($qr->user && $this->logSent(QuotationRequest::class, $qr->id, $trigger, 'in_app', $today)) {
            Notification::create([
                'user_id' => $qr->user_id,
                'type' => 'deadline_reminder',
                'title' => $title,
                'message' => $message,
                'data' => $data,
            ]);
            $count++;
        }

        $recipients = $this->buildInternalRecipients($qr->user);

        $qr->quotationSuppliers
            ->filter(fn ($qs) => !in_array($qs->status, ['submitted', 'declined'], true))
            ->each(function ($qs) use (&$recipients) {
                $recipients[] = [
                    'address' => $qs->supplier->email,
                    'kind' => 'supplier',
                    'name' => $qs->supplier->company_name,
                    'token' => $qs->token,
                ];
            });

        if (!empty($recipients) && $this->logSent(QuotationRequest::class, $qr->id, $trigger, 'email', $today)) {
            foreach ($recipients as $recipient) {
                Mail::to($recipient['address'])->send(new DeadlineReminderMail(
                    entityType: 'quotation',
                    entity: $qr,
                    trigger: $trigger,
                    recipientKind: $recipient['kind'],
                    token: $recipient['token'] ?? null,
                    recipientName: $recipient['name'],
                ));
            }
            $count++;
        }

        return $count;
    }

    protected function processAcquisition(Acquisition $acq, Carbon $today): int
    {
        $trigger = $this->determineTrigger($acq->expected_delivery_date, $today);

        if (!$trigger) {
            return 0;
        }

        $count = 0;
        $ref = $acq->reference_number;
        $delivery = $acq->expected_delivery_date;
        $activityTitle = $acq->quotationRequest?->title;
        $daysOverdue = max(0, -$today->startOfDay()->diffInDays($delivery->startOfDay(), false));

        $title = match ($trigger) {
            't_minus_2' => 'Entrega em 2 dias',
            't_minus_1' => 'Entrega amanhã',
            'due_date' => 'Entrega HOJE',
            'overdue' => 'Entrega ATRASADA',
        };

        $message = match ($trigger) {
            't_minus_2' => "Faltam 2 dias para a entrega do pedido #{$ref}" . ($activityTitle ? " ({$activityTitle})" : '') . ". Data prevista: {$delivery->format('d/m/Y')}.",
            't_minus_1' => "A entrega do pedido #{$ref}" . ($activityTitle ? " ({$activityTitle})" : '') . " está prevista para AMANHÃ ({$delivery->format('d/m/Y')}).",
            'due_date' => "A entrega do pedido #{$ref}" . ($activityTitle ? " ({$activityTitle})" : '') . " está prevista para HOJE.",
            'overdue' => "A entrega do pedido #{$ref}" . ($activityTitle ? " ({$activityTitle})" : '') . " está ATRASADA há {$daysOverdue} dia(s). Data prevista era {$delivery->format('d/m/Y')}.",
        };

        $data = [
            'acquisition_id' => $acq->id,
            'reference_number' => $ref,
            'title' => $activityTitle,
            'expected_delivery_date' => $delivery->format('Y-m-d'),
            'supplier_name' => $acq->supplier?->company_name,
            'reminder_type' => $trigger,
        ];

        if ($acq->user && $this->logSent(Acquisition::class, $acq->id, $trigger, 'in_app', $today)) {
            Notification::create([
                'user_id' => $acq->user_id,
                'type' => 'deadline_reminder',
                'title' => $title,
                'message' => $message,
                'data' => $data,
            ]);
            $count++;
        }

        $recipients = $this->buildInternalRecipients($acq->user);

        if ($acq->supplier) {
            $recipients[] = [
                'address' => $acq->supplier->email,
                'kind' => 'supplier',
                'name' => $acq->supplier->company_name,
                'token' => null,
            ];
        }

        if (!empty($recipients) && $this->logSent(Acquisition::class, $acq->id, $trigger, 'email', $today)) {
            foreach ($recipients as $recipient) {
                Mail::to($recipient['address'])->send(new DeadlineReminderMail(
                    entityType: 'delivery',
                    entity: $acq,
                    trigger: $trigger,
                    recipientKind: $recipient['kind'],
                    recipientName: $recipient['name'],
                    token: $recipient['token'] ?? null,
                ));
            }
            $count++;
        }

        return $count;
    }

    protected function buildInternalRecipients(?Model $user): array
    {
        $recipients = [];

        if (config('mail.procurement_address')) {
            $recipients[] = [
                'address' => config('mail.procurement_address'),
                'kind' => 'internal',
                'name' => 'Procurement MOSAP3',
            ];
        }

        if ($user && !in_array($user->email, array_column($recipients, 'address'), true)) {
            $recipients[] = [
                'address' => $user->email,
                'kind' => 'internal',
                'name' => $user->name,
            ];
        }

        return $recipients;
    }

    protected function logSent(string $entityType, int $entityId, string $trigger, string $channel, Carbon $sentDate): bool
    {
        $log = ReminderLog::firstOrCreate([
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'reminder_type' => $trigger,
            'channel' => $channel,
            'sent_date' => $sentDate->toDateString(),
        ]);

        return $log->wasRecentlyCreated;
    }
}
