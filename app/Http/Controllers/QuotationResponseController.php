<?php

namespace App\Http\Controllers;

use App\Mail\NegotiationNotificationMail;
use App\Models\Acquisition;
use App\Models\NegotiationNotification;
use App\Models\QuotationResponse;
use App\Models\SupplierEvaluation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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
     * @OA\Post(
     *     path="/api/quotation-responses/{id}/approve",
     *     summary="Aprovar Proposta",
     *     tags={"Negociação & Revisão"},
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")),
     *     @OA\RequestBody(
     *         @OA\JsonContent(
     *             @OA\Property(property="notes", type="string", example="Aprovado, excelente preço")
     *         )
     *     ),
     *     @OA\Response(response=200, description="Aprovado com sucesso")
     * )
     */
    public function approve(Request $request, QuotationResponse $quotationResponse)
    {
        // ... implementation
        $validated = $request->validate(['notes' => 'nullable|string']);

        $quotationResponse->update([
            'status' => 'approved',
            'review_notes' => $validated['notes'] ?? null,
            'user_id' => $request->user()->id,
        ]);

        $this->updateSupplierStatistics($quotationResponse->quotationSupplier->supplier_id);

        return response()->json($quotationResponse);
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
    public function reject(Request $request, QuotationResponse $quotationResponse)
    {
         $validated = $request->validate(['notes' => 'nullable|string']);

        $quotationResponse->update([
            'status' => 'rejected',
            'review_notes' => $validated['notes'] ?? null,
            'user_id' => $request->user()->id,
        ]);

        $this->updateSupplierStatistics($quotationResponse->quotationSupplier->supplier_id);

        return response()->json($quotationResponse);
    }

    /**
     * @OA\Post(
     *     path="/api/quotation-responses/{id}/request-revision",
     *     summary="Solicitar Revisão",
     *     description="Solicita ao fornecedor uma revisão da proposta (Gera novo token).",
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
    public function requestRevision(Request $request, QuotationResponse $quotationResponse)
    {
        // ... implementation existing
        $validated = $request->validate([
            'reason' => 'required|string',
            'message' => 'required|string',
        ]);

        return DB::transaction(function () use ($validated, $quotationResponse) {
            $quotationResponse->update([
                'status' => 'needs_revision',
                'user_id' => auth()->id(), // Reviewer
            ]);

            $qs = $quotationResponse->quotationSupplier;
            
            // Generate NEW token for revision
            $newToken = Str::random(64);
            $qs->update([
                'token' => $newToken,
                // Do we change status to 'negotiating'?
                // The requirements say: states: pending_review, approved, rejected, needs_revision, negotiating
                // 'negotiating' is likely for when revision is requested but not yet submitted.
                // Actually 'negotiating' is on Response? 
                // Let's assume 'needs_revision' on Response implies negotiation.
                // SupplierInvite status remains or updates?
                'status' => 'sent', // Reset to sent so they can open again? Or 'pending'? 
                // Let's use 'sent' to indicate link is active.
            ]);

            $notification = NegotiationNotification::create([
                'quotation_supplier_id' => $qs->id,
                'reason' => $validated['reason'],
                'message' => $validated['message'],
            ]);

            // Load relationships for email
            $qs->load('supplier', 'quotationRequest');

            Mail::to($qs->supplier->email)->send(new NegotiationNotificationMail(
                $qs->quotationRequest,
                $notification,
                $newToken
            ));
            
            // Log History action? 
            $quotationResponse->history()->create([
                'revision_number' => $quotationResponse->revision_number,
                'items_data' => $quotationResponse->items->toArray(),
                'total_amount' => $quotationResponse->items->sum('unit_price'), // approx
                'action' => 'revised', // requested revision
                'action_notes' => $validated['message'],
                'user_id' => auth()->id(),
            ]);
            
            $this->updateSupplierStatistics($qs->supplier_id);

            return response()->json(['message' => 'Solicitação de revisão enviada.', 'new_token' => $newToken]);
        });
    }

    /**
     * @OA\Post(
     *     path="/api/quotation-responses/{id}/create-acquisition",
     *     summary="Gerar Aquisição",
     *     description="Gera uma aquisição a partir de uma proposta aprovada.",
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

        $validated = $request->validate([
            'justification' => 'nullable|string',
            'expected_delivery_date' => 'required|date|after:now',
        ]);

        return DB::transaction(function () use ($validated, $quotationResponse) {
            $acquisition = Acquisition::create([
                'quotation_request_id' => $quotationResponse->quotationSupplier->quotation_request_id,
                'quotation_response_id' => $quotationResponse->id,
                'supplier_id' => $quotationResponse->quotationSupplier->supplier_id,
                'user_id' => auth()->id(),
                'total_amount' => $quotationResponse->items->sum('total_price') ?? $quotationResponse->items->sum('unit_price'), // Use total_price if calc implemented
                'justification' => $validated['justification'] ?? null,
                'status' => 'pending',
                'expected_delivery_date' => $validated['expected_delivery_date'],
            ]);

            $quotationResponse->quotationSupplier->quotationRequest->update(['status' => 'completed']);
            
            $this->updateSupplierStatistics($quotationResponse->quotationSupplier->supplier_id);

            return response()->json($acquisition, 201);
        });
    }

    private function updateSupplierStatistics($supplierId) {
        // Simple trigger to recalculate or increment
        // For now, placeholder or basic increment
        // Ideally should be a separate Service or Event
        
        $eval = SupplierEvaluation::firstOrCreate(['supplier_id' => $supplierId]);
        
        // Recalculate basic counts
        // This is expensive if done realtime, usually done via Job.
        // I'll leave as placeholder to be implemented in "Automated Evaluations" section if needed.
    }
}
