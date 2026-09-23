<?php

namespace App\Http\Controllers;

use App\Models\Menu;
use App\Models\User;
use App\Services\EmailVerificationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * @OA\Tag(
 *     name="Usuários",
 *     description="Gestão de usuários (Admin apenas)"
 * )
 */
class UserController extends Controller
{
    /**
     * @OA\Get(
     *     path="/api/users",
     *     summary="Listar usuários",
     *     tags={"Usuários"},
     *     security={{"bearerAuth":{}}},
     *     @OA\Response(
     *         response=200,
     *         description="Lista de usuários",
     *         @OA\JsonContent(type="array", @OA\Items(type="object"))
     *     ),
     *     @OA\Response(response=403, description="Não autorizado")
     * )
     */
    public function index()
    {
        // ...
        $users = User::paginate(15);
        return response()->json($users);
    }

    /**
     * @OA\Post(
     *     path="/api/users",
     *     summary="Criar novo usuário (Admin/Técnico)",
     *     tags={"Usuários"},
     *     security={{"bearerAuth":{}}},
     *     description="O administrador não define a senha. É enviado um link de activação ao utilizador, que define a sua própria senha no primeiro acesso.",
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"name", "email", "role"},
     *             @OA\Property(property="name", type="string", example="João Silva"),
     *             @OA\Property(property="email", type="string", format="email", example="joao.silva@mosap3.ao"),
     *             @OA\Property(property="role", type="string", enum={"admin", "procurement_technician"}, example="procurement_technician"),
     *             @OA\Property(property="is_active", type="boolean", example=true)
     *         )
     *     ),
     *     @OA\Response(
     *         response=201,
     *         description="Usuário criado sem senha. É enviado um link de activação para o endereço indicado.",
     *         @OA\JsonContent(
     *             @OA\Property(property="user", type="object"),
     *             @OA\Property(property="verification_email_sent", type="boolean"),
     *             @OA\Property(property="message", type="string")
     *         )
     *     ),
     *     @OA\Response(response=422, description="Erro de validação")
     * )
     */
    public function store(Request $request, EmailVerificationService $verification)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|string|email|max:255|unique:users',
            'role' => 'required|in:admin,procurement_technician',
            'is_active' => 'boolean'
        ]);

        // O administrador não define a senha: o utilizador define a sua no primeiro
        // acesso, pelo link de activação. Até lá fica um hash aleatório que ninguém
        // conhece — a coluna é NOT NULL e nenhuma senha real serve para entrar.
        $validated['password'] = Hash::make(Str::random(64));
        $validated['email_verified_at'] = null;

        $user = User::create($validated);

        // A conta só fica utilizável depois de o utilizador abrir o link de activação e definir a senha.
        $sent = $verification->send($user);

        return response()->json([
            'user' => $user->fresh(),
            'verification_email_sent' => $sent,
            'message' => $sent
                ? 'Utilizador criado. Foi enviado um link para ' . $user->email . ' para o utilizador definir a sua senha.'
                : 'Utilizador criado, mas não foi possível enviar o link de activação. Use a opção de reenvio.',
        ], 201);
    }

    /**
     * Display the specified resource.
     */
    public function show(string $id)
    {
        $user = User::findOrFail($id);
        return response()->json($user);
    }

    /**
     * @OA\Put(
     *     path="/api/users/{id}",
     *     summary="Atualizar usuário",
     *     tags={"Usuários"},
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")),
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             @OA\Property(property="name", type="string"),
     *             @OA\Property(property="email", type="string", format="email"),
     *             @OA\Property(property="password", type="string", format="password", description="Deixe vazio para manter a atual"),
     *             @OA\Property(property="role", type="string", enum={"admin", "procurement_technician"}),
     *             @OA\Property(property="is_active", type="boolean")
     *         )
     *     ),
     *     @OA\Response(response=200, description="Usuário atualizado"),
     *     @OA\Response(response=422, description="Erro de validação")
     * )
     */
    public function update(Request $request, string $id, EmailVerificationService $verification)
    {
        $user = User::findOrFail($id);

        $validated = $request->validate([
            'name' => 'string|max:255',
            'email' => ['email', Rule::unique('users')->ignore($user->id)],
            'password' => 'nullable|string|min:8',
            'role' => 'in:admin,procurement_technician',
            'is_active' => 'boolean'
        ]);

        if (isset($validated['password']) && !empty($validated['password'])) {
            $validated['password'] = Hash::make($validated['password']);
        } else {
            unset($validated['password']);
        }

        $emailChanged = isset($validated['email']) && $validated['email'] !== $user->email;

        $user->update($validated);

        // Novo endereço de email volta a exigir confirmação.
        if ($emailChanged) {
            $verification->send($user);
        }

        return response()->json($user->fresh());
    }

    /**
     * @OA\Delete(
     *     path="/api/users/{id}",
     *     summary="Remover usuário",
     *     tags={"Usuários"},
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")),
     *     @OA\Response(response=204, description="Usuário removido"),
     *     @OA\Response(response=404, description="Usuário não encontrado")
     * )
     */
    public function destroy(string $id)
    {
        $user = User::findOrFail($id);
        $user->delete();

        return response()->json(null, 204);
    }

    /**
     * @OA\Get(
     *     path="/api/user/permissions",
     *     summary="Obter menus e permissões do utilizador autenticado",
     *     description="Devolve a árvore de menus com as permissões do utilizador. Menus sem permissão são omitidos. Admin recebe todos os menus.",
     *     tags={"Usuários"},
     *     security={{"bearerAuth":{}}},
     *     @OA\Response(response=200, description="Lista de menus com permissões")
     * )
     */
    public function myPermissions(Request $request)
    {
        $user = $request->user();

        if ($user->role !== 'admin') {
            $user->load('menuPermissions');
        }

        $menus = Menu::with('children')
            ->whereNull('parent_id')
            ->where('is_active', true)
            ->orderBy('order')
            ->get()
            ->map(fn ($menu) => $this->formatMenu($menu, $user))
            ->filter()
            ->values();

        return response()->json($menus);
    }

    private function formatMenu(Menu $menu, User $user): ?array
    {
        if ($user->role === 'admin') {
            $permissions = ['read', 'write'];
        } else {
            $perm = $user->menuPermissions->firstWhere('id', $menu->id);
            $permissions = match ($perm?->pivot->level) {
                'write' => ['write'],
                'read' => ['read'],
                default => [],
            };
        }

        $children = $menu->children
            ->map(fn ($child) => $this->formatMenu($child, $user))
            ->filter()
            ->values();

        if ($permissions === [] && $children->isEmpty()) {
            return null;
        }

        return [
            'id' => $menu->id,
            'name' => $menu->name,
            'slug' => $menu->slug,
            'icon' => $menu->icon,
            'order' => $menu->order,
            'permissions' => $permissions,
            'children' => $children,
        ];
    }
}
