<?php

namespace Tests\Feature;

use App\Models\QuotationRequest;
use App\Models\Supplier;
use App\Models\User;
use App\Models\QuotationSupplier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\WithFaker;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class QuotationLifecycleTest extends TestCase
{
    use RefreshDatabase;

    public function test_quotation_lifecycle_flow()
    {
        Mail::fake();

        // 1. Admin creates a Quotation Request
        $admin = User::factory()->create([
            'is_active' => true,
            'role' => 'admin'
        ]);
        $suppliers = Supplier::factory()->count(2)->create();

        $quotationData = [
            'title' => 'Office Setup',
            'description' => 'Need desk and chairs',
            'deadline' => now()->addDays(7)->toIso8601String(),
            'items' => [
                [
                    'name' => 'Desk',
                    'quantity' => 10,
                    'unit' => 'pcs',
                    'specifications' => 'Wooden',
                ]
            ],
            'suppliers' => $suppliers->pluck('id')->toArray(),
        ];

        $response = $this->actingAs($admin, 'sanctum')->postJson('/api/quotation-requests', $quotationData);
        $response->assertStatus(201);
        
        $quotationId = $response->json('id');
        $quotation = QuotationRequest::find($quotationId);
        $this->assertEquals('draft', $quotation->status);
        $this->assertCount(2, $quotation->suppliers);

        // 2. Admin sends the Quotation Request
        $sendResponse = $this->actingAs($admin, 'sanctum')->postJson("/api/quotation-requests/{$quotationId}/send");
        $sendResponse->assertStatus(200);
        
        $quotation->refresh();
        $this->assertEquals('sent', $quotation->status);

        // 3. Supplier 1 Views the Quotation (Public Link)
        $pivot = QuotationSupplier::where('quotation_request_id', $quotationId)
                    ->where('supplier_id', $suppliers[0]->id)
                    ->first();
        
        $token = $pivot->token;
        
        $viewResponse = $this->getJson("/api/quotation/{$token}");
        $viewResponse->assertStatus(200)
                     ->assertJsonStructure(['quotation_supplier', 'existing_response']);
        
        $pivot->refresh();
        $this->assertEquals('opened', $pivot->status);

        // 4. Supplier 1 Submits a Proposal
        $quotationItem = $quotation->items->first();
        
        $proposalData = [
            'deliveryDate' => now()->addDays(5)->toIso8601String(),
            'deliveryDays' => 5,
            'paymentTerms' => '50% upfront',
            'items' => [
                [
                    'quotation_item_id' => $quotationItem->id,
                    'unit_price' => 50000,
                    'notes' => 'Best wood',
                ]
            ]
        ];

        $submitResponse = $this->postJson("/api/quotation/{$token}/submit", $proposalData);
        $submitResponse->assertStatus(201);

        $pivot->refresh();
        $this->assertEquals('submitted', $pivot->status);

        // Check if Quotation Request status changed to 'in_progress'
        $quotation->refresh();
        $this->assertEquals('in_progress', $quotation->status);

        // 5. Supplier 2 Declines
        $pivot2 = QuotationSupplier::where('quotation_request_id', $quotationId)
                    ->where('supplier_id', $suppliers[1]->id)
                    ->first();
        
        $declineResponse = $this->postJson("/api/quotation/{$pivot2->token}/decline");
        $declineResponse->assertStatus(200);
        
        $pivot2->refresh();
        $this->assertEquals('declined', $pivot2->status);

        // 6. Admin Reviews Responses
        $responses = $this->actingAs($admin, 'sanctum')->getJson('/api/quotation-responses?quotation_request_id=' . $quotationId);
        $responses->assertStatus(200)
                  ->assertJsonCount(1, 'data'); // We have 1 submitted response
        
        $submittedResponseId = $submitResponse->json('id');
        
        // 7. Admin Approves Proposal from Supplier 1
        $approveResponse = $this->actingAs($admin, 'sanctum')->postJson("/api/quotation-responses/{$submittedResponseId}/approve", [
            'notes' => 'Best price and quality',
        ]);
        $approveResponse->assertStatus(200)
                        ->assertJson(['status' => 'approved']);

        // 8. Admin Creates Acquisition
        $acquisitionResponse = $this->actingAs($admin, 'sanctum')->postJson("/api/quotation-responses/{$submittedResponseId}/create-acquisition", [
            'expected_delivery_date' => now()->addDays(10)->toIso8601String(),
            'justification' => 'Urgent need',
        ]);
        $acquisitionResponse->assertStatus(201);
        
        // Verify Request is Completed
        $quotation->refresh();
        $this->assertEquals('completed', $quotation->status);
    }
}
