<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PublicRegistrationTest extends TestCase
{
    use RefreshDatabase;

    private function invitedSupplier(): Supplier
    {
        $user = User::factory()->create();
        $supplier = Supplier::factory()->create([
            'user_id' => $user->id,
            'registration_status' => 'invited',
            'is_active' => false,
            'activity_type' => 'service',
        ]);
        $supplier->categories()->attach(Category::factory()->create()->id);

        return $supplier;
    }

    private function payload(Supplier $supplier, array $overrides = []): array
    {
        return array_merge([
            'company_name' => 'Empresa Nova Lda',
            'phone' => '923000000',
            'nif' => '500000000',
            'province' => 'Luanda',
            'municipality' => 'Belas',
            'address' => 'Rua X',
            'categories' => $supplier->categories->pluck('id')->all(),
            'commercial_certificate' => UploadedFile::fake()->create('cert.pdf', 100),
            'nif_proof' => UploadedFile::fake()->create('nif.pdf', 100),
        ], $overrides);
    }

    public function test_registration_without_activity_type_stores_blank()
    {
        Storage::fake('public');
        $supplier = $this->invitedSupplier();

        $response = $this->post('/api/supplier/register/' . $supplier->registration_token, $this->payload($supplier));

        $response->assertStatus(302);
        $supplier->refresh();
        $this->assertNull($supplier->activity_type);
        $this->assertEquals('registered', $supplier->registration_status);
    }

    public function test_registration_still_accepts_activity_type_when_sent()
    {
        Storage::fake('public');
        $supplier = $this->invitedSupplier();

        $this->post('/api/supplier/register/' . $supplier->registration_token, $this->payload($supplier, [
            'activity_type' => 'commerce',
        ]))->assertStatus(302);

        $supplier->refresh();
        $this->assertEquals('commerce', $supplier->activity_type);
    }
}
