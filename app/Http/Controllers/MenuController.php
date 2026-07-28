<?php

namespace App\Http\Controllers;

use App\Models\Menu;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * @OA\Tag(
 *     name="Menus",
 *     description="Gestão de menus do sistema (Admin apenas)"
 * )
 */
class MenuController extends Controller
{
    /**
     * @OA\Get(
     *     path="/api/menus",
     *     summary="Listar menus do sistema",
     *     tags={"Menus"},
     *     security={{"bearerAuth":{}}},
     *     @OA\Response(response=200, description="Lista de menus")
     * )
     */
    public function index()
    {
        $menus = Menu::with('children')
            ->whereNull('parent_id')
            ->orderBy('order')
            ->get();

        return response()->json($menus);
    }

    /**
     * @OA\Post(
     *     path="/api/menus",
     *     summary="Criar novo menu",
     *     tags={"Menus"},
     *     security={{"bearerAuth":{}}},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"name", "slug"},
     *             @OA\Property(property="name", type="string", example="Relatórios"),
     *             @OA\Property(property="slug", type="string", example="reports"),
     *             @OA\Property(property="parent_id", type="integer", nullable=true),
     *             @OA\Property(property="description", type="string"),
     *             @OA\Property(property="icon", type="string"),
     *             @OA\Property(property="order", type="integer"),
     *             @OA\Property(property="is_active", type="boolean")
     *         )
     *     ),
     *     @OA\Response(response=201, description="Menu criado"),
     *     @OA\Response(response=422, description="Erro de validação")
     * )
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'parent_id' => 'nullable|exists:menus,id',
            'name' => 'required|string|max:255',
            'slug' => 'required|string|max:255|unique:menus,slug',
            'description' => 'nullable|string',
            'icon' => 'nullable|string|max:255',
            'order' => 'nullable|integer|min:0',
            'is_active' => 'boolean',
        ]);

        $menu = Menu::create($validated);

        return response()->json($menu, 201);
    }

    /**
     * @OA\Get(
     *     path="/api/menus/{menu}",
     *     summary="Exibir detalhes do menu",
     *     tags={"Menus"},
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(name="menu", in="path", required=true, @OA\Schema(type="integer")),
     *     @OA\Response(response=200, description="Detalhes do menu")
     * )
     */
    public function show(Menu $menu)
    {
        return response()->json($menu->load('children'));
    }

    /**
     * @OA\Put(
     *     path="/api/menus/{menu}",
     *     summary="Actualizar menu",
     *     tags={"Menus"},
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(name="menu", in="path", required=true, @OA\Schema(type="integer")),
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             @OA\Property(property="name", type="string"),
     *             @OA\Property(property="slug", type="string"),
     *             @OA\Property(property="parent_id", type="integer", nullable=true),
     *             @OA\Property(property="description", type="string"),
     *             @OA\Property(property="icon", type="string"),
     *             @OA\Property(property="order", type="integer"),
     *             @OA\Property(property="is_active", type="boolean")
     *         )
     *     ),
     *     @OA\Response(response=200, description="Menu actualizado"),
     *     @OA\Response(response=422, description="Erro de validação")
     * )
     */
    public function update(Request $request, Menu $menu)
    {
        $validated = $request->validate([
            'parent_id' => 'nullable|exists:menus,id',
            'name' => 'string|max:255',
            'slug' => ['string', 'max:255', Rule::unique('menus', 'slug')->ignore($menu->id)],
            'description' => 'nullable|string',
            'icon' => 'nullable|string|max:255',
            'order' => 'nullable|integer|min:0',
            'is_active' => 'boolean',
        ]);

        $menu->update($validated);

        return response()->json($menu);
    }

    /**
     * @OA\Delete(
     *     path="/api/menus/{menu}",
     *     summary="Remover menu",
     *     tags={"Menus"},
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(name="menu", in="path", required=true, @OA\Schema(type="integer")),
     *     @OA\Response(response=204, description="Menu removido")
     * )
     */
    public function destroy(Menu $menu)
    {
        $menu->delete();

        return response()->json(null, 204);
    }
}
