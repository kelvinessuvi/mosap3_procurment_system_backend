<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reminder_logs', function (Blueprint $table) {
            $table->id();
            $table->string('entity_type');
            $table->unsignedBigInteger('entity_id');
            $table->string('reminder_type');
            $table->string('channel');
            $table->date('sent_date');
            $table->timestamps();

            $table->unique(
                ['entity_type', 'entity_id', 'reminder_type', 'channel', 'sent_date'],
                'reminder_logs_unique'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reminder_logs');
    }
};
