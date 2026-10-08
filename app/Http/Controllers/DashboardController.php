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
    public function index(Request $request)
    {
        $user = $request->user();

        // O painel mostra o trabalho de quem o abre: o administrador vê tudo, o
        // técnico vê os processos que iniciou e aqueles que lhe foram atribuídos.
        // As listas abaixo são clicáveis — mostrar processos que o utilizador não
        // pode abrir dava-lhe 403 ao clicar.
        return response()->json([
            'counts' => [
                'active_quotations' => QuotationRequest::visibleTo($user)
                    ->whereIn('status', ['sent', 'in_progress'])->count(),
                'pending_reviews' => QuotationResponse::visibleTo($user)
                    ->where('status', 'pending_review')->count(),
                // Os fornecedores não pertencem a um processo: contagem global.
                'active_suppliers' => Supplier::where('is_active', true)->count(),
                'total_quotations' => QuotationRequest::visibleTo($user)->count(),
            ],
            'recent_quotations' => QuotationRequest::visibleTo($user)->latest()->take(5)->get(),
            'pending_actions' => QuotationResponse::with(['quotationSupplier.supplier', 'quotationSupplier.quotationRequest'])
                                    ->visibleTo($user)
                                    ->where('status', 'pending_review')
                                    ->take(5)
                                    ->get(),
        ]);
    }
}
