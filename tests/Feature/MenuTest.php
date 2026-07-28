<?php

namespace Tests\Feature;

use App\Models\Menu;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MenuTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_list_menus()
    {
        $admin = User::factory()->create(['role' => 'admin']);
        Menu::factory()->count(3)->create();

        $response = $this->actingAs($admin, 'sanctum')->getJson('/api/menus');

        $response->assertStatus(200);
    }

    public function test_non_admin_cannot_list_menus()
    {
        $user = User::factory()->create();
        $response = $this->actingAs($user, 'sanctum')->getJson('/api/menus');
        $response->assertStatus(403);
    }

    public function test_admin_can_create_menu()
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $response = $this->actingAs($admin, 'sanctum')->postJson('/api/menus', [
            'name' => 'Reports',
            'slug' => 'reports',
        ]);

        $response->assertStatus(201);
        $this->assertDatabaseHas('menus', ['slug' => 'reports']);
    }

    public function test_admin_can_update_menu()
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $menu = Menu::factory()->create();

        $response = $this->actingAs($admin, 'sanctum')->putJson("/api/menus/{$menu->id}", [
            'name' => 'Updated Menu',
        ]);

        $response->assertStatus(200);
        $this->assertDatabaseHas('menus', ['id' => $menu->id, 'name' => 'Updated Menu']);
    }

    public function test_admin_can_delete_menu()
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $menu = Menu::factory()->create();

        $response = $this->actingAs($admin, 'sanctum')->deleteJson("/api/menus/{$menu->id}");

        $response->assertStatus(204);
        $this->assertDatabaseMissing('menus', ['id' => $menu->id]);
    }
}
