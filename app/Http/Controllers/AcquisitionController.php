<?php

namespace App\Http\Controllers;

use App\Models\Acquisition;
use App\Models\QuotationItem;
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
                DB::raw('SUM(quotation_items.quantity) as total_quantity'), // Quantity is on Request Item (QuotationItem). ResponseItem usually confirms unit price but quantity is fixed by Request unless negotiation changed it? Wait, QuotationResponseItem doesn't have quantity in migration, it links to QuotationItem which has quantity. Correct.
                DB::raw('SUM(quotation_response_items.total_price) as total_spent'),
                DB::raw('COUNT(acquisitions.id) as acquisition_count')
            )
            ->groupBy('product_name')
            ->orderBy('total_spent', 'desc')
            ->limit($limit)
            ->get();

        return response()->json($stats);
    }
}
