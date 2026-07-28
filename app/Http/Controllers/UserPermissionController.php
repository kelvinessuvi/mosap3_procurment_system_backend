<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * @OA\Tag(
 *     name="Permissões de Utilizadores",
 *     description="Gestão de permissões de menu por utilizador (Admin apenas)"
 * )
 */
class UserPermissionController extends Controller
{
    /**
     * @OA\Get(
     *     path="/api/users/{user}/permissions",
     *     summary="Obter permissões de menu de um utilizador",
     *     tags={"Permissões de Utilizadores"},
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(name="user", in="path", required=true, @OA\Schema(type="integer")),
     *     @OA\Response(response=200, description="Permissões do utilizador")
     * )
     */
    public function index(User $user)
    {
        $permissions = $user->menuPermissions()
            ->get()
            ->map(fn ($menu) => [
                'menu_id' => $menu->id,
                'level' => $menu->pivot->level,
            ]);

        return response()->json([
            'user_id' => $user->id,
            'name' => $user->name,
            'permissions' => $permissions,
        ]);
    }

    /**
     * @OA\Put(
     *     path="/api/users/{user}/permissions",
     *     summary="Definir permissões de menu de um utilizador",
     *     description="Substitui todas as permissões actuais do utilizador. Enviar array vazio para remover todas.",
     *     tags={"Permissões de Utilizadores"},
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(name="user", in="path", required=true, @OA\Schema(type="integer")),
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"permissions"},
     *             @OA\Property(property="permissions", type="array",
     *                 @OA\Items(
     *                     @OA\Property(property="menu_id", type="integer"),
     *                     @OA\Property(property="level", type="string", enum={"read", "write"})
     *                 )
     *             )
     *         )
     *     ),
     *     @OA\Response(response=200, description="Permissões actualizadas"),
     *     @OA\Response(response=422, description="Erro de validação")
     * )
     */
    public function update(Request $request, User $user)
    {
        $validated = $request->validate([
            'permissions' => 'present|array',
            'permissions.*.menu_id' => 'required|exists:menus,id',
            'permissions.*.level' => 'required|in:read,write',
        ]);

        $data = [];
        foreach ($validated['permissions'] as $perm) {
            $data[$perm['menu_id']] = ['level' => $perm['level']];
        }

        $user->menuPermissions()->sync($data);

        return response()->json(['message' => 'Permissões actualizadas com sucesso.']);
    }
}
