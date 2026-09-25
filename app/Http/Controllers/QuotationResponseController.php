<?php

namespace App\Http\Controllers;

use App\Mail\NegotiationNotificationMail;
use App\Mail\ProposalApprovedMail;
use App\Mail\ProposalRejectedMail;
use App\Models\Acquisition;
use App\Models\AuditLog;
use App\Models\NegotiationNotification;
use App\Models\QuotationResponse;
use App\Models\QuotationSupplier;
use App\Models\SupplierEvaluation;
use App\Services\ProcurementNotifier;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

/**
 * @OA\Tag(
 *     name="Negociação & Revisão",
 *     description="Gestão de Respostas e Negociações"
 * )
 */
class QuotationResponseController extends Controller
{
    /**
     * Estados em que uma proposta ainda está "em aberto" (pode ser decidida).
     */
    private const OPEN_STATUSES = ['pending_review', 'submitted', 'negotiating'];

    /**
     * @OA\Get(
     *     path="/api/quotation-responses",
     *     summary="Listar Respostas",
     *     description="Lista paginada, da mais recente para a mais antiga. Cada revisão do fornecedor é uma resposta nova (revision_number).",
     *     tags={"Negociação & Revisão"},
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(name="status", in="query", @OA\Schema(type="string")),
     *     @OA\Parameter(name="quotation_request_id", in="query", @OA\Schema(type="integer")),
     *     @OA\Parameter(name="per_page", in="query", @OA\Schema(type="integer", maximum=100)),
     *     @OA\Response(response=200, description="Lista de respostas")
     * )
     */
    public function index(Request $request)
    {
        $query = QuotationResponse::with(['quotationSupplier.supplier', 'quotationSupplier.quotationRequest'])
            ->orderByDesc('id');

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('quotation_request_id')) {
            $query->whereHas('quotationSupplier', function($q) use ($request) {
                $q->where('quotation_request_id', $request->quotation_request_id);
            });
        }

        $perPage = min(max((int) $request->input('per_page', 15), 1), 100);

        return response()->json($query->paginate($perPage));
    }

    /**
     * @OA\Get(
     *     path="/api/quotation-responses/{id}",
     *     summary="Detalhes da Resposta",
     *     tags={"Negociação & Revisão"},
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")),
     *     @OA\Response(response=200, description="Detalhes")
     * )
     */
    public function show(QuotationResponse $quotationResponse)
    {
        return response()->json($quotationResponse->load(['items.quotationItem', 'history', 'quotationSupplier.supplier']));
    }

    /**
     * @OA\Post(
     *     path="/api/quotation-responses/{id}/approve",
     *     summary="Aprovar Proposta e Gerar Aquisição",
     *     description="Numa única operação: aprova a proposta, gera a aquisição, conclui o pedido de cotação e rejeita as restantes propostas em aberto (os fornecedores são avisados por email).",
     *     tags={"Negociação & Revisão"},
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")),
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"expected_delivery_date"},
     *             @OA\Property(property="expected_delivery_date", type="string", format="date", example="2026-03-01"),
     *             @OA\Property(property="justification", type="string", example="Melhor relação preço/prazo"),
     *             @OA\Property(property="notes", type="string", example="Aprovado, excelente preço")
     *         )
     *     ),
     *     @OA\Response(response=200, description="Proposta aprovada e aquisição criada (campo acquisition)"),
     *     @OA\Response(response=422, description="A proposta ou o pedido já não pode ser aprovado")
     * )
     */
    public function approve(Request $request, QuotationResponse $quotationResponse, ProcurementNotifier $notifier)
    {
        $validated = $request->validate([
            'expected_delivery_date' => 'required|date|after_or_equal:today',
            'justification' => 'nullable|string|max:2000',
            'notes' => 'nullable|string|max:2000',
        ]);

        $quotationResponse->load('quotationSupplier.supplier', 'quotationSupplier.quotationRequest');
        $quotationRequest = $quotationResponse->quotationSupplier->quotationRequest;

        if ($error = $this->decisionBlocker($quotationResponse)) {
            return response()->json(['message' => $error], 422);
        }

        $user = $request->user();

        [$acquisition, $rejectedOthers] = DB::transaction(function () use ($validated, $quotationResponse, $quotationRequest, $user) {
            // Bloquear o pedido para evitar duas aprovações em simultâneo
            $lockedRequest = $quotationRequest->newQuery()->lockForUpdate()->find($quotationRequest->id);
            if (in_array($lockedRequest->status, ['completed', 'cancelled']) || Acquisition::where('quotation_request_id', $lockedRequest->id)->exists()) {
                abort(422, 'Este pedido de cotação já foi concluído.');
            }

            $quotationResponse->update([
                'status' => 'approved',
                'review_notes' => $validated['notes'] ?? null,
                'user_id' => $user->id,
            ]);

            $acquisition = $this->createAcquisitionFor($quotationResponse, $validated['expected_delivery_date'], $validated['justification'] ?? null, $user->id);

            // As restantes propostas em aberto deste pedido deixam de estar em concurso
            $rejectedOthers = QuotationResponse::whereHas('quotationSupplier', function ($q) use ($quotationRequest, $quotationResponse) {
                    $q->where('quotation_request_id', $quotationRequest->id)
                      ->where('id', '!=', $quotationResponse->quotation_supplier_id);
                })
                ->whereIn('status', array_merge(self::OPEN_STATUSES, ['needs_revision']))
                ->with('quotationSupplier.supplier', 'quotationSupplier.quotationRequest')
                ->get()
                // Só a proposta mais recente de cada fornecedor
                ->groupBy('quotation_supplier_id')
                ->map(fn ($responses) => $responses->sortByDesc('id')->first())
                ->values();

            foreach ($rejectedOthers as $other) {
                $other->update([
                    'status' => 'rejected',
                    'review_notes' => 'Foi seleccionada outra proposta para este pedido de cotação.',
                    'user_id' => $user->id,
                ]);
            }

            return [$acquisition, $rejectedOthers];
        });

        $supplier = $quotationResponse->quotationSupplier->supplier;

        // Estatísticas e emails fora da transacção (uma falha de email não desfaz a aprovação)
        $this->updateSupplierStatistics($supplier->id);
        $this->sendMailSafely($supplier->email, new ProposalApprovedMail($quotationResponse, $user));

        foreach ($rejectedOthers as $other) {
            $this->updateSupplierStatistics($other->quotationSupplier->supplier_id);
            $this->sendMailSafely($other->quotationSupplier->supplier->email, new ProposalRejectedMail($other, $user));
        }

        AuditLog::log('Aprovação de proposta', "Proposta #{$quotationResponse->id} do fornecedor {$supplier->company_name} foi aprovada e gerou a aquisição #{$acquisition->reference_number}", [
            'quotation_response_id' => $quotationResponse->id,
            'supplier_id' => $supplier->id,
            'supplier_name' => $supplier->company_name,
            'quotation_request_id' => $quotationRequest->id,
            'acquisition_id' => $acquisition->id,
            'reference_number' => $acquisition->reference_number,
            'total_amount' => $acquisition->total_amount,
            'rejected_response_ids' => $rejectedOthers->pluck('id')->all(),
        ], $user);

        $notifier->notifyStaff(
            $quotationRequest,
            'proposal_approved',
            'Proposta Aprovada',
            "A proposta do fornecedor {$supplier->company_name} foi aprovada para \"{$quotationRequest->title}\" e foi gerada a aquisição {$acquisition->reference_number}.",
            [
                'quotation_response_id' => $quotationResponse->id,
                'acquisition_id' => $acquisition->id,
                'supplier_id' => $supplier->id,
                'supplier_name' => $supplier->company_name,
            ],
            [
                'Pedido' => $quotationRequest->reference_number,
                'Actividade' => $quotationRequest->title,
                'Fornecedor' => $supplier->company_name,
                'Aquisição' => $acquisition->reference_number,
                'Entrega prevista' => optional($acquisition->expected_delivery_date)->format('d/m/Y'),
                'Aprovado por' => $user->name,
            ],
            $user
        );

        return response()->json(array_merge(
            $quotationResponse->fresh()->toArray(),
            ['acquisition' => $acquisition, 'rejected_response_ids' => $rejectedOthers->pluck('id')->all()]
        ));
    }

    /**
     * @OA\Post(
     *     path="/api/quotation-responses/{id}/reject",
     *     summary="Rejeitar Proposta",
     *     tags={"Negociação & Revisão"},
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")),
     *     @OA\RequestBody(
     *         @OA\JsonContent(
     *             @OA\Property(property="notes", type="string", example="Preço muito alto")
     *         )
     *     ),
     *     @OA\Response(response=200, description="Rejeitado com sucesso")
     * )
     */
    public function reject(Request $request, QuotationResponse $quotationResponse, ProcurementNotifier $notifier)
    {
        $validated = $request->validate(['notes' => 'nullable|string|max:2000']);

        $quotationResponse->load('quotationSupplier.supplier', 'quotationSupplier.quotationRequest');
        if ($error = $this->decisionBlocker($quotationResponse, ['needs_revision'])) {
            return response()->json(['message' => $error], 422);
        }

        $user = $request->user();
        $quotationResponse->update([
            'status' => 'rejected',
            'review_notes' => $validated['notes'] ?? null,
            'user_id' => $user->id,
        ]);

        $qs = $quotationResponse->quotationSupplier;
        $this->updateSupplierStatistics($qs->supplier_id);
        $this->sendMailSafely($qs->supplier->email, new ProposalRejectedMail($quotationResponse, $user));

        AuditLog::log('Rejeição de proposta', "Proposta #{$quotationResponse->id} do fornecedor {$qs->supplier->company_name} foi rejeitada", [
            'quotation_response_id' => $quotationResponse->id,
            'supplier_id' => $qs->supplier_id,
            'supplier_name' => $qs->supplier->company_name,
            'quotation_request_id' => $qs->quotation_request_id,
            'notes' => $validated['notes'] ?? null,
        ], $user);

        $notifier->notifyStaff(
            $qs->quotationRequest,
            'proposal_rejected',
            'Proposta Rejeitada',
            "A proposta do fornecedor {$qs->supplier->company_name} para \"{$qs->quotationRequest->title}\" foi rejeitada por {$user->name}.",
            [
                'quotation_response_id' => $quotationResponse->id,
                'supplier_id' => $qs->supplier_id,
                'supplier_name' => $qs->supplier->company_name,
            ],
            [
                'Pedido' => $qs->quotationRequest->reference_number,
                'Actividade' => $qs->quotationRequest->title,
                'Fornecedor' => $qs->supplier->company_name,
                'Motivo' => $validated['notes'] ?? null,
            ],
            $user
        );

        return response()->json($quotationResponse);
    }

    /**
     * @OA\Post(
     *     path="/api/quotation-responses/{id}/request-revision",
     *     summary="Solicitar Revisão",
     *     description="Solicita ao fornecedor uma revisão da proposta (gera novo token). A proposta revista chega como uma nova resposta com revision_number incrementado.",
     *     tags={"Negociação & Revisão"},
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")),
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"reason", "message"},
     *             @OA\Property(property="reason", type="string", example="Preço"),
     *             @OA\Property(property="message", type="string", example="Por favor, reveja o preço unitário do item 2.")
     *         )
     *     ),
     *     @OA\Response(response=200, description="Solicitação enviada")
     * )
     */
    public function requestRevision(Request $request, QuotationResponse $quotationResponse, ProcurementNotifier $notifier)
    {
        $validated = $request->validate([
            'reason' => 'required|string|max:255',
            'message' => 'required|string|max:5000',
        ]);

        $quotationResponse->load('quotationSupplier.supplier', 'quotationSupplier.quotationRequest');
        if ($error = $this->decisionBlocker($quotationResponse)) {
            return response()->json(['message' => $error], 422);
        }

        $user = $request->user();

        [$notification, $newToken] = DB::transaction(function () use ($validated, $quotationResponse, $user) {
            $quotationResponse->update([
                'status' => 'needs_revision',
                'user_id' => $user->id, // Reviewer
            ]);

            $qs = $quotationResponse->quotationSupplier;

            // Novo token: o link anterior deixa de funcionar e o fornecedor reabre o pedido
            $newToken = Str::random(64);
            $qs->update([
                'token' => $newToken,
                'status' => 'sent',
            ]);

            $notification = NegotiationNotification::create([
                'quotation_supplier_id' => $qs->id,
                'reason' => $validated['reason'],
                'message' => $validated['message'],
            ]);

            $quotationResponse->history()->create([
                'revision_number' => $quotationResponse->revision_number,
                'items_data' => $quotationResponse->items->toArray(),
                'total_amount' => $quotationResponse->items->sum('total_price'),
                'action' => 'revised', // revisão solicitada
                'action_notes' => $validated['message'],
                'user_id' => $user->id,
            ]);

            return [$notification, $newToken];
        });

        $qs = $quotationResponse->quotationSupplier;

        // O email ao fornecedor é essencial (contém o novo link): se falhar, a equipa é avisada
        $mailSent = $this->sendMailSafely($qs->supplier->email, new NegotiationNotificationMail(
            $qs->quotationRequest,
            $notification,
            $newToken,
            $qs->supplier,
            $user
        ));

        $this->updateSupplierStatistics($qs->supplier_id);

        AuditLog::log('Pedido de revisão', "Revisão solicitada para proposta #{$quotationResponse->id} do fornecedor {$qs->supplier->company_name}", [
            'quotation_response_id' => $quotationResponse->id,
            'supplier_id' => $qs->supplier_id,
            'supplier_name' => $qs->supplier->company_name,
            'quotation_request_id' => $qs->quotation_request_id,
            'reason' => $validated['reason'],
            'message' => $validated['message'],
        ], $user);

        $notifier->notifyStaff(
            $qs->quotationRequest,
            'revision_requested',
            'Revisão Solicitada',
            "{$user->name} solicitou ao fornecedor {$qs->supplier->company_name} uma revisão da proposta para \"{$qs->quotationRequest->title}\".",
            [
                'quotation_response_id' => $quotationResponse->id,
                'supplier_id' => $qs->supplier_id,
                'supplier_name' => $qs->supplier->company_name,
                'reason' => $validated['reason'],
            ],
            [
                'Pedido' => $qs->quotationRequest->reference_number,
                'Actividade' => $qs->quotationRequest->title,
                'Fornecedor' => $qs->supplier->company_name,
                'Motivo' => $validated['reason'],
                'Mensagem' => $validated['message'],
            ],
            $user
        );

        return response()->json([
            'message' => $mailSent
                ? 'Solicitação de revisão enviada.'
                : 'Revisão registada, mas não foi possível enviar o email ao fornecedor. Verifique o endereço de email.',
            'email_sent' => $mailSent,
        ]);
    }

    /**
     * @OA\Post(
     *     path="/api/quotation-responses/{id}/create-acquisition",
     *     summary="Gerar Aquisição (propostas já aprovadas)",
     *     description="Compatibilidade: gera a aquisição de uma proposta aprovada antes do fluxo unificado (aprovar = gerar aquisição).",
     *     tags={"Negociação & Revisão"},
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")),
     *     @OA\RequestBody(
     *         @OA\JsonContent(
     *             required={"expected_delivery_date"},
     *             @OA\Property(property="expected_delivery_date", type="string", format="date", example="2026-03-01"),
     *             @OA\Property(property="justification", type="string")
     *         )
     *     ),
     *     @OA\Response(response=201, description="Aquisição criada")
     * )
     */
    public function createAcquisition(Request $request, QuotationResponse $quotationResponse)
    {
        if ($quotationResponse->status !== 'approved') {
            return response()->json(['message' => 'Apenas propostas aprovadas podem gerar aquisição.'], 400);
        }

        if (Acquisition::where('quotation_response_id', $quotationResponse->id)->exists()) {
            return response()->json(['message' => 'Esta proposta já tem uma aquisição gerada.'], 422);
        }

        $validated = $request->validate([
            'justification' => 'nullable|string|max:2000',
            'expected_delivery_date' => 'required|date|after_or_equal:today',
        ]);

        return DB::transaction(function () use ($validated, $quotationResponse) {
            $acquisition = $this->createAcquisitionFor(
                $quotationResponse,
                $validated['expected_delivery_date'],
                $validated['justification'] ?? null,
                auth()->id()
            );

            $this->updateSupplierStatistics($quotationResponse->quotationSupplier->supplier_id);

            AuditLog::log('Criação de aquisição', "Aquisição #{$acquisition->reference_number} gerada a partir da proposta #{$quotationResponse->id}", [
                'acquisition_id' => $acquisition->id,
                'reference_number' => $acquisition->reference_number,
                'quotation_response_id' => $quotationResponse->id,
                'supplier_id' => $quotationResponse->quotationSupplier->supplier_id,
                'total_amount' => $acquisition->total_amount,
            ], auth()->user());

            return response()->json($acquisition, 201);
        });
    }

    /**
     * Cria a aquisição de uma proposta e conclui o pedido de cotação.
     */
    private function createAcquisitionFor(QuotationResponse $quotationResponse, string $expectedDeliveryDate, ?string $justification, $userId): Acquisition
    {
        $quotationResponse->load('items.quotationItem', 'quotationSupplier.quotationRequest');

        $totalAmount = 0;
        foreach ($quotationResponse->items as $item) {
            // Usar total_price se válido, caso contrário calcular
            if ($item->total_price > 0) {
                $totalAmount += $item->total_price;
            } elseif ($item->quotationItem) {
                $totalAmount += ($item->unit_price * $item->quotationItem->quantity);
            }
        }

        $acquisition = Acquisition::create([
            'quotation_request_id' => $quotationResponse->quotationSupplier->quotation_request_id,
            'quotation_response_id' => $quotationResponse->id,
            'supplier_id' => $quotationResponse->quotationSupplier->supplier_id,
            'user_id' => $userId,
            'total_amount' => $totalAmount,
            'justification' => $justification,
            'status' => 'pending',
            'expected_delivery_date' => $expectedDeliveryDate,
        ]);

        $quotationResponse->quotationSupplier->quotationRequest->update(['status' => 'completed']);

        return $acquisition;
    }

    /**
     * Motivo pelo qual não é possível decidir sobre esta proposta (ou null se for possível).
     *
     * @param  array  $extraAllowed  Estados adicionais aceites para esta acção
     */
    private function decisionBlocker(QuotationResponse $quotationResponse, array $extraAllowed = []): ?string
    {
        $quotationRequest = $quotationResponse->quotationSupplier->quotationRequest;

        if (in_array($quotationRequest->status, ['completed', 'cancelled'])) {
            return 'Este pedido de cotação já foi encerrado.';
        }

        if (Acquisition::where('quotation_request_id', $quotationRequest->id)->exists()) {
            return 'Este pedido de cotação já tem uma aquisição gerada.';
        }

        $newerExists = QuotationResponse::where('quotation_supplier_id', $quotationResponse->quotation_supplier_id)
            ->where('id', '>', $quotationResponse->id)
            ->exists();
        if ($newerExists) {
            return 'Existe uma versão mais recente desta proposta. Actualize a lista e analise a última revisão.';
        }

        if (!in_array($quotationResponse->status, array_merge(self::OPEN_STATUSES, $extraAllowed))) {
            return match ($quotationResponse->status) {
                'approved' => 'Esta proposta já foi aprovada.',
                'rejected' => 'Esta proposta já foi rejeitada.',
                'needs_revision' => 'Aguarda a revisão do fornecedor.',
                default => 'Esta proposta não pode ser alterada no estado actual.',
            };
        }

        return null;
    }

    /**
     * Envia um email sem interromper o fluxo em caso de falha.
     */
    private function sendMailSafely(?string $to, $mailable): bool
    {
        if (!$to) {
            return false;
        }

        try {
            Mail::to($to)->send($mailable);
            return true;
        } catch (\Throwable $e) {
            Log::warning('Falha ao enviar email para ' . $to . ': ' . $e->getMessage());
            return false;
        }
    }

    private function updateSupplierStatistics($supplierId) 
    {
        // Calculate metrics
        $totalQuotations = QuotationSupplier::where('supplier_id', $supplierId)->count();
        
        $totalSubmittedInvites = QuotationSupplier::where('supplier_id', $supplierId)
            ->where('status', 'submitted')
            ->count();
        
        $totalApproved = QuotationResponse::whereHas('quotationSupplier', function($q) use ($supplierId) {
            $q->where('supplier_id', $supplierId);
        })->where('status', 'approved')->count();
        
        $totalRejected = QuotationResponse::whereHas('quotationSupplier', function($q) use ($supplierId) {
            $q->where('supplier_id', $supplierId);
        })->where('status', 'rejected')->count();

        $totalAcquisitions = Acquisition::where('supplier_id', $supplierId)->count();

        // Calculate rates
        $responseRate = $totalQuotations > 0 ? ($totalSubmittedInvites / $totalQuotations) * 100 : 0;
        $successRate = $totalSubmittedInvites > 0 ? ($totalApproved / $totalSubmittedInvites) * 100 : 0;
        $acquisitionRate = $totalQuotations > 0 ? ($totalAcquisitions / $totalQuotations) * 100 : 0;

        $totalRevisions = QuotationResponse::whereHas('quotationSupplier', function($q) use ($supplierId) {
            $q->where('supplier_id', $supplierId);
        })->where('status', 'needs_revision')->count();

        // Calculate overall score (40% success + 30% response + 30% acquisition)
        $score = ($successRate * 0.4) + ($responseRate * 0.3) + (min($acquisitionRate * 2, 100) * 0.3);
        $score = min($score, 100);

        // Update or create evaluation
        SupplierEvaluation::updateOrCreate(
            ['supplier_id' => $supplierId],
            [
                'total_quotations' => $totalQuotations,
                'total_responses' => $totalSubmittedInvites,
                'total_approved' => $totalApproved,
                'total_rejected' => $totalRejected,
                'total_acquisitions' => $totalAcquisitions,
                'response_rate' => $responseRate,
                'success_rate' => $successRate,
                'acquisition_rate' => $acquisitionRate,
                'avg_response_time_hours' => 0, // Placeholder for now
                'total_revisions_requested' => $totalRevisions,
                'overall_score' => $score
            ]
        );
    }
}
