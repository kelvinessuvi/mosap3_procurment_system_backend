<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('suppliers', function (Blueprint $table) {
            $table->enum('activity_type_tmp', ['service', 'commerce'])->nullable();
        });

        DB::statement('UPDATE suppliers SET activity_type_tmp = activity_type');

        Schema::table('suppliers', function (Blueprint $table) {
            $table->dropColumn('activity_type');
        });

        Schema::table('suppliers', function (Blueprint $table) {
            $table->renameColumn('activity_type_tmp', 'activity_type');
        });
    }

    public function down(): void
    {
        Schema::table('suppliers', function (Blueprint $table) {
            $table->enum('activity_type_tmp', ['service', 'commerce'])->nullable(false);
        });

        DB::statement('UPDATE suppliers SET activity_type_tmp = activity_type');

        Schema::table('suppliers', function (Blueprint $table) {
            $table->dropColumn('activity_type');
        });

        Schema::table('suppliers', function (Blueprint $table) {
            $table->renameColumn('activity_type_tmp', 'activity_type');
        });
    }
};
