<?php

namespace App\Http\Controllers;

use App\Enums\ProcurementCategory;
use App\Services\ManagementReportService;
use App\Services\ReportPeriod;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

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
     *     path="/api/reports/management",
     *     summary="Relatório de Gestão de Pequenas Aquisições",
     *     description="Devolve o relatório estruturado conforme o documento oficial: dados gerais, resumo executivo por categoria (Bens, Serviços de Consultoria, Serviços de Não Consultoria, Obras), detalhe de processos por categoria e análise de desempenho. Devolve apenas dados — a geração de PDF, DOCX ou Excel é feita no frontend. Ao contrário de /api/reports/summary, que é global, este relatório respeita a visibilidade dos processos: um técnico vê os que iniciou e os que lhe foram atribuídos.",
     *     tags={"Relatórios"},
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(name="period", in="query", description="Tipo de período", @OA\Schema(type="string", enum={"weekly","monthly","yearly"}, default="monthly")),
     *     @OA\Parameter(name="year", in="query", description="Ano do período (ex.: 2026)", @OA\Schema(type="integer")),
     *     @OA\Parameter(name="month", in="query", description="Mês, com period=monthly (1-12)", @OA\Schema(type="integer")),
     *     @OA\Parameter(name="week", in="query", description="Semana ISO, com period=weekly (1-53)", @OA\Schema(type="integer")),
     *     @OA\Parameter(name="start_date", in="query", description="Intervalo livre; tem precedência sobre period", @OA\Schema(type="string", format="date")),
     *     @OA\Parameter(name="end_date", in="query", @OA\Schema(type="string", format="date")),
     *     @OA\Parameter(
     *         name="status[]", in="query", description="Um ou mais estados em simultâneo. Além dos estados do processo, aceita 'atrasado', que não é um estado guardado mas uma condição sobre a data de entrega prevista.",
     *         @OA\Schema(type="array", @OA\Items(type="string", enum={"draft","sent","in_progress","completed","cancelled","atrasado"}))
     *     ),
     *     @OA\Response(response=200, description="Relatório estruturado"),
     *     @OA\Response(response=422, description="Período inválido")
     * )
     */
    public function management(Request $request, ManagementReportService $service)
    {
        $validated = $request->validate([
            'period' => ['nullable', Rule::in(['weekly', 'monthly', 'yearly'])],
            'year' => 'nullable|integer|min:2000|max:2100',
            'month' => 'nullable|integer|min:1|max:12',
            'week' => 'nullable|integer|min:1|max:53',
            'start_date' => 'nullable|date',
            'end_date' => 'nullable|date',
            'status' => 'nullable|array',
            'status.*' => Rule::in(['draft', 'sent', 'in_progress', 'completed', 'cancelled', 'atrasado']),
        ]);

        $period = ReportPeriod::fromRequest($validated);

        return response()->json(
            $service->build($period, $request->user(), $validated['status'] ?? [])
        );
    }

    /**
     * @OA\Get(
     *     path="/api/reports/categories",
     *     summary="Categorias de aquisição do relatório de gestão",
     *     description="Lista as categorias com rótulo e descrição, para preencher o selector na criação de um processo.",
     *     tags={"Relatórios"},
     *     security={{"bearerAuth":{}}},
     *     @OA\Response(response=200, description="Lista de categorias")
     * )
     */
    public function categories()
    {
        return response()->json(
            collect(ProcurementCategory::cases())->map(fn ($c) => [
                'value' => $c->value,
                'label' => $c->label(),
                'description' => $c->description(),
            ])
        );
    }

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

        return response()->json([
            'period' => [
                'start' => $startDate,
                'end' => $endDate,
                'type' => $period
            ],
            'metrics' => $metrics,
            'charts' => $charts,
            'top_suppliers' => $topSuppliers,
        ]);
    }

    private function getMetrics($startDate, $endDate)
    {
        $acquisitions = \App\Models\Acquisition::whereBetween('created_at', [$startDate, $endDate]);

        $totalAcquisitions = $acquisitions->whereIn('status', ['completed', 'in_progress'])->count();
        $completedCount    = \App\Models\Acquisition::whereBetween('created_at', [$startDate, $endDate])->where('status', 'completed')->count();
        $pendingCount      = \App\Models\Acquisition::whereBetween('created_at', [$startDate, $endDate])->where('status', 'pending')->count();

        $totalQuotations = \App\Models\QuotationRequest::whereBetween('created_at', [$startDate, $endDate])->count();
        $sentQuotations  = \App\Models\QuotationRequest::whereBetween('created_at', [$startDate, $endDate])->where('status', 'sent')->count();
        $totalSuppliers  = \App\Models\Supplier::whereBetween('created_at', [$startDate, $endDate])->count();

        return [
            'total_acquisitions' => $totalAcquisitions,
            'completed_count'    => $completedCount,
            'pending_count'      => $pendingCount,
            'total_quotations'   => $totalQuotations,
            'sent_quotations'    => $sentQuotations,
            'total_suppliers'    => $totalSuppliers,
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
                'suppliers.company_name as name',
                \Illuminate\Support\Facades\DB::raw('COUNT(acquisitions.id) as total_acquisitions')
            )
            ->groupBy('suppliers.id', 'suppliers.company_name')
            ->orderByDesc('total_acquisitions')
            ->limit(5)
            ->get();
    }
}
