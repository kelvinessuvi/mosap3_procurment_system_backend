<?php

namespace App\Http\Controllers;

use App\Models\QuotationRequest;
use App\Models\QuotationResponse;
use App\Models\Supplier;
use Illuminate\Http\Request;

/**
 * @OA\Tag(
 *     name="Dashboard",
 *     description="Estatísticas e Visão Geral"
 * )
 */
class DashboardController extends Controller
{
    /**
     * @OA\Get(
     *     path="/api/dashboard",
     *     summary="Estatísticas do Sistema",
     *     tags={"Dashboard"},
     *     security={{"bearerAuth":{}}},
     *     @OA\Response(
     *         response=200,
     *         description="Dados do dashboard",
     *         @OA\JsonContent(
     *             @OA\Property(property="counts", type="object",
     *                 @OA\Property(property="active_quotations", type="integer"),
     *                 @OA\Property(property="pending_reviews", type="integer")
     *             ),
     *             @OA\Property(property="recent_quotations", type="array", @OA\Items(type="object"))
     *         )
     *     )
     * )
     */
    public function index()
    {
        return response()->json([
            'counts' => [
                'active_quotations' => QuotationRequest::whereIn('status', ['sent', 'in_progress'])->count(),
                'pending_reviews' => QuotationResponse::where('status', 'pending_review')->count(),
                'active_suppliers' => Supplier::where('is_active', true)->count(),
                'total_quotations' => QuotationRequest::count(),
            ],
            'recent_quotations' => QuotationRequest::latest()->take(5)->get(),
            'pending_actions' => QuotationResponse::with(['quotationSupplier.supplier', 'quotationSupplier.quotationRequest'])
                                    ->where('status', 'pending_review')
                                    ->take(5)
                                    ->get(),
        ]);
    }
}
