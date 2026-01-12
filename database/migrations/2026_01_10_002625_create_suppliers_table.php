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
        Schema::create('suppliers', function (Blueprint $table) {
            $table->id();
            $table->string('legal_name');
            $table->string('commercial_name');
            $table->string('email')->unique();
            $table->string('phone');
            $table->string('nif')->unique();
            $table->enum('activity_type', ['service', 'commerce']);
            $table->string('province');
            $table->string('municipality');
            $table->text('address')->nullable();
            
            // Documents
            $table->string('commercial_certificate')->nullable();
            $table->string('commercial_license')->nullable();
            $table->string('nif_proof')->nullable();
            
            $table->boolean('is_active')->default(true);
            $table->foreignId('user_id')->constrained(); // Creator
            $table->timestamps();
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('suppliers');
    }
};
