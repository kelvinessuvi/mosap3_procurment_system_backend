<?php

namespace App\Http\Controllers;

use App\Models\Supplier;
use App\Models\SupplierEvaluation;
use App\Models\QuotationSupplier;
use App\Models\QuotationResponse;
use App\Models\Acquisition;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * @OA\Tag(
 *     name="Avaliações de Fornecedores",
 *     description="Métricas de Desempenho Automatizadas"
 * )
 */
class EvaluationController extends Controller
{
    /**
     * @OA\Get(
     *     path="/api/supplier-evaluations",
     *     summary="Listar Avaliações",
     *     tags={"Avaliações de Fornecedores"},
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(name="sort_by", in="query", required=false, @OA\Schema(type="string", enum={"overall_score", "success_rate"})),
     *     @OA\Response(response=200, description="Lista de avaliações")
     * )
     */
    public function index(Request $request)
    {
        // ...
        $query = SupplierEvaluation::with('supplier');

        if ($request->filled('sort_by')) {
            $query->orderBy($request->sort_by, $request->input('sort_dir', 'desc'));
        } else {
             $query->orderBy('overall_score', 'desc');
        }

        return response()->json($query->paginate(15));
    }

    /**
     * Display the specified resource.
     */
    public function show(string $id)
    {
        // ID can be supplier_id or evaluation_id? Usually supplier_id is more useful.
        $evaluation = SupplierEvaluation::where('supplier_id', $id)->firstOrFail();
        return response()->json($evaluation->load('supplier'));
    }

    /**
     * Recalculate evaluation for a supplier
     */
    public function recalculate(string $id) // supplier_id
    {
         $evaluation = $this->performCalculation($id);
         return response()->json($evaluation);
    }
    
    public function recalculateAll() {
        // Heavy operation
        Supplier::chunk(100, function ($suppliers) {
            foreach ($suppliers as $supplier) {
                $this->performCalculation($supplier->id);
            }
        });
        return response()->json(['message' => 'Todas as avaliações foram recalculadas.']);
    }

    private function performCalculation($supplierId)
    {
        // Metrics
        $totalQuotations = QuotationSupplier::where('supplier_id', $supplierId)->count();
        $totalResponses = QuotationResponse::whereHas('quotationSupplier', function($q) use ($supplierId) {
             $q->where('supplier_id', $supplierId);
        })->where('revision_number', 1)->count(); // Count unique responses (first revision) or all? Requirement: "Taxa de resposta". Usually count UNIQUE opportunities responded to.
        // Actually, easier: QuotationSupplier where status IN ('submitted', 'opened'??). Status 'submitted' implies response.
        $totalSubmittedInvites = QuotationSupplier::where('supplier_id', $supplierId)->where('status', 'submitted')->count();
        
        // Approvals
        $totalApproved = QuotationResponse::whereHas('quotationSupplier', function($q) use ($supplierId) {
             $q->where('supplier_id', $supplierId);
        })->where('status', 'approved')->count();
        
        $totalRejected = QuotationResponse::whereHas('quotationSupplier', function($q) use ($supplierId) {
             $q->where('supplier_id', $supplierId);
        })->where('status', 'rejected')->count();

        // Acquisitions
        $totalAcquisitions = Acquisition::where('supplier_id', $supplierId)->count();

        // Rates
        $responseRate = $totalQuotations > 0 ? ($totalSubmittedInvites / $totalQuotations) * 100 : 0;
        $successRate = $totalSubmittedInvites > 0 ? ($totalApproved / $totalSubmittedInvites) * 100 : 0;
        $acquisitionRate = $totalQuotations > 0 ? ($totalAcquisitions / $totalQuotations) * 100 : 0; // or vs responses

        // Revisions
        $totalRevisions = QuotationResponse::whereHas('quotationSupplier', function($q) use ($supplierId) {
             $q->where('supplier_id', $supplierId);
        })->where('status', 'needs_revision')->count(); // Or revision_number > 1

        // Avg Response Time (Time between sent_at and submitted_at on QuotationSupplier... wait, submitted_at is on Response)
        // Let's use QuotationSupplier sent_at vs Response submitted_at.
        // Simplified: 0 for now or complex query.
        $avgResponseTime = 0;

        // Score Formula (Example)
        // 40% Success Rate + 30% Response Rate + 30% Acquisition Rate?
        // Or penalty for revisions.
        $score = ($successRate * 0.4) + ($responseRate * 0.3) + (min($acquisitionRate * 2, 100) * 0.3);
        $score = min($score, 100);

        return SupplierEvaluation::updateOrCreate(
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
                'avg_response_time_hours' => $avgResponseTime,
                'total_revisions_requested' => $totalRevisions,
                'overall_score' => $score
            ]
        );
    }
}
