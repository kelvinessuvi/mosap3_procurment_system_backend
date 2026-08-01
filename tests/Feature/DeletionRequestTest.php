<?php

namespace Tests\Feature;

use App\Models\Acquisition;
use App\Models\DeletionRequest;
use App\Models\QuotationRequest;
use App\Models\QuotationResponse;
use App\Models\QuotationSupplier;
use App\Models\Supplier;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DeletionRequestTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    private function technician(): User
    {
        return User::factory()->create(['role' => 'procurement_technician']);
    }

    private function createAcquisition(): array
    {
        $user = $this->technician();
        $supplier = Supplier::factory()->create();
        $qr = QuotationRequest::factory()->create([
            'user_id' => $user->id,
            'status' => 'in_progress',
            'deadline' => Carbon::today()->addDays(5)->format('Y-m-d H:i:s'),
        ]);

        $qs = QuotationSupplier::create([
            'quotation_request_id' => $qr->id,
            'supplier_id' => $supplier->id,
            'token' => 'token-dr-' . uniqid(),
            'status' => 'submitted',
        ]);

        $response = QuotationResponse::create([
            'quotation_supplier_id' => $qs->id,
            'delivery_date' => Carbon::today()->addDays(3)->format('Y-m-d'),
            'delivery_days' => 10,
            'payment_terms' => '30 dias',
            'submitted_at' => now(),
            'status' => 'approved',
        ]);

        $acq = Acquisition::create([
            'quotation_request_id' => $qr->id,
            'quotation_response_id' => $response->id,
            'supplier_id' => $supplier->id,
            'user_id' => $user->id,
            'reference_number' => 'ACQ-TEST-' . strtoupper(uniqid()),
            'total_amount' => 2500.00,
            'justification' => 'Compra necessária para o departamento',
            'status' => 'pending',
            'expected_delivery_date' => Carbon::today()->addDays(5)->format('Y-m-d'),
        ]);

        return [$user, $acq];
    }

    public function test_approve_soft_deletes_and_masks_supplier()
    {
        $admin = $this->admin();
        $technician = $this->technician();
        $supplier = Supplier::factory()->create();

        $deletionRequest = DeletionRequest::create([
            'requestable_type' => Supplier::class,
            'requestable_id' => $supplier->id,
            'requested_by' => $technician->id,
            'reason' => 'Fornecedor inactivo',
        ]);

        $response = $this->actingAs($admin, 'sanctum')
            ->postJson("/api/deletion-requests/{$deletionRequest->id}/approve");

        $response->assertStatus(200);
        $this->assertSoftDeleted('suppliers', ['id' => $supplier->id]);
        $this->assertDatabaseHas('suppliers', [
            'id' => $supplier->id,
            'email' => "deleted-{$supplier->id}@deleted.del",
            'company_name' => "Fornecedor Eliminado {$supplier->id}",
            'nif' => "ELIMINADO-{$supplier->id}",
            'phone' => '0000000000',
            'is_active' => false,
        ]);

        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/suppliers')
            ->assertJsonMissing(['id' => $supplier->id]);
    }

    public function test_approve_soft_deletes_and_masks_acquisition()
    {
        $admin = $this->admin();
        [$technician, $acq] = $this->createAcquisition();

        $deletionRequest = DeletionRequest::create([
            'requestable_type' => Acquisition::class,
            'requestable_id' => $acq->id,
            'requested_by' => $technician->id,
            'reason' => 'Aquisição duplicada',
        ]);

        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/deletion-requests/{$deletionRequest->id}/approve")
            ->assertStatus(200);

        $this->assertSoftDeleted('acquisitions', ['id' => $acq->id]);
        $this->assertDatabaseHas('acquisitions', [
            'id' => $acq->id,
            'total_amount' => 0,
            'justification' => null,
            'reference_number' => $acq->reference_number,
        ]);
    }

    public function test_approve_soft_deletes_and_masks_quotation_request()
    {
        $admin = $this->admin();
        $technician = $this->technician();
        $qr = QuotationRequest::factory()->create([
            'user_id' => $technician->id,
            'title' => 'Fornecimento de papel A4',
            'description' => 'Descrição sensível do pedido',
            'activity_description' => 'Actividade sensível',
            'status' => 'sent',
        ]);

        $deletionRequest = DeletionRequest::create([
            'requestable_type' => QuotationRequest::class,
            'requestable_id' => $qr->id,
            'requested_by' => $technician->id,
            'reason' => 'Pedido errado',
        ]);

        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/deletion-requests/{$deletionRequest->id}/approve")
            ->assertStatus(200);

        $this->assertSoftDeleted('quotation_requests', ['id' => $qr->id]);
        $this->assertDatabaseHas('quotation_requests', [
            'id' => $qr->id,
            'title' => 'Eliminado',
            'description' => null,
            'activity_description' => null,
            'reference_number' => $qr->reference_number,
        ]);
    }

    public function test_admin_direct_delete_masks_supplier()
    {
        $admin = $this->admin();
        $supplier = Supplier::factory()->create();

        $this->actingAs($admin, 'sanctum')
            ->deleteJson("/api/suppliers/{$supplier->id}")
            ->assertStatus(204);

        $this->assertSoftDeleted('suppliers', ['id' => $supplier->id]);
        $this->assertDatabaseHas('suppliers', [
            'id' => $supplier->id,
            'email' => "deleted-{$supplier->id}@deleted.del",
            'company_name' => "Fornecedor Eliminado {$supplier->id}",
        ]);
    }

    public function test_approve_rejects_already_processed_request()
    {
        $admin = $this->admin();
        $technician = $this->technician();
        $supplier = Supplier::factory()->create();

        $deletionRequest = DeletionRequest::create([
            'requestable_type' => Supplier::class,
            'requestable_id' => $supplier->id,
            'requested_by' => $technician->id,
            'reason' => 'Duplicado',
        ]);

        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/deletion-requests/{$deletionRequest->id}/approve")
            ->assertStatus(200);

        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/deletion-requests/{$deletionRequest->id}/approve")
            ->assertStatus(400);
    }
}
