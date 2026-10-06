<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Notification;
use App\Models\QuotationRequestAssignment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * @OA\Tag(
 *     name="Pedidos de Atribuição",
 *     description="Aprovação ou rejeição, pelo administrador, dos pedidos de atribuição de processos feitos pelos técnicos"
 * )
 */
class AssignmentRequestController extends Controller
{
    /**
     * @OA\Get(
     *     path="/api/assignment-requests",
     *     summary="Listar pedidos de atribuição",
     *     tags={"Pedidos de Atribuição"},
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(name="status", in="query", @OA\Schema(type="string", enum={"pending","active","rejected","revoked"})),
     *     @OA\Response(response=200, description="Lista paginada")
     * )
     */
    public function index(Request $request)
    {
        $query = QuotationRequestAssignment::with([
            'quotationRequest:id,reference_number,title,status,user_id',
            'user:id,name,email',
            'requester:id,name',
            'reviewer:id,name',
        ])->orderByDesc('created_at');

        // Por omissão mostra o que precisa de decisão.
        $query->where('status', $request->input('status', QuotationRequestAssignment::PENDING));

        return response()->json($query->paginate(15));
    }

    /**
     * @OA\Get(
     *     path="/api/assignment-requests/{assignment}",
     *     summary="Detalhe de um pedido de atribuição",
     *     tags={"Pedidos de Atribuição"},
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(name="assignment", in="path", required=true, @OA\Schema(type="integer")),
     *     @OA\Response(response=200, description="Detalhe")
     * )
     */
    public function show(QuotationRequestAssignment $assignment)
    {
        return response()->json($assignment->load([
            'quotationRequest', 'user:id,name,email', 'requester:id,name', 'reviewer:id,name',
        ]));
    }

    /**
     * @OA\Post(
     *     path="/api/assignment-requests/{assignment}/approve",
     *     summary="Aprovar o pedido de atribuição",
     *     description="A atribuição passa a estar em vigor e o técnico ganha acesso ao processo.",
     *     tags={"Pedidos de Atribuição"},
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(name="assignment", in="path", required=true, @OA\Schema(type="integer")),
     *     @OA\Response(response=200, description="Aprovado"),
     *     @OA\Response(response=400, description="Pedido já processado")
     * )
     */
    public function approve(Request $request, QuotationRequestAssignment $assignment)
    {
        if (! $assignment->isPending()) {
            return response()->json(['message' => 'Este pedido já foi processado.'], 400);
        }

        $admin = $request->user();

        DB::transaction(function () use ($assignment, $admin) {
            $assignment->update([
                'status' => QuotationRequestAssignment::ACTIVE,
                'reviewed_by' => $admin->id,
                'reviewed_at' => now(),
            ]);
        });

        $assignment->load(['quotationRequest', 'user']);
        $payload = $this->payload($assignment);

        AuditLog::log(
            'Aprovação de atribuição',
            "Administrador '{$admin->name}' aprovou a atribuição da cotação {$payload['identifier']} a '{$assignment->user?->name}'",
            $payload,
            $admin
        );

        // Quem pediu, para saber que já pode contar com o colega.
        Notification::create([
            'user_id' => $assignment->requested_by,
            'type' => 'assignment_approved',
            'title' => 'Pedido de atribuição aprovado',
            'message' => "A atribuição da cotação {$payload['identifier']} a '{$assignment->user?->name}' foi aprovada.",
            'data' => $payload,
        ]);

        // E o atribuído, para saber que tem trabalho novo.
        if ((int) $assignment->user_id !== (int) $assignment->requested_by) {
            Notification::create([
                'user_id' => $assignment->user_id,
                'type' => 'assignment_granted',
                'title' => 'Processo atribuído',
                'message' => "Foi-lhe atribuída a cotação {$payload['identifier']}.",
                'data' => $payload,
            ]);
        }

        return response()->json([
            'message' => 'Atribuição aprovada.',
            'assignment' => $assignment,
        ]);
    }

    /**
     * @OA\Post(
     *     path="/api/assignment-requests/{assignment}/reject",
     *     summary="Rejeitar o pedido de atribuição",
     *     tags={"Pedidos de Atribuição"},
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(name="assignment", in="path", required=true, @OA\Schema(type="integer")),
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"rejection_reason"},
     *             @OA\Property(property="rejection_reason", type="string")
     *         )
     *     ),
     *     @OA\Response(response=200, description="Rejeitado"),
     *     @OA\Response(response=400, description="Pedido já processado"),
     *     @OA\Response(response=422, description="Falta a justificação")
     * )
     */
    public function reject(Request $request, QuotationRequestAssignment $assignment)
    {
        if (! $assignment->isPending()) {
            return response()->json(['message' => 'Este pedido já foi processado.'], 400);
        }

        $validated = $request->validate(['rejection_reason' => 'required|string']);
        $admin = $request->user();

        DB::transaction(function () use ($assignment, $admin, $validated) {
            $assignment->update([
                'status' => QuotationRequestAssignment::REJECTED,
                'reviewed_by' => $admin->id,
                'reviewed_at' => now(),
                'rejection_reason' => $validated['rejection_reason'],
            ]);
        });

        $assignment->load(['quotationRequest', 'user']);
        $payload = $this->payload($assignment) + ['rejection_reason' => $validated['rejection_reason']];

        AuditLog::log(
            'Rejeição de atribuição',
            "Administrador '{$admin->name}' rejeitou a atribuição da cotação {$payload['identifier']} a '{$assignment->user?->name}'",
            $payload,
            $admin
        );

        Notification::create([
            'user_id' => $assignment->requested_by,
            'type' => 'assignment_rejected',
            'title' => 'Pedido de atribuição rejeitado',
            'message' => "A atribuição da cotação {$payload['identifier']} não foi aprovada.",
            'data' => $payload,
        ]);

        return response()->json([
            'message' => 'Pedido de atribuição rejeitado.',
            'assignment' => $assignment,
        ]);
    }

    /**
     * Mantém a convenção de `data` que o NotificationController já serve ao frontend.
     */
    private function payload(QuotationRequestAssignment $assignment): array
    {
        $reference = $assignment->quotationRequest?->reference_number
            ?? '#'.$assignment->quotation_request_id;

        return [
            'assignment_id' => $assignment->id,
            'quotation_request_id' => $assignment->quotation_request_id,
            'reference_number' => $reference,
            'identifier' => $reference,
            'target_user_id' => $assignment->user_id,
            'target_user_name' => $assignment->user?->name,
        ];
    }
}
