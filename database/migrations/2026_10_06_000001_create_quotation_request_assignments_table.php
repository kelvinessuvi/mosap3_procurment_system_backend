<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Atribuição de um processo de aquisição a um técnico.
 *
 * Uma linha representa o par (processo, técnico) e o seu estado. A visibilidade
 * são as linhas 'active'. O criador do processo NÃO tem linha: continua a ser
 * identificado por quotation_requests.user_id, para não haver duas fontes de
 * verdade sobre quem o iniciou.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('quotation_request_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('quotation_request_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained();              // o técnico atribuído

            // pending  -> pedido do criador, à espera de aprovação (NÃO dá acesso)
            // active   -> atribuição em vigor (dá acesso)
            // rejected -> pedido recusado pelo admin
            // revoked  -> atribuição retirada
            $table->enum('status', ['pending', 'active', 'rejected', 'revoked'])->default('pending');

            $table->foreignId('requested_by')->constrained('users');  // quem pediu ou atribuiu
            $table->text('reason')->nullable();

            $table->foreignId('reviewed_by')->nullable()->constrained('users');
            $table->timestamp('reviewed_at')->nullable();
            $table->text('rejection_reason')->nullable();

            $table->foreignId('revoked_by')->nullable()->constrained('users');
            $table->timestamp('revoked_at')->nullable();

            // Espelha o status enquanto a linha está viva ('pending'/'active') e fica a
            // NULL quando passa a histórico. Como NULL != NULL em índices UNIQUE (tanto
            // em MySQL como em SQLite), o índice abaixo garante no máximo um pendente e
            // uma atribuição activa por par, deixando o histórico crescer à vontade.
            // É mantido pelo modelo e não por coluna gerada, porque STORED GENERATED do
            // MySQL não é portável para o SQLite usado nos testes.
            $table->string('open_state', 10)->nullable();

            $table->timestamps();

            $table->unique(['quotation_request_id', 'user_id', 'open_state'], 'qra_open_unique');
            $table->index(['user_id', 'status', 'quotation_request_id'], 'qra_visibility_idx');
            $table->index(['status', 'created_at'], 'qra_review_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('quotation_request_assignments');
    }
};
