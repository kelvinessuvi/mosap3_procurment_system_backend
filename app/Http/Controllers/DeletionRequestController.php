<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\DeletionRequest;
use App\Models\Notification;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * @OA\Tag(
 *     name="Pedidos de Exclusão",
 *     description="Gestão de solicitações de exclusão de registos (admin)"
 * )
 */
class DeletionRequestController extends Controller
{
    /**
     * @OA\Get(
     *     path="/api/deletion-requests",
     *     summary="Listar pedidos de exclusão",
     *     tags={"Pedidos de Exclusão"},
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(name="status", in="query", description="Filtrar por status", @OA\Schema(type="string", enum={"pending", "approved", "rejected"})),
     *     @OA\Response(response=200, description="Lista de pedidos")
     * )
     */
    public function index(Request $request)
    {
        $query = DeletionRequest::with(['requestable', 'requester', 'reviewer'])
            ->orderByDesc('created_at');

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        return response()->json($query->paginate(15));
    }

    /**
     * @OA\Get(
     *     path="/api/deletion-requests/{id}",
     *     summary="Detalhes do pedido de exclusão",
     *     tags={"Pedidos de Exclusão"},
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")),
     *     @OA\Response(response=200, description="Detalhes do pedido"),
     *     @OA\Response(response=404, description="Pedido não encontrado")
     * )
     */
    public function show(DeletionRequest $deletionRequest)
    {
        return response()->json(
            $deletionRequest->load(['requestable', 'requester', 'reviewer'])
        );
    }

    /**
     * @OA\Post(
     *     path="/api/deletion-requests/{id}/approve",
     *     summary="Aprovar exclusão",
     *     description="Aprova o pedido e remove o registo (soft delete) com os dados sensíveis mascarados.",
     *     tags={"Pedidos de Exclusão"},
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")),
     *     @OA\Response(response=200, description="Exclusão aprovada e registo removido"),
     *     @OA\Response(response=400, description="Pedido já foi processado")
     * )
     */
    public function approve(Request $request, DeletionRequest $deletionRequest)
    {
        if ($deletionRequest->status !== 'pending') {
            return response()->json(['message' => 'Este pedido já foi processado.'], 400);
        }

        return DB::transaction(function () use ($request, $deletionRequest) {
            $deletionRequest->update([
                'status' => 'approved',
                'reviewed_by' => $request->user()->id,
                'reviewed_at' => now(),
            ]);

            $record = $deletionRequest->requestable;
            $label = $record ? class_basename($record) : class_basename($deletionRequest->requestable_type);
            $identifier = $record?->company_name
                ?? $record?->reference_number
                ?? $record?->title
                ?? $record?->id
                ?? '#' . $deletionRequest->requestable_id;

            // Soft delete with masked sensitive data (via model deleting event)
            if ($record) {
                $record->delete();
            }

            $admin = $request->user();

            AuditLog::log('Aprovação de exclusão', "Admin '{$admin->name}' aprovou a exclusão do(a) {$label} '{$identifier}'", [
                'deletion_request_id' => $deletionRequest->id,
                'requestable_type' => $deletionRequest->requestable_type,
                'requestable_id' => $deletionRequest->requestable_id,
                'reason' => $deletionRequest->reason,
                'requester_name' => $deletionRequest->requester?->name ?? 'Utilizador',
            ], $admin);

            // Notify requester that their request was approved
            Notification::create([
                'user_id' => $deletionRequest->requested_by,
                'type' => 'deletion_approved',
                'title' => 'Exclusão Aprovada',
                'message' => "A sua solicitação de exclusão do(a) {$label} '{$identifier}' foi aprovada e o registro foi removido.",
                'data' => [
                    'deletion_request_id' => $deletionRequest->id,
                    'requestable_type' => $deletionRequest->requestable_type,
                    'requestable_id' => $deletionRequest->requestable_id,
                    'identifier' => $identifier,
                ],
            ]);

            return response()->json(['message' => 'Exclusão aprovada e registro removido com sucesso.']);
        });
    }

    /**
     * @OA\Post(
     *     path="/api/deletion-requests/{id}/reject",
     *     summary="Rejeitar exclusão",
     *     description="Rejeita o pedido de exclusão. O registo é mantido intacto.",
     *     tags={"Pedidos de Exclusão"},
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")),
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"rejection_reason"},
     *             @OA\Property(property="rejection_reason", type="string", example="Fornecedor com contratos ativos")
     *         )
     *     ),
     *     @OA\Response(response=200, description="Exclusão rejeitada, registo mantido"),
     *     @OA\Response(response=400, description="Pedido já foi processado"),
     *     @OA\Response(response=422, description="Erro de validação")
     * )
     */
    public function reject(Request $request, DeletionRequest $deletionRequest)
    {
        if ($deletionRequest->status !== 'pending') {
            return response()->json(['message' => 'Este pedido já foi processado.'], 400);
        }

        $validated = $request->validate([
            'rejection_reason' => 'required|string',
        ]);

        return DB::transaction(function () use ($validated, $request, $deletionRequest) {
            $deletionRequest->update([
                'status' => 'rejected',
                'reviewed_by' => $request->user()->id,
                'reviewed_at' => now(),
                'rejection_reason' => $validated['rejection_reason'],
            ]);

            $record = $deletionRequest->requestable;
            $label = $record ? class_basename($record) : class_basename($deletionRequest->requestable_type);
            $identifier = $record?->company_name
                ?? $record?->reference_number
                ?? $record?->title
                ?? $record?->id
                ?? '#' . $deletionRequest->requestable_id;

            $admin = $request->user();

            AuditLog::log('Rejeição de exclusão', "Admin '{$admin->name}' rejeitou a exclusão do(a) {$label} '{$identifier}'", [
                'deletion_request_id' => $deletionRequest->id,
                'requestable_type' => $deletionRequest->requestable_type,
                'requestable_id' => $deletionRequest->requestable_id,
                'reason' => $deletionRequest->reason,
                'rejection_reason' => $validated['rejection_reason'],
                'requester_name' => $deletionRequest->requester?->name ?? 'Utilizador',
            ], $admin);

            // Notify requester that their request was rejected
            Notification::create([
                'user_id' => $deletionRequest->requested_by,
                'type' => 'deletion_rejected',
                'title' => 'Exclusão Rejeitada',
                'message' => "A sua solicitação de exclusão do(a) {$label} '{$identifier}' foi rejeitada.\nMotivo: {$validated['rejection_reason']}",
                'data' => [
                    'deletion_request_id' => $deletionRequest->id,
                    'requestable_type' => $deletionRequest->requestable_type,
                    'requestable_id' => $deletionRequest->requestable_id,
                    'identifier' => $identifier,
                    'rejection_reason' => $validated['rejection_reason'],
                ],
            ]);

            return response()->json(['message' => 'Exclusão rejeitada. O registro foi mantido.']);
        });
    }
}
