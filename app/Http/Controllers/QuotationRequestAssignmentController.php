<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Notification;
use App\Models\QuotationRequest;
use App\Models\QuotationRequestAssignment;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * @OA\Tag(
 *     name="Atribuição de Processos",
 *     description="Atribuir um processo de aquisição a outro técnico. O criador do processo pede, o administrador aprova; o administrador pode também atribuir directamente."
 * )
 */
class QuotationRequestAssignmentController extends Controller
{
    /**
     * @OA\Get(
     *     path="/api/quotation-requests/{quotationRequest}/assignments",
     *     summary="Listar atribuições do processo",
     *     description="Inclui as atribuições em vigor, os pedidos pendentes e o histórico.",
     *     tags={"Atribuição de Processos"},
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(name="quotationRequest", in="path", required=true, @OA\Schema(type="integer")),
     *     @OA\Response(response=200, description="Lista de atribuições"),
     *     @OA\Response(response=403, description="Sem acesso a este processo")
     * )
     */
    public function index(QuotationRequest $quotationRequest)
    {
        $assignments = $quotationRequest->assignmentRecords()
            ->with(['user:id,name,email', 'requester:id,name', 'reviewer:id,name'])
            ->orderByDesc('id')
            ->get();

        return response()->json([
            'creator' => $quotationRequest->user()->first(['id', 'name', 'email']),
            'data' => $assignments,
        ]);
    }

    /**
     * @OA\Post(
     *     path="/api/quotation-requests/{quotationRequest}/assignments",
     *     summary="Atribuir o processo a um técnico (ou pedir a atribuição)",
     *     description="O administrador atribui directamente e a atribuição entra em vigor de imediato. O técnico que criou o processo cria um pedido, que fica pendente até um administrador o aprovar — e que, até lá, NÃO dá acesso.",
     *     tags={"Atribuição de Processos"},
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(name="quotationRequest", in="path", required=true, @OA\Schema(type="integer")),
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"user_id"},
     *             @OA\Property(property="user_id", type="integer", description="Técnico a atribuir"),
     *             @OA\Property(property="reason", type="string", description="Justificação. Obrigatória quando quem pede não é administrador.")
     *         )
     *     ),
     *     @OA\Response(response=201, description="Atribuído (admin) ou pedido criado (técnico)"),
     *     @OA\Response(response=403, description="Só o criador do processo pode pedir atribuições"),
     *     @OA\Response(response=409, description="Já existe um pedido pendente ou uma atribuição em vigor"),
     *     @OA\Response(response=422, description="Alvo inválido")
     * )
     */
    public function store(Request $request, QuotationRequest $quotationRequest)
    {
        $user = $request->user();
        $isAdmin = $user->role === 'admin';

        // O middleware `visible` já garantiu acesso ao processo, mas pedir
        // atribuições é mais restrito: só o criador (ou o admin).
        if (! $isAdmin && (int) $quotationRequest->user_id !== (int) $user->id) {
            return response()->json([
                'message' => 'Apenas o criador do processo pode pedir atribuições.',
            ], 403);
        }

        $validated = $request->validate([
            'user_id' => [
                'required',
                'integer',
                Rule::exists('users', 'id')->whereNull('deleted_at'),
            ],
            'reason' => $isAdmin ? 'nullable|string' : 'required|string',
        ]);

        $target = User::find($validated['user_id']);

        if (! $target->is_active) {
            return response()->json(['message' => 'Este utilizador está inactivo.'], 422);
        }

        if ($target->role === 'admin') {
            return response()->json([
                'message' => 'Os administradores já têm acesso a todos os processos.',
            ], 422);
        }

        if ((int) $target->id === (int) $quotationRequest->user_id) {
            return response()->json([
                'message' => 'O criador do processo já tem acesso.',
            ], 422);
        }

        $status = $isAdmin ? QuotationRequestAssignment::ACTIVE : QuotationRequestAssignment::PENDING;

        try {
            $assignment = DB::transaction(function () use ($quotationRequest, $target, $user, $validated, $status, $isAdmin) {
                return QuotationRequestAssignment::create([
                    'quotation_request_id' => $quotationRequest->id,
                    'user_id' => $target->id,
                    'status' => $status,
                    'requested_by' => $user->id,
                    'reason' => $validated['reason'] ?? null,
                    'reviewed_by' => $isAdmin ? $user->id : null,
                    'reviewed_at' => $isAdmin ? now() : null,
                ]);
            });
        } catch (QueryException $e) {
            // A unicidade é garantida pelo índice qra_open_unique, não por um
            // exists() prévio — por isso não há janela para dois pedidos
            // simultâneos criarem duas linhas.
            if ($this->isUniqueViolation($e)) {
                return response()->json([
                    'message' => 'Já existe um pedido pendente ou uma atribuição em vigor para este técnico.',
                ], 409);
            }

            throw $e;
        }

        $payload = [
            'assignment_id' => $assignment->id,
            'quotation_request_id' => $quotationRequest->id,
            'reference_number' => $quotationRequest->reference_number,
            'identifier' => $quotationRequest->reference_number,
            'target_user_id' => $target->id,
            'target_user_name' => $target->name,
            'requested_by_name' => $user->name,
            'reason' => $validated['reason'] ?? null,
        ];

        if ($isAdmin) {
            AuditLog::log(
                'Atribuição de processo',
                "Administrador '{$user->name}' atribuiu a cotação {$quotationRequest->reference_number} a '{$target->name}'",
                $payload,
                $user
            );

            Notification::create([
                'user_id' => $target->id,
                'type' => 'assignment_granted',
                'title' => 'Processo atribuído',
                'message' => "Foi-lhe atribuída a cotação {$quotationRequest->reference_number}.",
                'data' => $payload,
            ]);

            if ($quotationRequest->user_id && (int) $quotationRequest->user_id !== (int) $user->id) {
                Notification::create([
                    'user_id' => $quotationRequest->user_id,
                    'type' => 'assignment_granted',
                    'title' => 'Técnico atribuído ao seu processo',
                    'message' => "'{$target->name}' passou a ter acesso à cotação {$quotationRequest->reference_number}.",
                    'data' => $payload,
                ]);
            }

            return response()->json([
                'message' => "Cotação atribuída a {$target->name}.",
                'assignment' => $assignment,
            ], 201);
        }

        AuditLog::log(
            'Solicitação de atribuição',
            "Utilizador '{$user->name}' pediu a atribuição da cotação {$quotationRequest->reference_number} a '{$target->name}'",
            $payload,
            $user
        );

        foreach (User::where('role', 'admin')->where('is_active', true)->get() as $admin) {
            Notification::create([
                'user_id' => $admin->id,
                'type' => 'assignment_requested',
                'title' => 'Pedido de atribuição de processo',
                'message' => "'{$user->name}' pediu para atribuir a cotação {$quotationRequest->reference_number} a '{$target->name}'.",
                'data' => $payload,
            ]);
        }

        return response()->json([
            'message' => 'Pedido de atribuição enviado para aprovação do administrador.',
            'assignment' => $assignment,
        ], 201);
    }

    /**
     * @OA\Delete(
     *     path="/api/quotation-requests/{quotationRequest}/assignments/{assignment}",
     *     summary="Retirar uma atribuição ou cancelar um pedido",
     *     description="Pode ser feito pelo administrador, pelo criador do processo, ou pelo próprio técnico atribuído.",
     *     tags={"Atribuição de Processos"},
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(name="quotationRequest", in="path", required=true, @OA\Schema(type="integer")),
     *     @OA\Parameter(name="assignment", in="path", required=true, @OA\Schema(type="integer")),
     *     @OA\Response(response=200, description="Atribuição retirada"),
     *     @OA\Response(response=400, description="Já não está activa"),
     *     @OA\Response(response=403, description="Sem permissão"),
     *     @OA\Response(response=404, description="Não pertence a este processo")
     * )
     */
    public function destroy(Request $request, QuotationRequest $quotationRequest, QuotationRequestAssignment $assignment)
    {
        if ((int) $assignment->quotation_request_id !== (int) $quotationRequest->id) {
            abort(404);
        }

        $user = $request->user();

        $podeRetirar = $user->role === 'admin'
            || (int) $quotationRequest->user_id === (int) $user->id
            || (int) $assignment->user_id === (int) $user->id;

        if (! $podeRetirar) {
            return response()->json(['message' => 'Não tem permissão para retirar esta atribuição.'], 403);
        }

        if (! in_array($assignment->status, QuotationRequestAssignment::OPEN_STATES, true)) {
            return response()->json(['message' => 'Esta atribuição já não está activa.'], 400);
        }

        $eraPendente = $assignment->isPending();

        $assignment->update([
            'status' => QuotationRequestAssignment::REVOKED,
            'revoked_by' => $user->id,
            'revoked_at' => now(),
        ]);

        AuditLog::log(
            $eraPendente ? 'Cancelamento de pedido de atribuição' : 'Remoção de atribuição',
            "Utilizador '{$user->name}' retirou a atribuição da cotação {$quotationRequest->reference_number}",
            [
                'assignment_id' => $assignment->id,
                'quotation_request_id' => $quotationRequest->id,
                'reference_number' => $quotationRequest->reference_number,
                'target_user_id' => $assignment->user_id,
            ],
            $user
        );

        if (! $eraPendente && (int) $assignment->user_id !== (int) $user->id) {
            Notification::create([
                'user_id' => $assignment->user_id,
                'type' => 'assignment_revoked',
                'title' => 'Atribuição retirada',
                'message' => "Deixou de ter acesso à cotação {$quotationRequest->reference_number}.",
                'data' => [
                    'assignment_id' => $assignment->id,
                    'quotation_request_id' => $quotationRequest->id,
                    'identifier' => $quotationRequest->reference_number,
                ],
            ]);
        }

        return response()->json([
            'message' => $eraPendente ? 'Pedido de atribuição cancelado.' : 'Atribuição retirada.',
        ]);
    }

    /**
     * Violação de índice único, em MySQL e em SQLite.
     */
    private function isUniqueViolation(QueryException $e): bool
    {
        return $e->getCode() === '23000'
            || str_contains(strtolower($e->getMessage()), 'unique');
    }
}
