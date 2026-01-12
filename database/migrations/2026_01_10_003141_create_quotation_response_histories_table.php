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
        Schema::create('quotation_response_histories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('quotation_response_id')->constrained()->onDelete('cascade');
            $table->integer('revision_number');
            $table->json('items_data');
            $table->decimal('total_amount', 15, 2);
            $table->string('action'); // submitted, revised, approved...
            $table->text('action_notes')->nullable();
            $table->foreignId('user_id')->nullable()->constrained(); // Action by
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('quotation_response_histories');
    }
};
