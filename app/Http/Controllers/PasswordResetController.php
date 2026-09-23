<?php

namespace App\Http\Controllers;

use App\Mail\ResetPasswordMail;
use App\Models\AuditLog;
use App\Models\User;
use App\Models\VerificationCode;
use App\Services\VerificationCodeService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

/**
 * @OA\Tag(
 *     name="Recuperação de Senha",
 *     description="Recuperação de conta por código de 6 dígitos enviado por email"
 * )
 */
class PasswordResetController extends Controller
{
    public function __construct(private VerificationCodeService $codes)
    {
    }

    /**
     * @OA\Post(
     *     path="/api/password/forgot",
     *     summary="Pedir código de recuperação de senha",
     *     tags={"Recuperação de Senha"},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"email"},
     *             @OA\Property(property="email", type="string", format="email", example="joao.silva@mosap3.ao")
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Se a conta existir, foi enviado um código de 6 dígitos",
     *         @OA\JsonContent(
     *             @OA\Property(property="message", type="string"),
     *             @OA\Property(property="expires_in_minutes", type="integer", example=15)
     *         )
     *     ),
     *     @OA\Response(response=429, description="Código pedido há menos de 60 segundos")
     * )
     */
    public function forgot(Request $request)
    {
        $request->validate(['email' => 'required|email']);

        $minutes = (int) config('auth.reset_code.expire', 15);

        // Resposta genérica: não revela se o email está registado na plataforma.
        $generic = response()->json([
            'message' => 'Se existir uma conta com este email, enviámos um código de recuperação de 6 dígitos.',
            'expires_in_minutes' => $minutes,
        ]);

        $user = User::where('email', $request->email)->first();

        if (! $user || ! $user->is_active) {
            return $generic;
        }

        if ($this->codes->recentlyIssued($user->email, VerificationCode::TYPE_PASSWORD_RESET)) {
            return response()->json([
                'message' => 'Já foi enviado um código há pouco tempo. Aguarde um minuto antes de pedir outro.',
            ], 429);
        }

        $code = $this->codes->issue($user->email, VerificationCode::TYPE_PASSWORD_RESET, $minutes);

        try {
            Mail::to($user->email)->send(new ResetPasswordMail($user, $code, $minutes));
        } catch (\Throwable $e) {
            Log::error('Falha ao enviar código de recuperação de senha', [
                'user_id' => $user->id,
                'email' => $user->email,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'message' => 'Não foi possível enviar o email de recuperação. Tente novamente mais tarde.',
            ], 500);
        }

        AuditLog::log(
            'Recuperação de Senha',
            "Código de recuperação de senha enviado para '{$user->email}'",
            ['user_id' => $user->id, 'email' => $user->email],
            $user
        );

        return $generic;
    }

    /**
     * @OA\Post(
     *     path="/api/password/verify-code",
     *     summary="Validar o código de recuperação",
     *     description="Passo opcional: confirma o código e devolve um token de curta duração que autoriza a troca de senha, para que o utilizador não tenha de reintroduzir o código.",
     *     tags={"Recuperação de Senha"},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"email","code"},
     *             @OA\Property(property="email", type="string", format="email"),
     *             @OA\Property(property="code", type="string", example="482915")
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Código válido",
     *         @OA\JsonContent(
     *             @OA\Property(property="message", type="string"),
     *             @OA\Property(property="token", type="string"),
     *             @OA\Property(property="expires_in_minutes", type="integer", example=15)
     *         )
     *     ),
     *     @OA\Response(response=422, description="Código inválido, expirado ou tentativas esgotadas")
     * )
     */
    public function verifyCode(Request $request)
    {
        $request->validate([
            'email' => 'required|email',
            'code' => 'required|digits:6',
        ]);

        $result = $this->codes->verify(
            $request->email,
            VerificationCode::TYPE_PASSWORD_RESET,
            $request->code
        );

        if ($result['status'] !== VerificationCodeService::OK) {
            return $this->codeError($this->messageFor($result['status']), $result['remaining_attempts'] ?? 0);
        }

        $tokenMinutes = (int) config('auth.reset_code.token_expire', 15);
        $token = $this->codes->issueToken($result['record'], $tokenMinutes);

        return response()->json([
            'message' => 'Código validado. Defina agora a nova senha.',
            'token' => $token,
            'expires_in_minutes' => $tokenMinutes,
        ]);
    }

    /**
     * @OA\Post(
     *     path="/api/password/reset",
     *     summary="Definir a nova senha",
     *     description="Aceita o código de 6 dígitos directamente, ou o token devolvido por /api/password/verify-code.",
     *     tags={"Recuperação de Senha"},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"email","password","password_confirmation"},
     *             @OA\Property(property="email", type="string", format="email"),
     *             @OA\Property(property="code", type="string", example="482915", description="Obrigatório se não enviar 'token'"),
     *             @OA\Property(property="token", type="string", description="Obrigatório se não enviar 'code'"),
     *             @OA\Property(property="password", type="string", format="password", example="novaSenha123"),
     *             @OA\Property(property="password_confirmation", type="string", format="password", example="novaSenha123")
     *         )
     *     ),
     *     @OA\Response(response=200, description="Senha redefinida com sucesso"),
     *     @OA\Response(response=422, description="Código/token inválido ou expirado")
     * )
     */
    public function reset(Request $request)
    {
        $request->validate([
            'email' => 'required|email',
            'code' => 'required_without:token|nullable|digits:6',
            'token' => 'required_without:code|nullable|string',
            'password' => 'required|string|min:8|confirmed',
        ]);

        $user = User::where('email', $request->email)->first();

        if (! $user || ! $user->is_active) {
            return $this->codeError('O código é inválido ou já expirou.');
        }

        if ($request->filled('token')) {
            $record = $this->codes->findByToken(
                $user->email,
                VerificationCode::TYPE_PASSWORD_RESET,
                $request->token
            );

            if (! $record) {
                return $this->codeError('A sessão de recuperação expirou. Peça um novo código.');
            }
        } else {
            $result = $this->codes->verify(
                $user->email,
                VerificationCode::TYPE_PASSWORD_RESET,
                $request->code
            );

            if ($result['status'] !== VerificationCodeService::OK) {
                return $this->codeError($this->messageFor($result['status']), $result['remaining_attempts'] ?? 0);
            }

            $record = $result['record'];
        }

        $user->forceFill([
            'password' => Hash::make($request->password),
            'remember_token' => Str::random(60),
        ])->save();

        $this->codes->consume($record);
        $this->codes->invalidateAll($user->email, VerificationCode::TYPE_PASSWORD_RESET);

        // Sessões antigas deixam de ser válidas após a troca de senha.
        $user->tokens()->delete();

        AuditLog::log(
            'Recuperação de Senha',
            "Utilizador '{$user->name}' redefiniu a senha",
            ['user_id' => $user->id, 'email' => $user->email],
            $user
        );

        return response()->json([
            'message' => 'Senha redefinida com sucesso. Já pode iniciar sessão com a nova senha.',
        ]);
    }

    private function messageFor(string $status): string
    {
        return match ($status) {
            VerificationCodeService::EXPIRED => 'O código expirou. Peça um novo código.',
            VerificationCodeService::TOO_MANY_ATTEMPTS => 'Excedeu o número de tentativas. Peça um novo código.',
            default => 'O código introduzido é inválido.',
        };
    }

    private function codeError(string $message, int $remaining = 0)
    {
        return response()->json([
            'message' => $message,
            'remaining_attempts' => $remaining,
            'errors' => ['code' => [$message]],
        ], 422);
    }
}
