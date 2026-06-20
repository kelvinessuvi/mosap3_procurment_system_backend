<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use Illuminate\Http\Request;

/**
 * @OA\Tag(
 *     name="Auditoria",
 *     description="Registo de auditoria do sistema (Apenas Admin)"
 * )
 */
class AuditLogController extends Controller
{
    /**
     * @OA\Get(
     *     path="/api/audit-logs",
     *     summary="Listar logs de auditoria",
     *     description="Retorna a lista paginada de logs de auditoria com filtros opcionais.",
     *     tags={"Auditoria"},
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(name="event", in="query", description="Filtrar por evento", required=false, @OA\Schema(type="string")),
     *     @OA\Parameter(name="user_id", in="query", description="Filtrar por utilizador", required=false, @OA\Schema(type="integer")),
     *     @OA\Parameter(name="date_from", in="query", description="Data início (Y-m-d)", required=false, @OA\Schema(type="string", format="date")),
     *     @OA\Parameter(name="date_to", in="query", description="Data fim (Y-m-d)", required=false, @OA\Schema(type="string", format="date")),
     *     @OA\Parameter(name="per_page", in="query", description="Resultados por página", required=false, @OA\Schema(type="integer", default=15)),
     *     @OA\Response(response=200, description="Lista de logs"),
     * )
     */
    public function index(Request $request)
    {
        $query = AuditLog::query()->with('user')->orderByDesc('created_at');

        if ($request->filled('event')) {
            $query->ofEvent($request->event);
        }

        if ($request->filled('user_id')) {
            $query->forUser($request->user_id);
        }

        if ($request->filled('date_from')) {
            $query->where('created_at', '>=', $request->date_from);
        }

        if ($request->filled('date_to')) {
            $query->where('created_at', '<=', $request->date_to . ' 23:59:59');
        }

        return response()->json($query->paginate($request->input('per_page', 15)));
    }

    /**
     * @OA\Get(
     *     path="/api/audit-logs/{id}",
     *     summary="Detalhe do log de auditoria",
     *     tags={"Auditoria"},
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")),
     *     @OA\Response(response=200, description="Log encontrado"),
     *     @OA\Response(response=404, description="Não encontrado")
     * )
     */
    public function show(AuditLog $auditLog)
    {
        return response()->json($auditLog->load('user'));
    }
}
