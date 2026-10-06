<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Campos exigidos pelo Relatório de Gestão de Pequenas Aquisições.
 *
 * `procurement_category` é obrigatória na criação (validada no controller) mas
 * fica nullable na base de dados: os processos anteriores a esta migração não
 * têm forma de ser classificados automaticamente e aparecem no relatório na
 * linha "Sem categoria" até alguém os corrigir.
 *
 * Os restantes campos só se aplicam a algumas categorias — período de execução
 * à consultoria, local à obra — e por isso são sempre opcionais.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('quotation_requests', function (Blueprint $table) {
            $table->enum('procurement_category', [
                'bens', 'consultoria', 'nao_consultoria', 'obras',
            ])->nullable()->after('activity_description');

            $table->date('execution_start_date')->nullable()->after('procurement_category');
            $table->date('execution_end_date')->nullable()->after('execution_start_date');
            $table->string('work_location')->nullable()->after('execution_end_date');

            $table->index('procurement_category');
        });
    }

    public function down(): void
    {
        Schema::table('quotation_requests', function (Blueprint $table) {
            $table->dropIndex(['procurement_category']);
            $table->dropColumn([
                'procurement_category',
                'execution_start_date',
                'execution_end_date',
                'work_location',
            ]);
        });
    }
};
