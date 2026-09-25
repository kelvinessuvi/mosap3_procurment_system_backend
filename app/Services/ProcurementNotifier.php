<?php

namespace App\Services;

use App\Mail\StaffNotificationMail;
use App\Models\Notification;
use App\Models\QuotationRequest;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Notifica a equipa de procurement sobre eventos de um pedido de cotação
 * (propostas submetidas/revistas, declínios, aprovações, revisões...).
 *
 * Destinatários: o técnico que criou o pedido + todos os administradores activos,
 * excluindo quem executou a acção. Cria a notificação in-app e envia email.
 * Uma falha no envio de email nunca interrompe o fluxo de negócio.
 */
class ProcurementNotifier
{
    /**
     * @param  QuotationRequest  $quotationRequest
     * @param  string  $type     Tipo da notificação (ex.: quotation_response_submitted)
     * @param  string  $title    Título curto
     * @param  string  $message  Mensagem principal
     * @param  array   $data     Dados extra guardados na notificação
     * @param  array   $details  Linhas "Etiqueta => valor" mostradas no email
     * @param  User|null  $actor Utilizador que executou a acção (não é notificado)
     */
    public function notifyStaff(
        QuotationRequest $quotationRequest,
        string $type,
        string $title,
        string $message,
        array $data = [],
        array $details = [],
        ?User $actor = null
    ): void {
        $data = array_merge(['quotation_request_id' => $quotationRequest->id], $data);
        $actionUrl = $this->quotationRequestUrl($quotationRequest);

        foreach ($this->recipients($quotationRequest, $actor) as $user) {
            Notification::create([
                'user_id' => $user->id,
                'type' => $type,
                'title' => $title,
                'message' => $message,
                'data' => $data,
            ]);

            if (!$user->email) {
                continue;
            }

            try {
                Mail::to($user->email)->send(new StaffNotificationMail(
                    title: $title,
                    messageText: $message,
                    details: $details,
                    actionUrl: $actionUrl,
                    recipientName: $user->name,
                ));
            } catch (\Throwable $e) {
                Log::warning("Falha ao enviar email de notificação ({$type}) para {$user->email}: {$e->getMessage()}");
            }
        }
    }

    /**
     * Criador do pedido + administradores activos, sem duplicados e sem o autor da acção.
     */
    public function recipients(QuotationRequest $quotationRequest, ?User $actor = null): Collection
    {
        $users = User::where('is_active', true)
            ->where(function ($q) use ($quotationRequest) {
                $q->where('role', 'admin');
                if ($quotationRequest->user_id) {
                    $q->orWhere('id', $quotationRequest->user_id);
                }
            })
            ->get();

        return $users
            ->unique('id')
            ->reject(fn (User $user) => $actor && $user->id === $actor->id)
            ->values();
    }

    private function quotationRequestUrl(QuotationRequest $quotationRequest): ?string
    {
        $base = rtrim((string) config('app.frontend_url'), '/');
        return $base ? "{$base}/aquisicoes?pedido={$quotationRequest->id}" : null;
    }
}
