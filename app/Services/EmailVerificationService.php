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
     * Gera um link de activação e envia-o por email ao utilizador.
     *
     * Devolve false se o envio falhar (a conta continua criada, o link pode ser reenviado).
     */
    public function send(User $user): bool
    {
        $hours = (int) config('auth.verification.expire', 48);
        $token = $this->codes->issueLinkToken(
            $user->email,
            VerificationCode::TYPE_EMAIL_VERIFICATION,
            $hours
        );

        try {
            Mail::to($user->email)->send(new VerifyEmailMail($user, $this->verificationUrl($token), $hours));
        } catch (\Throwable $e) {
            Log::error('Falha ao enviar link de activação de conta', [
                'user_id' => $user->id,
                'email' => $user->email,
                'error' => $e->getMessage(),
            ]);

            return false;
        }

        AuditLog::log(
            'Confirmação de Email',
            "Link de activação (definição de senha) enviado para '{$user->email}'",
            ['user_id' => $user->id, 'email' => $user->email],
            auth()->user()
        );

        return true;
    }

    /**
     * URL de activação enviado no email.
     */
    public function verificationUrl(string $token): string
    {
        return url('/email/verify/' . $token);
    }

    /**
     * Foi enviado um link há menos de 60 segundos?
     */
    public function recentlySent(string $email): bool
    {
        return $this->codes->recentlyIssued($email, VerificationCode::TYPE_EMAIL_VERIFICATION);
    }
}
