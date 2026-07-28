<?php

namespace Database\Seeders;

use App\Models\Menu;
use Illuminate\Database\Seeder;

class MenuSeeder extends Seeder
{
    public function run(): void
    {
        $menus = [
            ['name' => 'Dashboard',          'slug' => 'dashboard',           'icon' => 'dashboard',   'order' => 1],
            ['name' => 'Fornecedores',       'slug' => 'suppliers',           'icon' => 'people',      'order' => 2],
            ['name' => 'Pedidos de Cotação', 'slug' => 'quotation-requests',  'icon' => 'description', 'order' => 3],
            ['name' => 'Aquisições',         'slug' => 'acquisitions',        'icon' => 'shopping_cart','order' => 4],
            ['name' => 'Produtos',           'slug' => 'products',            'icon' => 'inventory',   'order' => 5],
            ['name' => 'Categorias',         'slug' => 'categories',          'icon' => 'category',    'order' => 6],
            ['name' => 'Utilizadores',       'slug' => 'users',               'icon' => 'person',      'order' => 7],
            ['name' => 'Avaliações',         'slug' => 'supplier-evaluations', 'icon' => 'star',       'order' => 8],
            ['name' => 'Documentos',         'slug' => 'documents',           'icon' => 'folder',      'order' => 9],
            ['name' => 'Notificações',       'slug' => 'notifications',       'icon' => 'notifications','order' => 10],
            ['name' => 'Registos de Auditoria', 'slug' => 'audit-logs',      'icon' => 'history',     'order' => 11],
            ['name' => 'Pedidos de Exclusão','slug' => 'deletion-requests',   'icon' => 'delete',      'order' => 12],
        ];

        foreach ($menus as $menu) {
            Menu::create($menu);
        }
    }
}
