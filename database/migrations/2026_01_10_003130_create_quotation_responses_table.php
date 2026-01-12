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
        Schema::create('quotation_responses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('quotation_supplier_id')->constrained()->onDelete('cascade');
            $table->foreignId('user_id')->nullable()->constrained(); // Reviewer
            $table->text('observations')->nullable();
            $table->date('delivery_date');
            $table->integer('delivery_days'); 
            $table->string('payment_terms');
            $table->timestamp('submitted_at');
            $table->enum('status', ['pending_review', 'approved', 'rejected', 'needs_revision', 'negotiating'])->default('pending_review');
            $table->text('review_notes')->nullable();
            $table->integer('revision_number')->default(1);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('quotation_responses');
    }
};
