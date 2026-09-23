<?php

namespace App\Services;

use App\Models\VerificationCode;
use Carbon\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class VerificationCodeService
{
    /** Tentativas erradas permitidas por código antes de este ser inutilizado. */
    public const MAX_ATTEMPTS = 5;

    /** Resultados possíveis de uma verificação. */
    public const OK = 'ok';
    public const INVALID = 'invalid';
    public const EXPIRED = 'expired';
    public const TOO_MANY_ATTEMPTS = 'too_many_attempts';

    /**
     * Gera um novo código de 6 dígitos, invalidando os anteriores do mesmo tipo.
     *
     * Devolve o código em claro — só aqui ele existe; na base de dados fica o hash.
     */
    public function issue(string $email, string $type, int $expiresInMinutes): string
    {
        VerificationCode::where('email', $email)
            ->where('type', $type)
            ->active()
            ->update(['consumed_at' => now()]);

        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);

        VerificationCode::create([
            'email' => $email,
            'type' => $type,
            'code_hash' => Hash::make($code),
            'expires_at' => now()->addMinutes($expiresInMinutes),
        ]);

        return $code;
    }

    /**
     * Gera um token de link (activação de conta), invalidando os anteriores do mesmo tipo.
     *
     * Ao contrário de issue(), não há código de 6 dígitos: o link é a credencial.
     */
    public function issueLinkToken(string $email, string $type, int $expiresInHours): string
    {
        VerificationCode::where('email', $email)
            ->where('type', $type)
            ->active()
            ->update(['consumed_at' => now()]);

        $token = Str::random(64);
        $expiresAt = now()->addHours($expiresInHours);

        VerificationCode::create([
            'email' => $email,
            'type' => $type,
            'code_hash' => null,
            'expires_at' => $expiresAt,
            'token' => $token,
            'token_expires_at' => $expiresAt,
        ]);

        return $token;
    }

    /**
     * Localiza um registo apenas pelo token do link (o email não vem no URL).
     *
     * Devolve null se o token não existir, já tiver sido usado ou estiver expirado.
     */
    public function findByLinkToken(string $token, string $type): ?VerificationCode
    {
        $record = VerificationCode::where('type', $type)
            ->where('token', $token)
            ->active()
            ->first();

        if (! $record || ! $record->token_expires_at || $record->token_expires_at->isPast()) {
            return null;
        }

        return $record;
    }

    /**
     * Momento em que o último código deste tipo foi emitido (para limitar reenvios).
     */
    public function lastIssuedAt(string $email, string $type): ?Carbon
    {
        return VerificationCode::where('email', $email)
            ->where('type', $type)
            ->latest('id')
            ->value('created_at');
    }

    /**
     * Foi emitido um código há menos de $seconds segundos?
     */
    public function recentlyIssued(string $email, string $type, int $seconds = 60): bool
    {
        $last = $this->lastIssuedAt($email, $type);

        return $last && $last->addSeconds($seconds)->isFuture();
    }

    /**
     * Valida o código submetido contra o último código activo.
     *
     * Devolve ['status' => self::OK, 'record' => VerificationCode] em caso de sucesso,
     * ou ['status' => ..., 'remaining_attempts' => int] em caso de falha.
     */
    public function verify(string $email, string $type, string $code): array
    {
        $record = VerificationCode::where('email', $email)
            ->where('type', $type)
            ->active()
            ->latest('id')
            ->first();

        if (! $record) {
            return ['status' => self::INVALID, 'remaining_attempts' => 0];
        }

        if ($record->isExpired()) {
            return ['status' => self::EXPIRED, 'remaining_attempts' => 0];
        }

        if ($record->attempts >= self::MAX_ATTEMPTS) {
            return ['status' => self::TOO_MANY_ATTEMPTS, 'remaining_attempts' => 0];
        }

        $record->increment('attempts');

        if (! Hash::check($code, $record->code_hash)) {
            $remaining = max(0, self::MAX_ATTEMPTS - $record->attempts);

            // Esgotadas as tentativas, o código deixa de servir: é preciso pedir outro.
            if ($remaining === 0) {
                $record->forceFill(['consumed_at' => now()])->save();

                return ['status' => self::TOO_MANY_ATTEMPTS, 'remaining_attempts' => 0];
            }

            return ['status' => self::INVALID, 'remaining_attempts' => $remaining];
        }

        return ['status' => self::OK, 'record' => $record];
    }

    /**
     * Emite o token intermédio que autoriza a troca de senha depois do código validado.
     */
    public function issueToken(VerificationCode $record, int $expiresInMinutes): string
    {
        $token = Str::random(64);

        $record->forceFill([
            'token' => $token,
            'token_expires_at' => now()->addMinutes($expiresInMinutes),
        ])->save();

        return $token;
    }

    /**
     * Localiza o registo associado a um token ainda válido.
     */
    public function findByToken(string $email, string $type, string $token): ?VerificationCode
    {
        $record = VerificationCode::where('email', $email)
            ->where('type', $type)
            ->where('token', $token)
            ->active()
            ->first();

        if (! $record || ! $record->token_expires_at || $record->token_expires_at->isPast()) {
            return null;
        }

        return $record;
    }

    /**
     * Marca o código como usado — não pode voltar a servir.
     */
    public function consume(VerificationCode $record): void
    {
        $record->forceFill([
            'consumed_at' => now(),
            'token' => null,
            'token_expires_at' => null,
        ])->save();
    }

    /**
     * Invalida todos os códigos activos de um tipo (ex.: depois de trocar a senha).
     */
    public function invalidateAll(string $email, string $type): void
    {
        VerificationCode::where('email', $email)
            ->where('type', $type)
            ->active()
            ->update(['consumed_at' => now()]);
    }
}
