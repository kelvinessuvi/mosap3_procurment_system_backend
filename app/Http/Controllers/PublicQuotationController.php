<?php

namespace App\Http\Controllers;

use App\Models\QuotationResponse;
use App\Models\QuotationSupplier;
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
        $qs = QuotationSupplier::with(['quotationRequest.items', 'supplier', 'quotationRequest.user'])
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
            ->latest()
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
        $qs = QuotationSupplier::with(['quotationRequest.items', 'supplier'])
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
     *                 required={"delivery_date", "delivery_days", "payment_terms", "items"},
     *                 @OA\Property(property="delivery_date", type="string", format="date", example="2026-02-15"),
     *                 @OA\Property(property="delivery_days", type="integer", example=15),
     *                 @OA\Property(property="payment_terms", type="string", example="50% na encomenda, 50% na entrega"),
     *                 @OA\Property(property="observations", type="string", example="Frete incluso"),
     *                 @OA\Property(
     *                     property="items",
     *                     type="array",
     *                     @OA\Items(
     *                         required={"quotation_item_id", "unit_price"},
     *                         @OA\Property(property="quotation_item_id", type="integer", example=1),
     *                         @OA\Property(property="unit_price", type="number", format="float", example=4500.00),
     *                         @OA\Property(property="notes", type="string", example="Modelo similar ao solicitado")
     *                     )
     *                 ),
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
    public function submit(Request $request, $token)
    {
        $qs = QuotationSupplier::where('token', $token)->firstOrFail();

        if (in_array($qs->status, ['submitted', 'declined']) && $qs->quotationRequest->status !== 'in_progress') {
             // Allow resobmission only if requested revision? 
             // Logic: If status is 'submitted', maybe block. If 'needs_revision' (on response), allow.
             // But qs status is 'submitted' usually. 
             // Let's check the Response status.
             $lastResponse = QuotationResponse::where('quotation_supplier_id', $qs->id)->latest()->first();
             if ($lastResponse && !in_array($lastResponse->status, ['needs_revision', 'negotiating'])) {
                 return response()->json(['message' => 'Proposta já submetida.'], 403);
             }
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
            'items' => 'required|array',
            'items.*.quotation_item_id' => 'required|exists:quotation_items,id',
            'items.*.unit_price' => 'required|numeric|min:0',
            'items.*.notes' => 'nullable|string',
            'proposal_file' => 'nullable|file|mimes:pdf,doc,docx|max:10240',
        ]);

        return DB::transaction(function () use ($validated, $qs, $request) {
            
            // Determine revision number
            $lastResponse = QuotationResponse::where('quotation_supplier_id', $qs->id)->latest()->first();
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

            foreach ($validated['items'] as $item) {
                // Get the quotation item to access quantity
                $quotationItem = \App\Models\QuotationItem::find($item['quotation_item_id']);
                $totalPrice = $item['unit_price'] * $quotationItem->quantity;
                
                $response->items()->create([
                    'quotation_item_id' => $item['quotation_item_id'],
                    'unit_price' => $item['unit_price'],
                    'total_price' => $totalPrice,
                    'notes' => $item['notes'] ?? null,
                ]);
            }

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
                'items_data' => $validated['items'],
                'total_amount' => collect($validated['items'])->sum('unit_price'), // Simplified total
                'action' => 'submitted',
                'action_notes' => 'Proposta submetida pelo fornecedor',
            ]);

            return response()->json($response->load('items'), 201);
        });
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
    public function decline(Request $request, $token)
    {
        $qs = QuotationSupplier::where('token', $token)->firstOrFail();
        $qs->update(['status' => 'declined']);
        return response()->json(['message' => 'Participação declinada.']);
    }
}
