<?php

namespace App\Enums;

/**
 * Categorias de pequenas aquisições, conforme o Relatório de Gestão de Pequenas
 * Aquisições. São a espinha dorsal do relatório: as secções de resumo e de
 * detalhe são inteiramente organizadas por elas.
 */
enum ProcurementCategory: string
{
    case Bens = 'bens';
    case Consultoria = 'consultoria';
    case NaoConsultoria = 'nao_consultoria';
    case Obras = 'obras';

    public function label(): string
    {
        return match ($this) {
            self::Bens => 'Bens',
            self::Consultoria => 'Serviços de Consultoria',
            self::NaoConsultoria => 'Serviços de Não Consultoria',
            self::Obras => 'Obras',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Bens => 'Abrange o fornecimento de equipamentos, materiais de escritório, insumos agrícolas, mobiliário e outros bens de consumo ou capital.',
            self::Consultoria => 'Abrange a contratação de especialistas individuais ou firmas para serviços intelectuais e de aconselhamento especializado.',
            self::NaoConsultoria => 'Abrange serviços físicos e operacionais, tais como manutenção de veículos/equipamentos, transporte e logística, organização de eventos, limpezas e segurança.',
            self::Obras => 'Abrange pequenas intervenções de engenharia civil, reparações estruturais, reabilitação de infraestruturas ou pequenas construções.',
        };
    }

    /**
     * Prefixo do código de processo usado no relatório (PROC-BENS-001, ...).
     */
    public function codePrefix(): string
    {
        return match ($this) {
            self::Bens => 'PROC-BENS',
            self::Consultoria => 'PROC-CONS',
            self::NaoConsultoria => 'PROC-NCONS',
            self::Obras => 'PROC-OBR',
        };
    }

    /**
     * Cabeçalhos da tabela de detalhe. Cada categoria tem colunas próprias no
     * documento: a consultoria mostra o período de execução, as obras o local.
     */
    public function detailColumns(): array
    {
        $comuns = [
            'code' => 'Código do Processo',
            'description' => 'Descrição',
            'supplier' => 'Fornecedor / Adjudicatário',
        ];

        $especificas = match ($this) {
            self::Bens => ['approved_at' => 'Data de Aprovação'],
            self::Consultoria => ['execution_period' => 'Período de Execução'],
            self::NaoConsultoria => ['approved_at' => 'Data do Contrato / Ordem'],
            self::Obras => ['work_location' => 'Local da Obra'],
        };

        return $comuns + $especificas + [
            'total_amount' => 'Valor Total',
            'status' => 'Estado',
        ];
    }

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    public static function tryFromLabel(?string $value): ?self
    {
        return $value ? self::tryFrom($value) : null;
    }
}
