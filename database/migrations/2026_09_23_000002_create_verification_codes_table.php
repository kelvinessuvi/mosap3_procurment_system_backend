<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('verification_codes', function (Blueprint $table) {
            $table->id();
            $table->string('email')->index();
            // email_verification | password_reset
            $table->string('type', 32);
            // O código de 6 dígitos nunca é guardado em claro.
            $table->string('code_hash');
            $table->unsignedTinyInteger('attempts')->default(0);
            // useCurrent() é deliberado: sem um DEFAULT explícito, o MySQL com
            // explicit_defaults_for_timestamp=OFF (predefinição no 5.7) acrescenta
            // "ON UPDATE CURRENT_TIMESTAMP" à primeira coluna TIMESTAMP NOT NULL —
            // e isso renovaria a validade do código a cada tentativa falhada.
            $table->timestamp('expires_at')->useCurrent();
            // Token intermédio emitido depois de o código ser validado (recuperação de senha).
            $table->string('token', 64)->nullable()->unique();
            $table->timestamp('token_expires_at')->nullable();
            $table->timestamp('consumed_at')->nullable();
            $table->timestamps();

            $table->index(['email', 'type', 'consumed_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('verification_codes');
    }
};
