<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('quotation_requests', function (Blueprint $table) {
            $table->text('activity_description')->nullable()->after('description');
        });
    }

    public function down(): void
    {
        Schema::table('quotation_requests', function (Blueprint $table) {
            $table->dropColumn('activity_description');
        });
    }
};
