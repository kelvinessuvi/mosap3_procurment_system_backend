<?php

namespace App\Services;

use App\Enums\ProcurementCategory;
use App\Models\Acquisition;
use App\Models\QuotationRequest;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * Relatório de Gestão de Pequenas Aquisições.
 *
 * Produz a estrutura do documento oficial: dados gerais, resumo executivo por
 * categoria, detalhe por categoria e análise de desempenho. Devolve apenas
 * dados — a geração de PDF/DOCX/Excel é do frontend.
 */
class ManagementReportService
{
    /** Estados de aquisição que contam como processo em curso. */
    public const EM_CURSO = ['pending', 'in_progress'];

    public function build(ReportPeriod $period, ?User $user, array $statuses = []): array
    {
        $processos = $this->processes($period, $user, $statuses);

        return [
            'report' => [
                'title' => 'Relatório de Gestão de Pequenas Aquisições',
                'system' => config('app.name'),
                'issued_at' => now()->toIso8601String(),
                'period' => $period->toArray(),
                'scope' => $user && $user->role === 'admin'
                    ? 'Todos os processos'
                    : 'Processos do utilizador e processos que lhe foram atribuídos',
                'filters' => ['statuses' => array_values($statuses)],
            ],
            'summary' => $this->summary($processos),
            'categories' => $this->categories($processos),
            'performance' => $this->performance($processos, $period),
        ];
    }

    /**
     * Processos do período, já com o que o relatório precisa.
     *
     * O período aplica-se à data de criação do processo, que é o que o
     * documento chama "período de referência".
     */
    private function processes(ReportPeriod $period, ?User $user, array $statuses): Collection
    {
        $query = QuotationRequest::query()
            ->visibleTo($user)
            ->with([
                'acquisitions.supplier:id,company_name',
                'quotationSuppliers.supplier:id,company_name',
            ])
            ->whereBetween('created_at', [$period->start, $period->end])
            ->orderBy('reference_number');

        if ($statuses !== []) {
            $query->where(function ($q) use ($statuses) {
                // "atrasado" não é um estado guardado: é uma condição sobre as
                // datas de entrega, por isso tem de ser traduzido aqui.
                $guardados = array_values(array_diff($statuses, ['atrasado']));

                if ($guardados !== []) {
                    $q->whereIn('status', $guardados);
                }

                if (in_array('atrasado', $statuses, true)) {
                    $q->orWhereHas('acquisitions', fn ($a) => $this->scopeLate($a));
                }
            });
        }

        return $query->get();
    }

    /** Em atraso: entrega prevista já passou e o processo não terminou. */
    private function scopeLate($query)
    {
        return $query->whereDate('expected_delivery_date', '<', now()->toDateString())
            ->whereNotIn('status', ['completed', 'cancelled']);
    }

    private function summary(Collection $processos): array
    {
        $porCategoria = [];

        foreach (ProcurementCategory::cases() as $categoria) {
            $porCategoria[] = $this->summaryRow(
                $categoria->value,
                $categoria->label(),
                $processos->filter(fn ($p) => $p->procurement_category === $categoria)
            );
        }

        // Processos anteriores à introdução da categoria. A linha só aparece
        // enquanto existirem, para não sugerir uma categoria que não existe.
        $semCategoria = $processos->filter(fn ($p) => $p->procurement_category === null);
        if ($semCategoria->isNotEmpty()) {
            $porCategoria[] = $this->summaryRow(null, 'Sem categoria', $semCategoria);
        }

        $total = $this->summaryRow(null, 'TOTAL GLOBAL', $processos);

        return [
            'total_processes' => $processos->count(),
            'total_amount' => $total['total_amount'],
            'currency' => 'AOA',
            'by_category' => $porCategoria,
            'total' => $total,
        ];
    }

    private function summaryRow(?string $categoria, string $label, Collection $processos): array
    {
        $aquisicoes = $processos->flatMap->acquisitions;

        return [
            'category' => $categoria,
            'label' => $label,
            'processes' => $processos->count(),
            'completed' => $processos->where('status', 'completed')->count(),
            'in_progress' => $processos->whereIn('status', ['sent', 'in_progress'])->count(),
            'late' => $processos->filter(fn ($p) => $this->isLate($p))->count(),
            'total_amount' => round($aquisicoes->sum('total_amount'), 2),
        ];
    }

    private function categories(Collection $processos): array
    {
        $saida = [];

        foreach (ProcurementCategory::cases() as $categoria) {
            $doGrupo = $processos->filter(fn ($p) => $p->procurement_category === $categoria);

            $saida[$categoria->value] = [
                'label' => $categoria->label(),
                'description' => $categoria->description(),
                'columns' => $categoria->detailColumns(),
                'rows' => $doGrupo->values()->map(fn ($p) => $this->detailRow($p, $categoria))->all(),
                'total_amount' => round($doGrupo->flatMap->acquisitions->sum('total_amount'), 2),
            ];
        }

        $semCategoria = $processos->filter(fn ($p) => $p->procurement_category === null);
        if ($semCategoria->isNotEmpty()) {
            $saida['sem_categoria'] = [
                'label' => 'Sem categoria',
                'description' => 'Processos criados antes de a classificação por categoria existir. Classifique-os para que entrem nas estatísticas por categoria.',
                'columns' => ProcurementCategory::Bens->detailColumns(),
                'rows' => $semCategoria->values()->map(fn ($p) => $this->detailRow($p, null))->all(),
                'total_amount' => round($semCategoria->flatMap->acquisitions->sum('total_amount'), 2),
            ];
        }

        return $saida;
    }

    private function detailRow(QuotationRequest $processo, ?ProcurementCategory $categoria): array
    {
        $aquisicao = $processo->acquisitions->sortByDesc('id')->first();

        // Adjudicatário: o fornecedor da aquisição. Enquanto não houver
        // adjudicação, mostra-se quantos foram convidados.
        $fornecedor = $aquisicao?->supplier?->company_name;
        if (! $fornecedor) {
            $convidados = $processo->quotationSuppliers->count();
            $fornecedor = $convidados > 0 ? "Em cotação ({$convidados} convidados)" : null;
        }

        return [
            'id' => $processo->id,
            'code' => $this->processCode($processo, $categoria),
            'reference_number' => $processo->reference_number,
            'description' => $processo->activity_description ?: $processo->title,
            'supplier' => $fornecedor,
            // A aquisição é criada no momento em que a proposta é aprovada,
            // por isso a sua data de criação é a data de aprovação.
            'approved_at' => $aquisicao?->created_at?->toDateString(),
            'execution_period' => $this->executionPeriod($processo),
            'work_location' => $processo->work_location,
            'total_amount' => $aquisicao ? round((float) $aquisicao->total_amount, 2) : null,
            'status' => $processo->status,
            'status_label' => $this->statusLabel($processo),
            'is_late' => $this->isLate($processo),
        ];
    }

    /** Código no formato do documento (PROC-BENS-001), estável por processo. */
    private function processCode(QuotationRequest $processo, ?ProcurementCategory $categoria): string
    {
        if (! $categoria) {
            return $processo->reference_number;
        }

        return $categoria->codePrefix().'-'.str_pad((string) $processo->id, 3, '0', STR_PAD_LEFT);
    }

    private function executionPeriod(QuotationRequest $processo): ?string
    {
        if (! $processo->execution_start_date && ! $processo->execution_end_date) {
            return null;
        }

        return trim(sprintf(
            '%s - %s',
            $processo->execution_start_date?->format('d/m/Y') ?? '?',
            $processo->execution_end_date?->format('d/m/Y') ?? '?'
        ));
    }

    private function isLate(QuotationRequest $processo): bool
    {
        return $processo->acquisitions->contains(
            fn (Acquisition $a) => $a->expected_delivery_date
                && $a->expected_delivery_date->isBefore(now()->startOfDay())
                && ! in_array($a->status, ['completed', 'cancelled'], true)
        );
    }

    private function statusLabel(QuotationRequest $processo): string
    {
        if ($this->isLate($processo)) {
            return 'Em Atraso';
        }

        return match ($processo->status) {
            'draft' => 'Rascunho',
            'sent' => 'Em Cotação',
            'in_progress' => 'Em Execução',
            'completed' => 'Concluído',
            'cancelled' => 'Cancelado',
            default => $processo->status,
        };
    }

    /**
     * Secção 4 do documento. As partes calculáveis são a aderência aos prazos e
     * a rastreabilidade; as "ocorrências" são texto do técnico, pelo que aqui
     * se devolvem apenas os números que as justificam.
     */
    private function performance(Collection $processos, ReportPeriod $period): array
    {
        $aquisicoes = $processos->flatMap->acquisitions;

        $diasAteAprovacao = $processos
            ->map(function ($p) {
                $primeira = $p->acquisitions->sortBy('id')->first();

                return $primeira ? $p->created_at->diffInDays($primeira->created_at) : null;
            })
            ->filter(fn ($d) => $d !== null);

        $atrasoEntrega = $aquisicoes
            ->filter(fn ($a) => $a->actual_delivery_date && $a->expected_delivery_date)
            ->map(fn ($a) => $a->expected_delivery_date->diffInDays($a->actual_delivery_date, false));

        $comAnexos = $processos->filter(fn ($p) => ! empty($p->attachments))->count();

        return [
            'deadlines' => [
                'avg_days_to_approval' => $diasAteAprovacao->isEmpty()
                    ? null
                    : round($diasAteAprovacao->avg(), 1),
                'avg_delivery_deviation_days' => $atrasoEntrega->isEmpty()
                    ? null
                    : round($atrasoEntrega->avg(), 1),
                'delivered_on_time' => $atrasoEntrega->filter(fn ($d) => $d <= 0)->count(),
                'delivered_late' => $atrasoEntrega->filter(fn ($d) => $d > 0)->count(),
            ],
            'attention_points' => [
                'late_processes' => $processos->filter(fn ($p) => $this->isLate($p))->count(),
                'cancelled_processes' => $processos->where('status', 'cancelled')->count(),
                'unclassified_processes' => $processos->filter(fn ($p) => $p->procurement_category === null)->count(),
                'without_award' => $processos->filter(fn ($p) => $p->acquisitions->isEmpty())->count(),
            ],
            'traceability' => [
                'processes_with_attachments' => $comAnexos,
                'processes_total' => $processos->count(),
                'attachment_coverage_pct' => $processos->isEmpty()
                    ? null
                    : round($comAnexos / $processos->count() * 100, 1),
            ],
        ];
    }
}
