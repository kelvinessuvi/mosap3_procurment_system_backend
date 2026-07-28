<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\DeletionRequest;
use App\Models\Menu;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\WithFaker;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class SupplierTest extends TestCase
{
    use RefreshDatabase;

    private function createMenu(): Menu
    {
        return Menu::factory()->create(['slug' => 'suppliers']);
    }

    private function createUserWithSupplierRead(): User
    {
        $user = User::factory()->create();
        $menu = $this->createMenu();
        $user->menuPermissions()->attach($menu->id, ['level' => 'read']);
        return $user;
    }

    private function createUserWithSupplierWrite(): User
    {
        $user = User::factory()->create();
        $menu = $this->createMenu();
        $user->menuPermissions()->attach($menu->id, ['level' => 'write']);
        return $user;
    }

    public function test_can_list_suppliers()
    {
        $user = $this->createUserWithSupplierRead();
        Supplier::factory()->count(3)->create(['user_id' => $user->id]);

        $response = $this->actingAs($user, 'sanctum')->getJson('/api/suppliers');

        $response->assertStatus(200)
                 ->assertJsonCount(3, 'data');
    }

    public function test_can_create_supplier_with_documents()
    {
        Storage::fake('local');
        $user = $this->createUserWithSupplierWrite();
        $category = Category::factory()->create();

        $data = [
            'company_name' => 'Test Company',
            'email' => 'company@test.com',
            'phone' => '123456789',
            'nif' => '999999999',
            'activity_type' => 'service',
            'province' => 'Luanda',
            'municipality' => 'Luanda',
            'address' => 'Street 1',
            'categories' => [$category->id],
            'commercial_certificate' => UploadedFile::fake()->create('cert.pdf', 100),
            'nif_proof' => UploadedFile::fake()->create('nif.pdf', 100),
        ];

        $response = $this->actingAs($user, 'sanctum')->postJson('/api/suppliers', $data);

        $response->assertStatus(201);
        $this->assertDatabaseHas('suppliers', ['email' => 'company@test.com']);
    }

    public function test_can_update_supplier()
    {
        $user = $this->createUserWithSupplierWrite();
        $supplier = Supplier::factory()->create(['user_id' => $user->id]);

        $response = $this->actingAs($user, 'sanctum')->putJson("/api/suppliers/{$supplier->id}", [
            'company_name' => 'Updated Name',
            'email' => $supplier->email, // keep original
            'phone' => $supplier->phone, // keep original
            'nif' => $supplier->nif,
        ]);

        
        $response->assertStatus(200)
                 ->assertJson(['company_name' => 'Updated Name']);
    }

    public function test_admin_can_delete_supplier_directly()
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $supplier = Supplier::factory()->create(['user_id' => $admin->id]);

        $response = $this->actingAs($admin, 'sanctum')->deleteJson("/api/suppliers/{$supplier->id}");

        $response->assertStatus(204);
        $this->assertSoftDeleted($supplier);
    }

    public function test_non_admin_creates_deletion_request()
    {
        $user = $this->createUserWithSupplierWrite();
        $admin = User::factory()->create(['role' => 'admin']);
        $supplier = Supplier::factory()->create(['user_id' => $user->id]);

        $response = $this->actingAs($user, 'sanctum')->deleteJson("/api/suppliers/{$supplier->id}", [
            'reason' => 'Fornecedor não cumpre requisitos',
        ]);

        $response->assertStatus(201);
        $this->assertDatabaseHas('deletion_requests', [
            'requestable_type' => Supplier::class,
            'requestable_id' => $supplier->id,
            'requested_by' => $user->id,
            'status' => 'pending',
            'reason' => 'Fornecedor não cumpre requisitos',
        ]);
        // Supplier should still exist (soft delete not applied)
        $this->assertDatabaseHas('suppliers', ['id' => $supplier->id]);
    }
}
