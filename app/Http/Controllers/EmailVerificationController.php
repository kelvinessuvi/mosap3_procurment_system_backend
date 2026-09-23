<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\User;
use App\Models\VerificationCode;
use App\Services\EmailVerificationService;
use App\Services\VerificationCodeService;
use Illuminate\Http\Request;

/**
 * @OA\Tag(
 *     name="Confirmação de Email",
 *     description="Confirmação do endereço de email por código de 6 dígitos"
 * )
 */
class EmailVerificationController extends Controller
{
    public function __construct(
        private EmailVerificationService $verification,
        private VerificationCodeService $codes,
    ) {
    }

    /**
     * @OA\Post(
     *     path="/api/email/verify",
     *     summary="Confirmar email com o código de 6 dígitos",
     *     tags={"Confirmação de Email"},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"email","code"},
     *             @OA\Property(property="email", type="string", format="email", example="joao.silva@mosap3.ao"),
     *             @OA\Property(property="code", type="string", example="482915")
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Email confirmado",
     *         @OA\JsonContent(
     *             @OA\Property(property="message", type="string"),
     *             @OA\Property(property="email_verified", type="boolean", example=true)
     *         )
     *     ),
     *     @OA\Response(response=422, description="Código inválido, expirado ou tentativas esgotadas")
     * )
     */
    public function verify(Request $request)
    {
        $request->validate([
            'email' => 'required|email',
            'code' => 'required|digits:6',
        ]);

        $user = User::where('email', $request->email)->first();

        if (! $user) {
            return $this->codeError('O código é inválido ou já expirou.');
        }

        if ($user->hasVerifiedEmail()) {
            return response()->json([
                'message' => 'Este email já foi confirmado. Pode iniciar sessão.',
                'email_verified' => true,
            ]);
        }

        $result = $this->codes->verify(
            $user->email,
            VerificationCode::TYPE_EMAIL_VERIFICATION,
            $request->code
        );

        if ($result['status'] !== VerificationCodeService::OK) {
            return $this->codeError($this->messageFor($result['status']), $result['remaining_attempts'] ?? 0);
        }

        $this->codes->consume($result['record']);
        $user->markEmailAsVerified();

        AuditLog::log(
            'Confirmação de Email',
            "Utilizador '{$user->name}' confirmou o email '{$user->email}'",
            ['user_id' => $user->id, 'email' => $user->email],
            $user
        );

        return response()->json([
            'message' => 'Email confirmado com sucesso. Já pode iniciar sessão.',
            'email_verified' => true,
        ]);
    }

    /**
     * @OA\Post(
     *     path="/api/email/resend-verification",
     *     summary="Reenviar o código de confirmação (pelo próprio utilizador)",
     *     tags={"Confirmação de Email"},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"email"},
     *             @OA\Property(property="email", type="string", format="email")
     *         )
     *     ),
     *     @OA\Response(response=200, description="Se a conta existir e estiver por confirmar, foi enviado um novo código"),
     *     @OA\Response(response=429, description="Código pedido há menos de 60 segundos")
     * )
     */
    public function resendPublic(Request $request)
    {
        $request->validate(['email' => 'required|email']);

        $user = User::where('email', $request->email)->first();

        // Resposta genérica: não revela se o email existe na plataforma.
        $generic = response()->json([
            'message' => 'Se existir uma conta por confirmar com este email, foi enviado um novo código.',
        ]);

        if (! $user || $user->hasVerifiedEmail() || ! $user->is_active) {
            return $generic;
        }

        if ($this->verification->recentlySent($user->email)) {
            return response()->json([
                'message' => 'Já foi enviado um código há pouco tempo. Aguarde um minuto antes de pedir outro.',
            ], 429);
        }

        $this->verification->send($user);

        return $generic;
    }

    /**
     * @OA\Post(
     *     path="/api/users/{user}/resend-verification",
     *     summary="Reenviar o código de confirmação (Admin)",
     *     tags={"Confirmação de Email"},
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(name="user", in="path", required=true, @OA\Schema(type="integer")),
     *     @OA\Response(response=200, description="Código reenviado"),
     *     @OA\Response(response=422, description="Email já confirmado"),
     *     @OA\Response(response=500, description="Falha no envio do email")
     * )
     */
    public function resend(User $user)
    {
        if ($user->hasVerifiedEmail()) {
            return response()->json([
                'message' => 'O email deste utilizador já foi confirmado.',
            ], 422);
        }

        if (! $this->verification->send($user)) {
            return response()->json([
                'message' => 'Não foi possível enviar o email de confirmação. Tente novamente mais tarde.',
            ], 500);
        }

        return response()->json([
            'message' => 'Código de confirmação reenviado para ' . $user->email . '.',
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
