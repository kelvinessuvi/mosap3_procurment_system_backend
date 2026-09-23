<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\User;
use App\Models\VerificationCode;
use App\Services\EmailVerificationService;
use App\Services\VerificationCodeService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @OA\Tag(
 *     name="Activação de Conta",
 *     description="Activação da conta e definição da senha pelo próprio utilizador, a partir do link enviado por email"
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
     * Mostra o formulário de definição de senha (Rota Web, destino do link do email).
     *
     * O token NÃO é consumido aqui: só quando a senha for efectivamente definida.
     */
    public function showActivationForm(string $token)
    {
        $user = $this->userForToken($token);

        if (! $user) {
            return $this->invalidLinkPage();
        }

        return view('auth.set_password', [
            'token' => $token,
            'user' => $user,
        ]);
    }

    /**
     * Define a senha e activa a conta (submissão do formulário web).
     */
    public function activateFromForm(Request $request)
    {
        $request->validate([
            'token' => 'required|string',
            'password' => 'required|string|min:8|confirmed',
        ]);

        $user = $this->activate($request->token, $request->password);

        if (! $user) {
            return $this->invalidLinkPage();
        }

        return view('auth.verification_result', [
            'status' => 'success',
            'title' => 'Conta activada!',
            'message' => 'A sua senha foi definida e a conta está activa. Já pode iniciar sessão na plataforma MOSAP3 Procurement.',
        ]);
    }

    /**
     * @OA\Post(
     *     path="/api/email/check-token",
     *     summary="Validar o token do link de activação",
     *     description="Permite ao frontend alojar a sua própria página de definição de senha: devolve a quem pertence o link antes de mostrar o formulário. Não consome o token.",
     *     tags={"Activação de Conta"},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"token"},
     *             @OA\Property(property="token", type="string", description="Token presente no link do email")
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Token válido",
     *         @OA\JsonContent(
     *             @OA\Property(property="valid", type="boolean", example=true),
     *             @OA\Property(property="name", type="string"),
     *             @OA\Property(property="email", type="string", format="email")
     *         )
     *     ),
     *     @OA\Response(response=410, description="Link inválido, já usado ou expirado")
     * )
     */
    public function checkToken(Request $request)
    {
        $request->validate(['token' => 'required|string']);

        $user = $this->userForToken($request->token);

        if (! $user) {
            return response()->json([
                'valid' => false,
                'message' => 'Este link de activação já foi utilizado ou expirou. Peça um novo link.',
            ], 410);
        }

        return response()->json([
            'valid' => true,
            'name' => $user->name,
            'email' => $user->email,
        ]);
    }

    /**
     * @OA\Post(
     *     path="/api/email/verify",
     *     summary="Definir a senha e activar a conta",
     *     description="Alternativa à rota web /email/verify/{token}, para o caso de ser o frontend a alojar a página de definição de senha.",
     *     tags={"Activação de Conta"},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"token","password","password_confirmation"},
     *             @OA\Property(property="token", type="string", description="Token presente no link do email"),
     *             @OA\Property(property="password", type="string", format="password", example="aMinhaSenha123"),
     *             @OA\Property(property="password_confirmation", type="string", format="password", example="aMinhaSenha123")
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Conta activada e senha definida",
     *         @OA\JsonContent(
     *             @OA\Property(property="message", type="string"),
     *             @OA\Property(property="email_verified", type="boolean", example=true)
     *         )
     *     ),
     *     @OA\Response(response=410, description="Link inválido, já usado ou expirado"),
     *     @OA\Response(response=422, description="Senha inválida")
     * )
     */
    public function verifyApi(Request $request)
    {
        $request->validate([
            'token' => 'required|string',
            'password' => 'required|string|min:8|confirmed',
        ]);

        $user = $this->activate($request->token, $request->password);

        if (! $user) {
            return response()->json([
                'message' => 'Este link de activação já foi utilizado ou expirou. Peça um novo link.',
                'email_verified' => false,
            ], 410);
        }

        return response()->json([
            'message' => 'Conta activada e senha definida. Já pode iniciar sessão.',
            'email_verified' => true,
        ]);
    }

    /**
     * Utilizador a quem pertence um token de activação ainda válido, ou null.
     */
    private function userForToken(string $token): ?User
    {
        $record = $this->codes->findByLinkToken($token, VerificationCode::TYPE_EMAIL_VERIFICATION);

        if (! $record) {
            return null;
        }

        return User::where('email', $record->email)->where('is_active', true)->first();
    }

    /**
     * Define a senha, marca o email como confirmado e consome o token.
     */
    private function activate(string $token, string $password): ?User
    {
        $record = $this->codes->findByLinkToken($token, VerificationCode::TYPE_EMAIL_VERIFICATION);

        if (! $record) {
            return null;
        }

        $user = User::where('email', $record->email)->where('is_active', true)->first();

        if (! $user) {
            return null;
        }

        $user->forceFill([
            'password' => Hash::make($password),
            'email_verified_at' => now(),
            'remember_token' => Str::random(60),
        ])->save();

        $this->codes->consume($record);

        AuditLog::log(
            'Activação de Conta',
            "Utilizador '{$user->name}' activou a conta e definiu a senha",
            ['user_id' => $user->id, 'email' => $user->email],
            $user
        );

        return $user;
    }

    private function invalidLinkPage()
    {
        return response()->view('auth.verification_result', [
            'status' => 'invalid',
            'title' => 'Link inválido ou expirado',
            'message' => 'Este link de activação já foi utilizado ou expirou. Contacte o administrador para receber um novo link.',
        ], 410);
    }

    /**
     * @OA\Post(
     *     path="/api/email/resend-verification",
     *     summary="Reenviar o link de activação (pelo próprio utilizador)",
     *     tags={"Activação de Conta"},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"email"},
     *             @OA\Property(property="email", type="string", format="email")
     *         )
     *     ),
     *     @OA\Response(response=200, description="Se a conta existir e estiver por activar, foi enviado um novo link"),
     *     @OA\Response(response=429, description="Link pedido há menos de 60 segundos")
     * )
     */
    public function resendPublic(Request $request)
    {
        $request->validate(['email' => 'required|email']);

        $user = User::where('email', $request->email)->first();

        // Resposta genérica: não revela se o email existe na plataforma.
        $generic = response()->json([
            'message' => 'Se existir uma conta por activar com este email, foi enviado um novo link de activação.',
        ]);

        if (! $user || $user->hasVerifiedEmail() || ! $user->is_active) {
            return $generic;
        }

        if ($this->verification->recentlySent($user->email)) {
            return response()->json([
                'message' => 'Já foi enviado um link há pouco tempo. Aguarde um minuto antes de pedir outro.',
            ], 429);
        }

        $this->verification->send($user);

        return $generic;
    }

    /**
     * @OA\Post(
     *     path="/api/users/{user}/resend-verification",
     *     summary="Reenviar o link de activação (Admin)",
     *     tags={"Activação de Conta"},
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(name="user", in="path", required=true, @OA\Schema(type="integer")),
     *     @OA\Response(response=200, description="Link reenviado"),
     *     @OA\Response(response=422, description="Conta já activada"),
     *     @OA\Response(response=500, description="Falha no envio do email")
     * )
     */
    public function resend(User $user)
    {
        if ($user->hasVerifiedEmail()) {
            return response()->json([
                'message' => 'A conta deste utilizador já foi activada.',
            ], 422);
        }

        if (! $this->verification->send($user)) {
            return response()->json([
                'message' => 'Não foi possível enviar o email de activação. Tente novamente mais tarde.',
            ], 500);
        }

        return response()->json([
            'message' => 'Link de activação reenviado para ' . $user->email . '.',
        ]);
    }
}
