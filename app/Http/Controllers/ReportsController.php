<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

/**
 * @OA\Tag(
 *     name="Relatórios",
 *     description="Relatórios Gerenciais e Estatísticas"
 * )
 */
class ReportsController extends Controller
{
    /**
     * @OA\Get(
     *     path="/api/reports/summary",
     *     summary="Relatório Executivo",
     *     description="Retorna métricas consolidadas por período (semanal, mensal, anual)",
     *     tags={"Relatórios"},
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(name="period", in="query", @OA\Schema(type="string", enum={"weekly", "monthly", "yearly"}, default="monthly")),
     *     @OA\Parameter(name="start_date", in="query", @OA\Schema(type="string", format="date")),
     *     @OA\Parameter(name="end_date", in="query", @OA\Schema(type="string", format="date")),
     *     @OA\Response(response=200, description="Dados do relatório")
     * )
     */
    public function index(Request $request)
    {
        $period = $request->input('period', 'monthly');
        $startDate = $request->input('start_date');
        $endDate = $request->input('end_date');

        // Determine date range if not provided
        if (!$startDate || !$endDate) {
            $now = \Carbon\Carbon::now();
            switch ($period) {
                case 'weekly':
                    $startDate = $now->copy()->startOfWeek()->format('Y-m-d');
                    $endDate = $now->copy()->endOfWeek()->format('Y-m-d');
                    break;
                case 'yearly':
                    $startDate = $now->copy()->startOfYear()->format('Y-m-d');
                    $endDate = $now->copy()->endOfYear()->format('Y-m-d');
                    break;
                case 'monthly':
                default:
                    $startDate = $now->copy()->startOfMonth()->format('Y-m-d');
                    $endDate = $now->copy()->endOfMonth()->format('Y-m-d');
                    break;
            }
        }

        $metrics = $this->getMetrics($startDate, $endDate);
        $charts = $this->getCharts($startDate, $endDate, $period);
        $topSuppliers = $this->getTopSuppliers($startDate, $endDate);
        $topProducts = $this->getTopProducts($startDate, $endDate);

        return response()->json([
            'period' => [
                'start' => $startDate,
                'end' => $endDate,
                'type' => $period
            ],
            'metrics' => $metrics,
            'charts' => $charts,
            'top_suppliers' => $topSuppliers,
            'top_products' => $topProducts
        ]);
    }

    private function getMetrics($startDate, $endDate)
    {
        $acquisitions = \App\Models\Acquisition::whereBetween('created_at', [$startDate, $endDate])
            ->whereIn('status', ['completed', 'in_progress']);

        $totalSpent = $acquisitions->sum('total_amount');
        $count = $acquisitions->count();
        $avgTicket = $count > 0 ? $totalSpent / $count : 0;

        return [
            'total_spent' => round($totalSpent, 2),
            'total_acquisitions' => $count,
            'avg_ticket' => round($avgTicket, 2),
            'completed_count' => \App\Models\Acquisition::whereBetween('created_at', [$startDate, $endDate])->where('status', 'completed')->count(),
            'pending_count' => \App\Models\Acquisition::whereBetween('created_at', [$startDate, $endDate])->where('status', 'pending')->count(),
        ];
    }

    private function getCharts($startDate, $endDate, $period)
    {
        // For monthly/weekly, group by day. For yearly, group by month.
        $groupBy = ($period === 'yearly') ? 'MONTH' : 'DATE';
        
        $dateFormat = ($period === 'yearly') ? '%Y-%m' : '%Y-%m-%d';
        
        // This is SQLite syntax (since we are testing locally often with sqlite), 
        // but let's assume MySQL for production ('%Y-%m-%d').
        // Laravel usually abstracts this but raw queries need specific SQL dialect.
        // Assuming MySQL for this output as per user's likely env (mysql mention earlier).
        
        $driver = \Illuminate\Support\Facades\DB::connection()->getDriverName();
        $formatFunc = ($driver === 'sqlite') ? 'strftime' : 'DATE_FORMAT';
        
        // Adjust for SQLite nuances if needed, keeping it simple for now or using Carbon loop
        // It's safer to query raw data and group in PHP to be DB agnostic for this assistant logic
        
        $data = \App\Models\Acquisition::whereBetween('created_at', [$startDate, $endDate])
            ->whereIn('status', ['completed', 'in_progress'])
            ->orderBy('created_at')
            ->get()
            ->groupBy(function($date) use ($period) {
                if ($period === 'yearly') {
                     return \Carbon\Carbon::parse($date->created_at)->format('Y-m'); // Group by month
                }
                return \Carbon\Carbon::parse($date->created_at)->format('Y-m-d'); // Group by day
            });

        $chartData = [];
        foreach ($data as $key => $values) {
            $chartData[] = [
                'date' => $key,
                'value' => round($values->sum('total_amount'), 2),
                'count' => $values->count()
            ];
        }

        return ['spending_over_time' => $chartData];
    }

    private function getTopSuppliers($startDate, $endDate)
    {
        return \Illuminate\Support\Facades\DB::table('acquisitions')
            ->join('suppliers', 'acquisitions.supplier_id', '=', 'suppliers.id')
            ->whereBetween('acquisitions.created_at', [$startDate, $endDate])
            ->whereIn('acquisitions.status', ['completed', 'in_progress'])
            ->select(
                'suppliers.id',
                'suppliers.commercial_name as name',
                \Illuminate\Support\Facades\DB::raw('SUM(acquisitions.total_amount) as total'),
                \Illuminate\Support\Facades\DB::raw('COUNT(acquisitions.id) as count')
            )
            ->groupBy('suppliers.id', 'suppliers.commercial_name')
            ->orderByDesc('total')
            ->limit(5)
            ->get();
    }

    private function getTopProducts($startDate, $endDate)
    {
        // Reusing logic from AcquisitionController but simpler summary
        return \Illuminate\Support\Facades\DB::table('quotation_response_items')
            ->join('quotation_responses', 'quotation_response_items.quotation_response_id', '=', 'quotation_responses.id')
            ->join('acquisitions', 'quotation_responses.id', '=', 'acquisitions.quotation_response_id')
            ->join('quotation_items', 'quotation_response_items.quotation_item_id', '=', 'quotation_items.id')
            ->leftJoin('products', 'quotation_items.product_id', '=', 'products.id')
            ->whereBetween('acquisitions.created_at', [$startDate, $endDate])
            ->select(
                \Illuminate\Support\Facades\DB::raw('COALESCE(products.name, quotation_items.name) as name'),
                \Illuminate\Support\Facades\DB::raw('SUM(quotation_response_items.total_price) as total'),
                \Illuminate\Support\Facades\DB::raw('SUM(quotation_items.quantity) as quantity')
            )
            ->groupBy('name')
            ->orderByDesc('total')
            ->limit(5)
            ->get();
    }
}
