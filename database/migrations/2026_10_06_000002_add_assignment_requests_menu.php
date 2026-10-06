<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * O MenuSeeder usa Menu::create num ciclo e só corre em instalações novas, por
 * isso acrescentar lá a entrada não chega: as bases já existentes ficariam sem o
 * menu e nenhum administrador conseguiria chegar aos pedidos de atribuição.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('menus')->updateOrInsert(
            ['slug' => 'assignment-requests'],
            [
                'name' => 'Pedidos de Atribuição',
                'icon' => 'group_add',
                'order' => 13,
                'is_active' => true,
                'updated_at' => now(),
                'created_at' => now(),
            ]
        );
    }

    public function down(): void
    {
        DB::table('menus')->where('slug', 'assignment-requests')->delete();
    }
};
