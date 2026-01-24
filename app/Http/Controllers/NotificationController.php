<?php

namespace App\Http\Controllers;

use App\Models\Notification;
use Illuminate\Http\Request;

/**
 * @OA\Tag(
 *     name="Notificações",
 *     description="Gestão de Notificações In-App"
 * )
 */
class NotificationController extends Controller
{
    /**
     * @OA\Get(
     *     path="/api/notifications",
     *     summary="Listar Notificações",
     *     description="Retorna lista paginada de notificações do usuário autenticado",
     *     tags={"Notificações"},
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(name="unread_only", in="query", description="Filtrar apenas não lidas", @OA\Schema(type="boolean")),
     *     @OA\Parameter(name="type", in="query", description="Filtrar por tipo", @OA\Schema(type="string")),
     *     @OA\Parameter(name="per_page", in="query", description="Itens por página", @OA\Schema(type="integer")),
     *     @OA\Response(
     *         response=200,
     *         description="Lista de notificações",
     *         @OA\JsonContent(
     *             @OA\Property(property="data", type="array", @OA\Items(
     *                 @OA\Property(property="id", type="integer"),
     *                 @OA\Property(property="type", type="string"),
     *                 @OA\Property(property="title", type="string"),
     *                 @OA\Property(property="message", type="string"),
     *                 @OA\Property(property="data", type="object"),
     *                 @OA\Property(property="read_at", type="string", format="date-time", nullable=true),
     *                 @OA\Property(property="created_at", type="string", format="date-time")
     *             ))
     *         )
     *     )
     * )
     */
    public function index(Request $request)
    {
        $query = Notification::where('user_id', $request->user()->id)
            ->orderBy('created_at', 'desc');

        // Filter by unread only
        if ($request->boolean('unread_only')) {
            $query->unread();
        }

        // Filter by type
        if ($request->filled('type')) {
            $query->ofType($request->type);
        }

        $perPage = $request->input('per_page', 15);
        
        return response()->json($query->paginate($perPage));
    }

    /**
     * @OA\Get(
     *     path="/api/notifications/unread-count",
     *     summary="Contar Notificações Não Lidas",
     *     tags={"Notificações"},
     *     security={{"bearerAuth":{}}},
     *     @OA\Response(
     *         response=200,
     *         description="Contagem de não lidas",
     *         @OA\JsonContent(
     *             @OA\Property(property="count", type="integer", example=5)
     *         )
     *     )
     * )
     */
    public function unreadCount(Request $request)
    {
        $count = Notification::where('user_id', $request->user()->id)
            ->unread()
            ->count();

        return response()->json(['count' => $count]);
    }

    /**
     * @OA\Get(
     *     path="/api/notifications/{id}",
     *     summary="Detalhes da Notificação",
     *     tags={"Notificações"},
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")),
     *     @OA\Response(response=200, description="Detalhes da notificação"),
     *     @OA\Response(response=404, description="Notificação não encontrada")
     * )
     */
    public function show(Request $request, Notification $notification)
    {
        // Ensure user owns this notification
        if ($notification->user_id !== $request->user()->id) {
            return response()->json(['message' => 'Não autorizado'], 403);
        }

        return response()->json($notification);
    }

    /**
     * @OA\Post(
     *     path="/api/notifications/{id}/read",
     *     summary="Marcar Notificação Como Lida",
     *     tags={"Notificações"},
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")),
     *     @OA\Response(response=200, description="Notificação marcada como lida"),
     *     @OA\Response(response=403, description="Não autorizado")
     * )
     */
    public function markAsRead(Request $request, Notification $notification)
    {
        // Ensure user owns this notification
        if ($notification->user_id !== $request->user()->id) {
            return response()->json(['message' => 'Não autorizado'], 403);
        }

        $notification->markAsRead();

        return response()->json($notification);
    }

    /**
     * @OA\Post(
     *     path="/api/notifications/mark-all-read",
     *     summary="Marcar Todas Como Lidas",
     *     tags={"Notificações"},
     *     security={{"bearerAuth":{}}},
     *     @OA\Response(response=200, description="Todas as notificações foram marcadas como lidas")
     * )
     */
    public function markAllAsRead(Request $request)
    {
        Notification::where('user_id', $request->user()->id)
            ->unread()
            ->update(['read_at' => now()]);

        return response()->json(['message' => 'Todas as notificações foram marcadas como lidas']);
    }

    /**
     * @OA\Delete(
     *     path="/api/notifications/{id}",
     *     summary="Deletar Notificação",
     *     tags={"Notificações"},
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")),
     *     @OA\Response(response=204, description="Notificação deletada"),
     *     @OA\Response(response=403, description="Não autorizado")
     * )
     */
    public function destroy(Request $request, Notification $notification)
    {
        // Ensure user owns this notification
        if ($notification->user_id !== $request->user()->id) {
            return response()->json(['message' => 'Não autorizado'], 403);
        }

        $notification->delete();

        return response()->json(null, 204);
    }
}
