<?php

namespace App\Services;

use App\Mail\VerifyEmailMail;
use App\Models\AuditLog;
use App\Models\User;
use App\Models\VerificationCode;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class EmailVerificationService
{
    public function __construct(private VerificationCodeService $codes)
    {
    }

    /**
     * Gera um código de 6 dígitos e envia-o por email ao utilizador.
     *
     * Devolve false se o envio falhar (a conta continua criada, o código pode ser reenviado).
     */
    public function send(User $user): bool
    {
        $minutes = (int) config('auth.verification.expire', 30);
        $code = $this->codes->issue($user->email, VerificationCode::TYPE_EMAIL_VERIFICATION, $minutes);

        try {
            Mail::to($user->email)->send(new VerifyEmailMail($user, $code, $minutes));
        } catch (\Throwable $e) {
            Log::error('Falha ao enviar código de confirmação de conta', [
                'user_id' => $user->id,
                'email' => $user->email,
                'error' => $e->getMessage(),
            ]);

            return false;
        }

        AuditLog::log(
            'Confirmação de Email',
            "Código de confirmação de email enviado para '{$user->email}'",
            ['user_id' => $user->id, 'email' => $user->email],
            auth()->user()
        );

        return true;
    }

    /**
     * Foi enviado um código há menos de 60 segundos?
     */
    public function recentlySent(string $email): bool
    {
        return $this->codes->recentlyIssued($email, VerificationCode::TYPE_EMAIL_VERIFICATION);
    }
}
