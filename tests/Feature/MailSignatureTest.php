<?php

namespace Tests\Feature;

use App\Mail\NegotiationNotificationMail;
use App\Mail\ProposalApprovedMail;
use App\Mail\ProposalRejectedMail;
use App\Mail\SupplierApprovedMail;
use App\Mail\SupplierInvitationMail;
use App\Models\Acquisition;
use App\Models\QuotationRequest;
use App\Models\QuotationResponse;
use App\Models\QuotationSupplier;
use App\Models\Supplier;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class MailSignatureTest extends TestCase
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

    private function createResponse(array $overrides = []): array
    {
        $technician = $this->technician();
        $supplier = Supplier::factory()->create();
        $qr = QuotationRequest::factory()->create([
            'user_id' => $technician->id,
            'status' => 'in_progress',
            'deadline' => Carbon::today()->addDays(5)->format('Y-m-d H:i:s'),
        ]);

        $qs = QuotationSupplier::create([
            'quotation_request_id' => $qr->id,
            'supplier_id' => $supplier->id,
            'token' => 'token-mail-' . uniqid(),
            'status' => 'submitted',
        ]);

        $response = QuotationResponse::create(array_merge([
            'quotation_supplier_id' => $qs->id,
            'delivery_date' => Carbon::today()->addDays(3)->format('Y-m-d'),
            'delivery_days' => 10,
            'payment_terms' => '30 dias',
            'submitted_at' => now(),
            'status' => 'pending_review',
        ], $overrides));

        return [$supplier, $qr, $response];
    }

    public function test_supplier_invitation_email_uses_logged_in_user_in_signature()
    {
        Mail::fake();
        $admin = $this->admin();

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/suppliers/invite', ['email' => 'novo@fornecedor.ao'])
            ->assertStatus(201);

        Mail::assertSent(SupplierInvitationMail::class, function ($mail) use ($admin) {
            return $mail->senderUser !== null && $mail->senderUser->id === $admin->id;
        });
    }

    public function test_supplier_approved_email_uses_logged_in_user_in_signature()
    {
        Mail::fake();
        $admin = $this->admin();
        $supplier = Supplier::factory()->create([
            'registration_status' => 'registered',
            'is_active' => false,
        ]);

        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/suppliers/{$supplier->id}/approve")
            ->assertStatus(200);

        Mail::assertSent(SupplierApprovedMail::class, function ($mail) use ($admin) {
            return $mail->senderUser !== null && $mail->senderUser->id === $admin->id;
        });
    }

    public function test_proposal_approved_email_uses_logged_in_user_in_signature()
    {
        Mail::fake();
        $admin = $this->admin();
        [$supplier, $qr, $response] = $this->createResponse();

        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/quotation-responses/{$response->id}/approve", ['notes' => 'Ótima proposta'])
            ->assertStatus(200);

        Mail::assertSent(ProposalApprovedMail::class, function ($mail) use ($admin) {
            return $mail->senderUser !== null && $mail->senderUser->id === $admin->id;
        });
    }

    public function test_proposal_rejected_email_uses_logged_in_user_in_signature()
    {
        Mail::fake();
        $admin = $this->admin();
        [$supplier, $qr, $response] = $this->createResponse();

        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/quotation-responses/{$response->id}/reject", ['notes' => 'Preço alto'])
            ->assertStatus(200);

        Mail::assertSent(ProposalRejectedMail::class, function ($mail) use ($admin) {
            return $mail->senderUser !== null && $mail->senderUser->id === $admin->id;
        });
    }

    public function test_negotiation_email_uses_logged_in_user_in_signature()
    {
        Mail::fake();
        $admin = $this->admin();
        [$supplier, $qr, $response] = $this->createResponse();

        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/quotation-responses/{$response->id}/request-revision", [
                'reason' => 'Precisamos de melhor proposta',
                'message' => 'Reveja os preços por favor',
            ])
            ->assertStatus(200);

        Mail::assertSent(NegotiationNotificationMail::class, function ($mail) use ($admin) {
            return $mail->senderUser !== null && $mail->senderUser->id === $admin->id;
        });
    }
}