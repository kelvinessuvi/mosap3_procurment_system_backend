<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Os termos de pagamento deixaram de ser pedidos ao fornecedor. A coluna
 * mantém-se para preservar o histórico das propostas já submetidas, mas passa
 * a aceitar null nas novas.
 *
 * Feito com a técnica coluna temporária + drop + rename, para não depender do
 * doctrine/dbal (que ->change() exigiria e não está instalado).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('quotation_responses', function (Blueprint $table) {
            $table->string('payment_terms_tmp')->nullable();
        });

        DB::statement('UPDATE quotation_responses SET payment_terms_tmp = payment_terms');

        Schema::table('quotation_responses', function (Blueprint $table) {
            $table->dropColumn('payment_terms');
        });

        Schema::table('quotation_responses', function (Blueprint $table) {
            $table->renameColumn('payment_terms_tmp', 'payment_terms');
        });
    }

    public function down(): void
    {
        // Sem valor não há como repor um NOT NULL: as propostas novas ficam com ''.
        DB::statement("UPDATE quotation_responses SET payment_terms = '' WHERE payment_terms IS NULL");

        Schema::table('quotation_responses', function (Blueprint $table) {
            $table->string('payment_terms_tmp')->nullable(false)->default('');
        });

        DB::statement('UPDATE quotation_responses SET payment_terms_tmp = payment_terms');

        Schema::table('quotation_responses', function (Blueprint $table) {
            $table->dropColumn('payment_terms');
        });

        Schema::table('quotation_responses', function (Blueprint $table) {
            $table->renameColumn('payment_terms_tmp', 'payment_terms');
        });
    }
};
