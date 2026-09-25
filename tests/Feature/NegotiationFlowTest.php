<?php

namespace Tests\Feature;

use App\Mail\NegotiationNotificationMail;
use App\Mail\ProposalApprovedMail;
use App\Mail\ProposalRejectedMail;
use App\Mail\StaffNotificationMail;
use App\Models\Acquisition;
use App\Models\Notification;
use App\Models\QuotationRequest;
use App\Models\QuotationResponse;
use App\Models\QuotationSupplier;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class NegotiationFlowTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private User $technician;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();

        $this->admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        // O técnico cria o pedido; as rotas de negociação são testadas com o admin
        $this->technician = User::factory()->create(['role' => 'procurement_technician', 'is_active' => true]);
    }

    /** Cria e envia um pedido de cotação para N fornecedores; devolve [pedido, convites]. */
    private function sentRequest(int $suppliers = 2): array
    {
        $supplierIds = Supplier::factory()->count($suppliers)->create()->pluck('id')->toArray();

        $id = $this->actingAs($this->admin, 'sanctum')->postJson('/api/quotation-requests', [
            'title' => 'Portáteis',
            'description' => 'Equipamento',
            'deadline' => now()->addDays(7)->toIso8601String(),
            'suppliers' => $supplierIds,
        ])->assertStatus(201)->json('id');

        // Simular que o pedido foi criado pelo técnico
        QuotationRequest::whereKey($id)->update(['user_id' => $this->technician->id]);

        $this->actingAs($this->admin, 'sanctum')->postJson("/api/quotation-requests/{$id}/send")->assertStatus(200);

        $invites = QuotationSupplier::where('quotation_request_id', $id)->orderBy('id')->get();

        return [QuotationRequest::find($id), $invites];
    }

    private function submit(QuotationSupplier $invite, array $overrides = [])
    {
        return $this->postJson("/api/quotation/{$invite->fresh()->token}/submit", array_merge([
            'delivery_date' => now()->addDays(5)->toDateString(),
            'delivery_days' => 5,
            'payment_terms' => '30 dias',
        ], $overrides));
    }

    public function test_submission_notifies_creator_and_admins_in_app_and_by_email(): void
    {
        [$request, $invites] = $this->sentRequest();

        $this->submit($invites[0])->assertStatus(201);

        foreach ([$this->technician, $this->admin] as $user) {
            $this->assertDatabaseHas('notifications', [
                'user_id' => $user->id,
                'type' => 'quotation_response_submitted',
            ]);
            Mail::assertSent(StaffNotificationMail::class, fn ($mail) => $mail->hasTo($user->email));
        }
    }

    public function test_revised_proposal_is_a_new_response_and_is_listed_first(): void
    {
        [$request, $invites] = $this->sentRequest();

        $first = $this->submit($invites[0])->assertStatus(201)->json('id');

        // Sem pedido de revisão, o fornecedor não pode reenviar
        $this->submit($invites[0])->assertStatus(403);

        $this->actingAs($this->admin, 'sanctum')
            ->postJson("/api/quotation-responses/{$first}/request-revision", ['reason' => 'Preço', 'message' => 'Reveja o preço'])
            ->assertStatus(200)
            ->assertJson(['email_sent' => true]);

        Mail::assertSent(NegotiationNotificationMail::class);
        // O técnico (criador) é avisado de que foi pedida uma revisão
        $this->assertDatabaseHas('notifications', ['user_id' => $this->technician->id, 'type' => 'revision_requested']);

        $revised = $this->submit($invites[0])->assertStatus(201)->json();
        $this->assertSame(2, $revised['revision_number']);

        $this->assertDatabaseHas('notifications', ['user_id' => $this->technician->id, 'type' => 'quotation_response_revised']);

        // A listagem devolve a revisão mais recente primeiro
        $list = $this->actingAs($this->admin, 'sanctum')
            ->getJson("/api/quotation-responses?quotation_request_id={$request->id}&per_page=100")
            ->assertStatus(200)
            ->json('data');
        $this->assertSame($revised['id'], $list[0]['id']);

        // A versão antiga já não pode ser aprovada
        $this->actingAs($this->admin, 'sanctum')
            ->postJson("/api/quotation-responses/{$first}/approve", ['expected_delivery_date' => now()->addDays(10)->toDateString()])
            ->assertStatus(422);
    }

    public function test_approve_generates_acquisition_completes_request_and_rejects_others(): void
    {
        [$request, $invites] = $this->sentRequest(3);

        $winner = $this->submit($invites[0])->json('id');
        $other = $this->submit($invites[1])->json('id');
        // O terceiro fornecedor não responde

        $this->actingAs($this->admin, 'sanctum')
            ->postJson("/api/quotation-responses/{$winner}/approve")
            ->assertStatus(422)
            ->assertJsonValidationErrors('expected_delivery_date');

        $this->actingAs($this->admin, 'sanctum')
            ->postJson("/api/quotation-responses/{$winner}/approve", [
                'expected_delivery_date' => now()->addDays(10)->toDateString(),
                'justification' => 'Melhor preço',
            ])
            ->assertStatus(200)
            ->assertJson(['status' => 'approved', 'rejected_response_ids' => [$other]]);

        $this->assertSame('completed', $request->fresh()->status);
        $this->assertSame(1, Acquisition::where('quotation_response_id', $winner)->count());
        $this->assertSame('rejected', QuotationResponse::find($other)->status);

        Mail::assertSent(ProposalApprovedMail::class, 1);
        Mail::assertSent(ProposalRejectedMail::class, 1);
        $this->assertDatabaseHas('notifications', ['user_id' => $this->technician->id, 'type' => 'proposal_approved']);
        // Quem aprovou não recebe notificação da própria acção
        $this->assertDatabaseMissing('notifications', ['user_id' => $this->admin->id, 'type' => 'proposal_approved']);

        // Não é possível aprovar outra proposta nem reenviar depois de concluído
        $this->actingAs($this->admin, 'sanctum')
            ->postJson("/api/quotation-responses/{$other}/approve", ['expected_delivery_date' => now()->addDays(10)->toDateString()])
            ->assertStatus(422);
        $this->submit($invites[2])->assertStatus(403);
    }

    public function test_decline_notifies_staff(): void
    {
        [$request, $invites] = $this->sentRequest();

        $this->postJson("/api/quotation/{$invites[1]->token}/decline")->assertStatus(200);

        $this->assertSame(2, Notification::where('type', 'quotation_declined')->count());
        Mail::assertSent(StaffNotificationMail::class, 2);
    }
}
