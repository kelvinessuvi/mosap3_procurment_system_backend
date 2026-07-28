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
            $table->string('company_name')->nullable()->after('id');
        });

        DB::statement('UPDATE suppliers SET company_name = commercial_name');

        Schema::table('suppliers', function (Blueprint $table) {
            $table->dropColumn(['legal_name', 'commercial_name']);
        });

        Schema::table('suppliers', function (Blueprint $table) {
            $table->string('non_debtor_certificate_agt')->nullable()->after('product_list');
            $table->string('non_debtor_certificate_inss')->nullable()->after('non_debtor_certificate_agt');
            $table->dropColumn('non_debtor_certificate');
        });
    }

    public function down(): void
    {
        Schema::table('suppliers', function (Blueprint $table) {
            $table->string('legal_name')->after('id');
            $table->string('commercial_name')->after('legal_name');
        });

        DB::statement('UPDATE suppliers SET commercial_name = company_name, legal_name = company_name');

        Schema::table('suppliers', function (Blueprint $table) {
            $table->dropColumn('company_name');
        });

        Schema::table('suppliers', function (Blueprint $table) {
            $table->string('non_debtor_certificate')->nullable()->after('product_list');
            $table->dropColumn(['non_debtor_certificate_agt', 'non_debtor_certificate_inss']);
        });
    }
};
