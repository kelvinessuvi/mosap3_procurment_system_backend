<?php

namespace App\Http\Controllers;

use App\Models\Acquisition;
use App\Models\AuditLog;
use App\Models\DeletionRequest;
use App\Models\Notification;
use App\Models\QuotationItem;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * @OA\Tag(
 *     name="Aquisições",
 *     description="Histórico e Relatórios de Aquisições"
 * )
 */
class AcquisitionController extends Controller
{
    /**
     * @OA\Get(
     *     path="/api/acquisitions",
     *     summary="Listar Aquisições",
     *     description="Lista todas as aquisições com filtros.",
     *     tags={"Aquisições"},
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(name="supplier_id", in="query", @OA\Schema(type="integer")),
     *     @OA\Parameter(name="start_date", in="query", @OA\Schema(type="string", format="date")),
     *     @OA\Parameter(name="end_date", in="query", @OA\Schema(type="string", format="date")),
     *     @OA\Response(response=200, description="Lista de aquisições")
     * )
     */
    public function index(Request $request)
    {
        $query = Acquisition::with(['supplier', 'user', 'quotationRequest']);

        if ($request->filled('supplier_id')) {
            $query->where('supplier_id', $request->supplier_id);
        }

        if ($request->filled('start_date')) {
            $query->whereDate('created_at', '>=', $request->start_date);
        }

        if ($request->filled('end_date')) {
            $query->whereDate('created_at', '<=', $request->end_date);
        }

        return response()->json($query->paginate(15));
    }

    /**
     * @OA\Get(
     *     path="/api/suppliers/{id}/acquisitions",
     *     summary="Histórico de Aquisições do Fornecedor",
     *     tags={"Aquisições"},
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")),
     *     @OA\Response(response=200, description="Histórico do fornecedor")
     * )
     */
    public function supplierHistory($id)
    {
        $acquisitions = Acquisition::where('supplier_id', $id)
            ->with(['quotationRequest'])
            ->orderBy('created_at', 'desc')
            ->paginate(15);

        return response()->json($acquisitions);
    }

    /**
     * @OA\Get(
     *     path="/api/acquisitions/stats/products",
     *     summary="Produtos Mais Adquiridos",
     *     description="Retorna ranking de produtos mais comprados por quantidade ou valor total.",
     *     tags={"Aquisições"},
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(name="start_date", in="query", @OA\Schema(type="string", format="date")),
     *     @OA\Parameter(name="end_date", in="query", @OA\Schema(type="string", format="date")),
     *     @OA\Parameter(name="limit", in="query", @OA\Schema(type="integer")),
     *     @OA\Response(response=200, description="Ranking de produtos")
     * )
     */
    public function productStats(Request $request)
    {
        // This query is a bit complex. We need to go from Acquisition -> QuotationResponse -> Items -> QuotationItem -> Product
        // But simplified: Acquisition links to QuotationRequest.
        // And specific items acquired are in QuotationResponse linked to Acquisition.
        
        // Actually, Acquisition has `quotation_response_id`.
        // QuotationResponse has items (QuotationResponseItem).
        // QuotationResponseItem links to QuotationItem.
        // QuotationItem has product_id (if we link it).
        // Or just group by name if product_id is null.

        $startDate = $request->input('start_date', now()->subYear());
        $endDate = $request->input('end_date', now());
        $limit = $request->input('limit', 10);

        // We'll query QuotationResponseItems where the related QuotationResponse belongs to an Acquisition
        
        $stats = DB::table('quotation_response_items')
            ->join('quotation_responses', 'quotation_response_items.quotation_response_id', '=', 'quotation_responses.id')
            ->join('acquisitions', 'quotation_responses.id', '=', 'acquisitions.quotation_response_id')
            ->join('quotation_items', 'quotation_response_items.quotation_item_id', '=', 'quotation_items.id')
            // Left join products to get normalized names if available
            ->leftJoin('products', 'quotation_items.product_id', '=', 'products.id')
            ->whereBetween('acquisitions.created_at', [$startDate, $endDate])
            ->select(
                DB::raw('COALESCE(products.name, quotation_items.name) as product_name'),
                DB::raw('SUM(quotation_items.quantity) as total_quantity'), 
                DB::raw('SUM(COALESCE(quotation_response_items.total_price, quotation_response_items.unit_price * quotation_items.quantity)) as total_spent'),
                DB::raw('COUNT(acquisitions.id) as acquisition_count')
            )
            ->groupBy('product_name')
            ->orderBy('total_spent', 'desc')
            ->limit($limit)
            ->get();

        return response()->json($stats);
    }

    /**
     * @OA\Post(
     *     path="/api/acquisitions/{acquisition}/confirm-delivery",
     *     summary="Confirmar Entrega de Aquisição",
     *     tags={"Aquisições"},
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(name="acquisition", in="path", required=true, @OA\Schema(type="integer")),
     *     @OA\Response(response=200, description="Aquisição marcada como completa")
     * )
     */
    public function confirmDelivery(Request $request, Acquisition $acquisition)
    {
        if ($acquisition->status !== 'pending' && $acquisition->status !== 'in_progress') {
            return response()->json(['message' => 'Status inválido para confirmação.'], 400);
        }

        $acquisition->update([
            'status' => 'completed',
            'actual_delivery_date' => now()
        ]);

        AuditLog::log('Confirmação de entrega', "Aquisição #{$acquisition->reference_number} teve entrega confirmada", [
            'acquisition_id' => $acquisition->id,
            'reference_number' => $acquisition->reference_number,
            'supplier_id' => $acquisition->supplier_id,
        ], $request->user());

        // Notify the requester
        $acquisition->load('quotationRequest');
        if ($acquisition->quotationRequest && $acquisition->quotationRequest->user_id) {
            \App\Models\Notification::create([
                'user_id' => $acquisition->quotationRequest->user_id,
                'type' => 'acquisition_delivered',
                'title' => 'Entrega Confirmada',
                'message' => "A entrega da aquisição #{$acquisition->reference_number} foi confirmada.",
                'data' => [
                    'acquisition_id' => $acquisition->id,
                    'reference_number' => $acquisition->reference_number,
                    'supplier_name' => $acquisition->supplier->company_name ?? 'Fornecedor'
                ]
            ]);
        }

        return response()->json([
            'message' => 'Entrega confirmada e aquisição concluída.',
            'acquisition' => $acquisition
        ]);
    }

    /**
     * @OA\Delete(
     *     path="/api/acquisitions/{acquisition}",
     *     summary="Remover aquisição ou solicitar exclusão",
     *     description="Admin remove diretamente (204). Não-admin cria pedido de exclusão pendente para aprovação (201).",
     *     tags={"Aquisições"},
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(name="acquisition", in="path", required=true, @OA\Schema(type="integer")),
     *     @OA\RequestBody(
     *         @OA\JsonContent(
     *             @OA\Property(property="reason", type="string", description="Motivo/justificativa (obrigatório para não-admin)", example="Aquisição cancelada")
     *         )
     *     ),
     *     @OA\Response(response=204, description="Aquisição removida diretamente (admin)"),
     *     @OA\Response(response=201, description="Pedido de exclusão criado (não-admin)"),
     *     @OA\Response(response=409, description="Já existe um pedido pendente para esta aquisição")
     * )
     */
    public function destroy(Request $request, Acquisition $acquisition)
    {
        $user = $request->user();

        if ($user->role === 'admin') {
            $acquisition->delete();
            return response()->json(null, 204);
        }

        $pending = DeletionRequest::where('requestable_type', Acquisition::class)
            ->where('requestable_id', $acquisition->id)
            ->where('status', 'pending')
            ->exists();

        if ($pending) {
            return response()->json(['message' => 'Já existe um pedido de exclusão pendente para esta aquisição.'], 409);
        }

        $validated = $request->validate([
            'reason' => 'required|string',
        ]);

        $deletionRequest = DeletionRequest::create([
            'requestable_type' => Acquisition::class,
            'requestable_id' => $acquisition->id,
            'requested_by' => $user->id,
            'reason' => $validated['reason'],
        ]);

        $admins = User::where('role', 'admin')->get();
        foreach ($admins as $admin) {
            Notification::create([
                'user_id' => $admin->id,
                'type' => 'deletion_requested',
                'title' => 'Solicitação de Exclusão',
                'message' => "O utilizador {$user->name} solicitou a exclusão da aquisição #{$acquisition->reference_number}.\nMotivo: {$validated['reason']}",
                'data' => [
                    'deletion_request_id' => $deletionRequest->id,
                    'requestable_type' => Acquisition::class,
                    'requestable_id' => $acquisition->id,
                    'identifier' => $acquisition->reference_number,
                    'requested_by_name' => $user->name,
                    'reason' => $validated['reason'],
                ],
            ]);
        }

        AuditLog::log('Solicitação de exclusão', "Utilizador '{$user->name}' solicitou exclusão da aquisição #{$acquisition->reference_number}", [
            'deletion_request_id' => $deletionRequest->id,
            'acquisition_id' => $acquisition->id,
            'reference_number' => $acquisition->reference_number,
            'reason' => $validated['reason'],
        ], $user);

        return response()->json([
            'message' => 'Solicitação de exclusão enviada para aprovação do administrador.',
            'deletion_request' => $deletionRequest,
        ], 201);
    }
}
