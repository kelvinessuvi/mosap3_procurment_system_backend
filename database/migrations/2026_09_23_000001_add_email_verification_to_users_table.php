<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Contas já existentes continuam a poder entrar: consideram-se verificadas.
        // Só as contas criadas a partir de agora exigem confirmação por código.
        DB::table('users')
            ->whereNull('email_verified_at')
            ->update(['email_verified_at' => now()]);
    }

    public function down(): void
    {
        // Sem reversão: não é possível distinguir as contas confirmadas manualmente.
    }
};
