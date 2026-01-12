<?php

namespace Tests\Feature;

use App\Models\Category;
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

    public function test_can_list_suppliers()
    {
        $user = User::factory()->create();
        Supplier::factory()->count(3)->create(['user_id' => $user->id]);

        $response = $this->actingAs($user, 'sanctum')->getJson('/api/suppliers');

        $response->assertStatus(200)
                 ->assertJsonCount(3, 'data');
    }

    public function test_can_create_supplier_with_documents()
    {
        Storage::fake('local');
        $user = User::factory()->create();
        $category = Category::factory()->create();

        $data = [
            'legal_name' => 'Test Company',
            'commercial_name' => 'Test Inc',
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
        $user = User::factory()->create();
        $supplier = Supplier::factory()->create(['user_id' => $user->id]);

        $response = $this->actingAs($user, 'sanctum')->putJson("/api/suppliers/{$supplier->id}", [
            'legal_name' => 'Updated Name',
            'commercial_name' => $supplier->commercial_name, // keep original
            'email' => $supplier->email, // keep original
            'phone' => $supplier->phone, // keep original
            'nif' => $supplier->nif,
        ]); // Validation rules might require re-sending some fields if not strictly patch

        // Actually the controller validation for update has 'sometimes' or 'nullable' logic?
        // Let's check controller again. It validates what is present but email/nif unique ignore.
        // It validates basic fields as 'string|max:255' but not 'required' on update (no 'required' rule).
        // Wait, line 110: 'legal_name' => 'string|max:255'. It's not required.
        
        $response->assertStatus(200)
                 ->assertJson(['legal_name' => 'Updated Name']);
    }

    public function test_can_delete_supplier()
    {
        $user = User::factory()->create();
        $supplier = Supplier::factory()->create(['user_id' => $user->id]);

        $response = $this->actingAs($user, 'sanctum')->deleteJson("/api/suppliers/{$supplier->id}");

        $response->assertStatus(204);
        $this->assertSoftDeleted($supplier);
    }
}
