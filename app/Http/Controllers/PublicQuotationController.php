<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\QuotationResponse;
use App\Models\QuotationSupplier;
use App\Services\ProcurementNotifier;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * @OA\Tag(
 *     name="Link Público (Email)",
 *     description="Endpoints acessíveis via link enviado por email (Token)"
 * )
 */
class PublicQuotationController extends Controller
{
    /**
     * @OA\Get(
     *     path="/api/quotation/{token}",
     *     summary="Visualizar Pedido",
     *     description="Permite ao fornecedor ver os detalhes do pedido usando o token do email.",
     *     tags={"Link Público (Email)"},
     *     @OA\Parameter(name="token", in="path", required=true, @OA\Schema(type="string")),
     *     @OA\Response(
     *         response=200,
     *         description="Dados do pedido recuperados",
     *         @OA\JsonContent(
     *             @OA\Property(property="quotation_supplier", type="object"),
     *             @OA\Property(property="existing_response", type="object", nullable=true)
     *         )
     *     )
     * )
     */
    public function show($token)
    {
        $qs = QuotationSupplier::with(['quotationRequest', 'supplier', 'quotationRequest.user'])
            ->where('token', $token)
            ->firstOrFail();

        if ($qs->status === 'pending' || $qs->status === 'sent') {
            $qs->update([
                'status' => 'opened',
                'opened_at' => now(),
            ]);
        }

        $response = QuotationResponse::where('quotation_supplier_id', $qs->id)
            ->with('items')
            ->latest('id')
            ->first();

        return response()->json([
            'quotation_supplier' => $qs,
            'existing_response' => $response
        ]);
    }

    /**
     * Exibe a VIEW pública para o fornecedor (Rota Web).
     */
    public function viewRequest($token)
    {
        $qs = QuotationSupplier::with(['quotationRequest', 'supplier'])
            ->where('token', $token)
            ->firstOrFail();

        // Mark as opened if accessed via browser
        if ($qs->status === 'pending' || $qs->status === 'sent') {
            $qs->update([
                'status' => 'opened',
                'opened_at' => now(),
            ]);
        }

        return view('quotation.show', [
            'quotation' => $qs->quotationRequest,
            'supplier' => $qs->supplier,
            'token' => $token,
            'quotationSupplier' => $qs
        ]);
    }

    /**
     * @OA\Post(
     *     path="/api/quotation/{token}/submit",
     *     summary="Submeter Proposta",
     *     tags={"Link Público (Email)"},
     *     @OA\Parameter(name="token", in="path", required=true, @OA\Schema(type="string")),
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\MediaType(
     *             mediaType="multipart/form-data",
     *             @OA\Schema(
     *                 required={"delivery_date", "delivery_days", "payment_terms"},
     *                 @OA\Property(property="delivery_date", type="string", format="date", example="2026-02-15"),
     *                 @OA\Property(property="delivery_days", type="integer", example=15),
     *                 @OA\Property(property="payment_terms", type="string", example="50% na encomenda, 50% na entrega"),
     *                 @OA\Property(property="observations", type="string", example="Frete incluso"),
     *                 @OA\Property(
     *                     property="proposal_file",
     *                     type="string",
     *                     format="binary",
     *                     description="Documento da proposta (PDF, DOC, DOCX - max 10MB)"
     *                 )
     *             )
     *         )
     *     ),
     *     @OA\Response(response=201, description="Proposta enviada com sucesso")
     * )
     */
    public function submit(Request $request, $token, ProcurementNotifier $notifier)
    {
        $qs = QuotationSupplier::where('token', $token)->firstOrFail();

        // Pedido encerrado: não aceita mais propostas
        if (in_array($qs->quotationRequest->status, ['completed', 'cancelled'])) {
            return response()->json(['message' => 'Este pedido de cotação já foi encerrado.'], 403);
        }

        if ($qs->status === 'declined') {
            return response()->json(['message' => 'A participação neste pedido foi declinada.'], 403);
        }

        // Só é possível reenviar quando a equipa pediu uma revisão da última proposta
        $lastResponse = QuotationResponse::where('quotation_supplier_id', $qs->id)->latest('id')->first();
        if ($lastResponse && !in_array($lastResponse->status, ['needs_revision', 'negotiating'])) {
            return response()->json(['message' => 'Proposta já submetida.'], 403);
        }

        Log::info('Quotation Submit Input (Raw):', $request->all());

        // Normalize inputs (Support camelCase from JS frontends)
        $input = $request->all();
        if (isset($input['deliveryDate'])) $input['delivery_date'] = $input['deliveryDate'];
        if (isset($input['deliveryDays'])) $input['delivery_days'] = $input['deliveryDays'];
        if (isset($input['paymentTerms'])) $input['payment_terms'] = $input['paymentTerms'];

        $request->merge($input);

        $validated = $request->validate([
            'observations' => 'nullable|string',
            'delivery_date' => 'required|date|after:now',
            'delivery_days' => 'required|integer|min:0',
            'payment_terms' => 'required|string',
            'proposal_file' => 'nullable|file|mimes:pdf,doc,docx|max:10240',
        ]);

        $response = DB::transaction(function () use ($validated, $qs, $request) {
            
            // Determine revision number
            $lastResponse = QuotationResponse::where('quotation_supplier_id', $qs->id)->latest('id')->first();
            $revisionNumber = $lastResponse ? $lastResponse->revision_number + 1 : 1;

            // If we want to keep history, maybe we snapshot the OLD one before creating new?
            // Or we just create a NEW response row and keep old ones?
            // "Novo token gerado para cada revisão" -> If token is new, it's a NEW QuotationSupplier entry typically?
            // If token belongs to SAME QuotationSupplier, then we probably create a NEW Response linked to SAME SupplierInvite.
            // Let's create a NEW Response.

            // Handle file upload
            $proposalDocument = null;
            $proposalDocumentOriginalName = null;
            if ($request->hasFile('proposal_file')) {
                $file = $request->file('proposal_file');
                $proposalDocumentOriginalName = $file->getClientOriginalName();
                $proposalDocument = $file->store('proposals', 'public');
            }

            $response = QuotationResponse::create([
                'quotation_supplier_id' => $qs->id,
                'user_id' => null, // Supplier submitted
                'observations' => $validated['observations'] ?? null,
                'delivery_date' => $validated['delivery_date'],
                'delivery_days' => $validated['delivery_days'],
                'payment_terms' => $validated['payment_terms'],
                'submitted_at' => now(),
                'status' => 'pending_review',
                'revision_number' => $revisionNumber,
                'proposal_document' => $proposalDocument,
                'proposal_document_original_name' => $proposalDocumentOriginalName,
            ]);

            // Update Status of Invitation
            $qs->update([
                'status' => 'submitted',
            ]);

            // Update Quotation Request to 'in_progress' if logic dictates (e.g. at least one response)
            if ($qs->quotationRequest->status === 'sent') {
                $qs->quotationRequest->update(['status' => 'in_progress']);
            }
            
            // If previous response existed, maybe log to history?
            // Since we created a NEW response record, the old one exists as history basically.
            // But Requirements said "QuotationResponseHistory".
            // Implementation Plan: "QuotationResponseHistory... snapshot JSON".
            // So maybe we copy this NEW response to History as "submitted" snapshot?
            // Or we just use the multiple Response rows as history? 
            // "hasMany QuoationResponseHistory".
            // Let's create a history entry for this submission action.

            $response->history()->create([
                'revision_number' => $revisionNumber,
                'items_data' => [],
                'total_amount' => 0,
                'action' => 'submitted',
                'action_notes' => 'Proposta submetida pelo fornecedor',
            ]);

            // Update supplier evaluation metrics
            $this->updateSupplierEvaluation($qs->supplier_id);

            AuditLog::log('Submissão de proposta', "Fornecedor '{$qs->supplier->company_name}' submeteu proposta para cotação #{$qs->quotationRequest->id}", [
                'quotation_request_id' => $qs->quotationRequest->id,
                'quotation_supplier_id' => $qs->id,
                'supplier_id' => $qs->supplier_id,
                'supplier_name' => $qs->supplier->company_name,
                'revision_number' => $revisionNumber,
            ]);

            return $response;
        });

        // Notificar a equipa (in-app + email) depois de a proposta estar gravada
        $qs->load('supplier', 'quotationRequest');
        $isRevision = $response->revision_number > 1;
        $supplierName = $qs->supplier->company_name;
        $quotationRequest = $qs->quotationRequest;
        $notifier->notifyStaff(
            $quotationRequest,
            $isRevision ? 'quotation_response_revised' : 'quotation_response_submitted',
            $isRevision ? 'Proposta Revista Recebida' : 'Nova Proposta Recebida',
            $isRevision
                ? "O fornecedor {$supplierName} submeteu a revisão n.º {$response->revision_number} da proposta para \"{$quotationRequest->title}\"."
                : "O fornecedor {$supplierName} submeteu uma proposta para \"{$quotationRequest->title}\".",
            [
                'quotation_response_id' => $response->id,
                'supplier_id' => $qs->supplier_id,
                'supplier_name' => $supplierName,
                'revision_number' => $response->revision_number,
            ],
            [
                'Pedido' => $quotationRequest->reference_number,
                'Actividade' => $quotationRequest->title,
                'Fornecedor' => $supplierName,
                'Revisão' => $isRevision ? "n.º {$response->revision_number}" : null,
                'Data de entrega proposta' => optional($response->delivery_date)->format('d/m/Y'),
                'Condições de pagamento' => $response->payment_terms,
            ]
        );

        return response()->json($response, 201);
    }

    /**
     * @OA\Post(
     *     path="/api/quotation/{token}/decline",
     *     summary="Declinar Participação",
     *     tags={"Link Público (Email)"},
     *     @OA\Parameter(name="token", in="path", required=true, @OA\Schema(type="string")),
     *     @OA\Response(response=200, description="Participação apenas declinada")
     * )
     */
    public function decline(Request $request, $token, ProcurementNotifier $notifier)
    {
        $qs = QuotationSupplier::where('token', $token)->firstOrFail();
        $qs->load('supplier', 'quotationRequest');
        $qs->update(['status' => 'declined']);

        $notifier->notifyStaff(
            $qs->quotationRequest,
            'quotation_declined',
            'Fornecedor Declinou Convite',
            "O fornecedor {$qs->supplier->company_name} declinou a participação em \"{$qs->quotationRequest->title}\".",
            [
                'supplier_id' => $qs->supplier_id,
                'supplier_name' => $qs->supplier->company_name,
            ],
            [
                'Pedido' => $qs->quotationRequest->reference_number,
                'Actividade' => $qs->quotationRequest->title,
                'Fornecedor' => $qs->supplier->company_name,
            ]
        );

        AuditLog::log('Declínio de cotação', "Fornecedor '{$qs->supplier->company_name}' declinou cotação #{$qs->quotationRequest->id}", [
            'quotation_request_id' => $qs->quotationRequest->id,
            'quotation_supplier_id' => $qs->id,
            'supplier_id' => $qs->supplier_id,
            'supplier_name' => $qs->supplier->company_name,
        ]);

        return response()->json(['message' => 'Participação declinada.']);
    }

    /**
     * @OA\Get(
     *     path="/api/quotation/{token}/attachments/{index}",
     *     summary="Descarregar Anexo do Pedido de Cotação",
     *     description="Permite ao fornecedor descarregar um documento anexado ao pedido de cotação. Acesso via token do email, sem autenticação necessária.",
     *     tags={"Link Público (Email)"},
     *     @OA\Parameter(
     *         name="token",
     *         in="path",
     *         required=true,
     *         description="Token de acesso do fornecedor (enviado por email)",
     *         @OA\Schema(type="string")
     *     ),
     *     @OA\Parameter(
     *         name="index",
     *         in="path",
     *         required=true,
     *         description="Índice do anexo na lista de documentos (começa em 0)",
     *         @OA\Schema(type="integer", minimum=0)
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Documento retornado com sucesso",
     *         @OA\MediaType(mediaType="application/pdf"),
     *         @OA\MediaType(mediaType="application/msword"),
     *         @OA\MediaType(mediaType="application/vnd.openxmlformats-officedocument.wordprocessingml.document"),
     *         @OA\MediaType(mediaType="image/jpeg"),
     *         @OA\MediaType(mediaType="image/png")
     *     ),
     *     @OA\Response(response=404, description="Anexo ou ficheiro não encontrado")
     * )
     */
    public function downloadAttachment($token, $index)
    {
        $qs = QuotationSupplier::with('quotationRequest')
            ->where('token', $token)
            ->firstOrFail();

        $attachments = $qs->quotationRequest->attachments ?? [];

        if (!isset($attachments[$index])) {
            abort(404, 'Anexo não encontrado.');
        }

        $attachment = $attachments[$index];
        $filePath = $attachment['path'];

        if (!\Illuminate\Support\Facades\Storage::disk('public')->exists($filePath)) {
            abort(404, 'Ficheiro não encontrado.');
        }

        return response()->file(
            \Illuminate\Support\Facades\Storage::disk('public')->path($filePath),
            [
                'Content-Type' => \Illuminate\Support\Facades\Storage::disk('public')->mimeType($filePath),
                'Content-Disposition' => 'inline; filename="' . ($attachment['original_name'] ?? basename($filePath)) . '"'
            ]
        );
    }

    private function updateSupplierEvaluation($supplierId) 
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

        $totalAcquisitions = \App\Models\Acquisition::where('supplier_id', $supplierId)->count();

        // Calculate rates
        $responseRate = $totalQuotations > 0 ? ($totalSubmittedInvites / $totalQuotations) * 100 : 0;
        $successRate = $totalSubmittedInvites > 0 ? ($totalApproved / $totalSubmittedInvites) * 100 : 0;
        $acquisitionRate = $totalQuotations > 0 ? ($totalAcquisitions / $totalQuotations) * 100 : 0;

        $totalRevisions = QuotationResponse::whereHas('quotationSupplier', function($q) use ($supplierId) {
            $q->where('supplier_id', $supplierId);
        })->where('status', 'needs_revision')->count();

        // Calculate overall score
        $score = ($successRate * 0.4) + ($responseRate * 0.3) + (min($acquisitionRate * 2, 100) * 0.3);
        $score = min($score, 100);

        // Update or create evaluation
        \App\Models\SupplierEvaluation::updateOrCreate(
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
                'avg_response_time_hours' => 0,
                'total_revisions_requested' => $totalRevisions,
                'overall_score' => $score
            ]
        );
    }
}
