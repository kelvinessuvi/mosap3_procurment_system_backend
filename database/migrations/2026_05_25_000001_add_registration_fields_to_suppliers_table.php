<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('suppliers', function (Blueprint $table) {
            $table->string('registration_token', 64)->nullable()->unique()->after('product_list');
            $table->enum('registration_status', ['invited', 'registered'])->default('invited')->after('registration_token');
            $table->timestamp('registered_at')->nullable()->after('registration_status');
        });
    }

    public function down(): void
    {
        Schema::table('suppliers', function (Blueprint $table) {
            $table->dropColumn(['registration_token', 'registration_status', 'registered_at']);
        });
    }
};
