<?php

namespace Tests\Feature;

use App\Models\Menu;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UserPermissionTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_get_user_permissions()
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $tech = User::factory()->create(['role' => 'procurement_technician']);
        $menu = Menu::factory()->create();

        $tech->menuPermissions()->attach($menu->id, ['level' => 'read']);

        $response = $this->actingAs($admin, 'sanctum')->getJson("/api/users/{$tech->id}/permissions");

        $response->assertStatus(200)
            ->assertJsonFragment(['menu_id' => $menu->id, 'level' => 'read']);
    }

    public function test_non_admin_cannot_get_user_permissions()
    {
        $user = User::factory()->create();
        $other = User::factory()->create();

        $response = $this->actingAs($user, 'sanctum')->getJson("/api/users/{$other->id}/permissions");
        $response->assertStatus(403);
    }

    public function test_admin_can_set_user_permissions()
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $tech = User::factory()->create();
        $menu1 = Menu::factory()->create();
        $menu2 = Menu::factory()->create();

        $response = $this->actingAs($admin, 'sanctum')->putJson("/api/users/{$tech->id}/permissions", [
            'permissions' => [
                ['menu_id' => $menu1->id, 'level' => 'read'],
                ['menu_id' => $menu2->id, 'level' => 'write'],
            ],
        ]);

        $response->assertStatus(200);
        $this->assertDatabaseHas('user_menu_permissions', [
            'user_id' => $tech->id,
            'menu_id' => $menu1->id,
            'level' => 'read',
        ]);
        $this->assertDatabaseHas('user_menu_permissions', [
            'user_id' => $tech->id,
            'menu_id' => $menu2->id,
            'level' => 'write',
        ]);
    }

    public function test_setting_permissions_replaces_existing()
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $tech = User::factory()->create();
        $menu1 = Menu::factory()->create();
        $menu2 = Menu::factory()->create();

        // Add initial permission
        $tech->menuPermissions()->attach($menu1->id, ['level' => 'write']);

        // Replace with only menu2
        $this->actingAs($admin, 'sanctum')->putJson("/api/users/{$tech->id}/permissions", [
            'permissions' => [
                ['menu_id' => $menu2->id, 'level' => 'read'],
            ],
        ]);

        $this->assertDatabaseMissing('user_menu_permissions', [
            'user_id' => $tech->id,
            'menu_id' => $menu1->id,
        ]);
        $this->assertDatabaseHas('user_menu_permissions', [
            'user_id' => $tech->id,
            'menu_id' => $menu2->id,
            'level' => 'read',
        ]);
    }

    public function test_my_permissions_returns_all_for_admin()
    {
        $admin = User::factory()->create(['role' => 'admin']);
        Menu::factory()->create(['slug' => 'suppliers', 'is_active' => true]);

        $response = $this->actingAs($admin, 'sanctum')->getJson('/api/user/permissions');

        $response->assertStatus(200);
        $response->assertJsonFragment(['slug' => 'suppliers', 'permissions' => ['read', 'write']]);
    }

    public function test_my_permissions_returns_only_assigned_for_technician()
    {
        $tech = User::factory()->create(['role' => 'procurement_technician']);
        $menu = Menu::factory()->create(['slug' => 'suppliers', 'is_active' => true]);
        $menu2 = Menu::factory()->create(['slug' => 'products', 'is_active' => true]);

        $tech->menuPermissions()->attach($menu->id, ['level' => 'read']);

        $response = $this->actingAs($tech, 'sanctum')->getJson('/api/user/permissions');

        $response->assertStatus(200);
        // suppliers has read permission
        $suppliers = collect($response->json())->firstWhere('slug', 'suppliers');
        $this->assertEquals(['read'], $suppliers['permissions']);

        // products has no permission (not assigned)
        $products = collect($response->json())->firstWhere('slug', 'products');
        $this->assertEquals([], $products['permissions']);
    }

    public function test_menu_middleware_blocks_technician_without_permission()
    {
        $tech = User::factory()->create(['role' => 'procurement_technician']);
        Menu::factory()->create(['slug' => 'suppliers']);

        // No permission assigned - should be blocked
        $response = $this->actingAs($tech, 'sanctum')->getJson('/api/suppliers');
        $response->assertStatus(403);
    }

    public function test_menu_middleware_allows_technician_with_read_permission()
    {
        $tech = User::factory()->create(['role' => 'procurement_technician']);
        $menu = Menu::factory()->create(['slug' => 'suppliers']);
        $tech->menuPermissions()->attach($menu->id, ['level' => 'read']);

        $response = $this->actingAs($tech, 'sanctum')->getJson('/api/suppliers');

        // Should pass (read permission allows GET)
        $response->assertStatus(200);
    }

    public function test_menu_middleware_blocks_write_without_write_permission()
    {
        $tech = User::factory()->create(['role' => 'procurement_technician']);
        $menu = Menu::factory()->create(['slug' => 'suppliers']);
        $tech->menuPermissions()->attach($menu->id, ['level' => 'read']);

        $supplier = \App\Models\Supplier::factory()->create();

        // Create requires write - should be blocked
        $response = $this->actingAs($tech, 'sanctum')->postJson('/api/suppliers', [
            'company_name' => 'Test',
            'email' => 'test@test.com',
            'phone' => '123456789',
            'nif' => '999999999',
        ]);
        $response->assertStatus(403);
    }

    public function test_menu_middleware_allows_write_with_write_permission()
    {
        $tech = User::factory()->create(['role' => 'procurement_technician']);
        $menu = Menu::factory()->create(['slug' => 'suppliers']);
        $tech->menuPermissions()->attach($menu->id, ['level' => 'write']);

        // Create with write permission should pass (but fail validation since it's missing required fields)
        $response = $this->actingAs($tech, 'sanctum')->postJson('/api/suppliers', [
            'company_name' => 'Test Company',
            'email' => 'company@test.com',
            'phone' => '123456789',
            'nif' => '999999999',
            'activity_type' => 'service',
            'province' => 'Luanda',
            'municipality' => 'Luanda',
            'address' => 'Street 1',
        ]);

        // It should reach the controller (not blocked by middleware), but may fail validation for other reasons
        // 422 = validation error (reached controller), 403 = blocked by middleware
        $this->assertNotEquals(403, $response->status());
    }

    public function test_can_access_menu_returns_true_for_admin()
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $this->assertTrue($admin->canAccessMenu('any-slug', 'read'));
        $this->assertTrue($admin->canAccessMenu('any-slug', 'write'));
    }

    public function test_can_access_menu_returns_false_for_technician_without_permission()
    {
        $tech = User::factory()->create(['role' => 'procurement_technician']);
        $this->assertFalse($tech->canAccessMenu('any-slug', 'read'));
    }

    public function test_can_access_menu_read_with_read_permission()
    {
        $tech = User::factory()->create(['role' => 'procurement_technician']);
        $menu = Menu::factory()->create(['slug' => 'suppliers']);
        $tech->menuPermissions()->attach($menu->id, ['level' => 'read']);

        $this->assertTrue($tech->canAccessMenu('suppliers', 'read'));
        $this->assertFalse($tech->canAccessMenu('suppliers', 'write'));
    }

    public function test_can_access_menu_read_and_write_with_write_permission()
    {
        $tech = User::factory()->create(['role' => 'procurement_technician']);
        $menu = Menu::factory()->create(['slug' => 'suppliers']);
        $tech->menuPermissions()->attach($menu->id, ['level' => 'write']);

        $this->assertTrue($tech->canAccessMenu('suppliers', 'read'));
        $this->assertTrue($tech->canAccessMenu('suppliers', 'write'));
    }
}
