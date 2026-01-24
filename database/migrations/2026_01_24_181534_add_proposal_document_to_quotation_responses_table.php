<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('quotation_responses', function (Blueprint $table) {
            $table->string('proposal_document')->nullable()->after('revision_number');
            $table->string('proposal_document_original_name')->nullable()->after('proposal_document');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('quotation_responses', function (Blueprint $table) {
            $table->dropColumn(['proposal_document', 'proposal_document_original_name']);
        });
    }
};
