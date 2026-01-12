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
        Schema::create('supplier_evaluations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('supplier_id')->constrained()->onDelete('cascade');
            $table->integer('total_quotations')->default(0);
            $table->integer('total_responses')->default(0);
            $table->integer('total_approved')->default(0);
            $table->integer('total_rejected')->default(0);
            $table->integer('total_acquisitions')->default(0);
            $table->decimal('response_rate', 5, 2)->default(0);
            $table->decimal('success_rate', 5, 2)->default(0);
            $table->decimal('acquisition_rate', 5, 2)->default(0);
            $table->decimal('avg_response_time_hours', 10, 2)->default(0);
            $table->integer('total_revisions_requested')->default(0);
            $table->decimal('overall_score', 5, 2)->default(0);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('supplier_evaluations');
    }
};
