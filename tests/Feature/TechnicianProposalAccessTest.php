<?php

namespace Tests\Feature;

use App\Models\Menu;
use App\Models\QuotationItem;
use App\Models\QuotationRequest;
use App\Models\QuotationRequestAssignment;
use App\Models\QuotationResponse;
use App\Models\QuotationSupplier;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * O técnico que iniciou o processo (ou que lhe foi atribuído) vê e negoceia as
 * propostas dele. O que limita é a permissão de menu — 'read' só vê, 'write'
 * age — e a visibilidade nunca deixa passar dos processos do próprio.
 */
class TechnicianProposalAccessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    private function technician(string $nivel = 'write'): User
    {
        $user = User::factory()->create(['role' => 'procurement_technician']);

        foreach (['supplier-evaluations', 'quotation-requests', 'acquisitions'] as $slug) {
            $menu = Menu::firstOrCreate(['slug' => $slug], ['name' => $slug, 'order' => 1]);
            $user->menuPermissions()->attach($menu->id, ['level' => $nivel]);
        }

        return $user;
    }

    /** Processo com uma proposta por decidir. */
    private function propostaDe(User $dono): QuotationResponse
    {
        $fornecedor = Supplier::factory()->create();
        $processo = QuotationRequest::factory()->create([
            'user_id' => $dono->id,
            'status' => 'in_progress',
            'procurement_category' => 'bens',
        ]);
        QuotationItem::factory()->create(['quotation_request_id' => $processo->id]);
        $convite = QuotationSupplier::factory()->create([
            'quotation_request_id' => $processo->id,
            'supplier_id' => $fornecedor->id,
            'status' => 'submitted',
        ]);

        return QuotationResponse::factory()->create([
            'quotation_supplier_id' => $convite->id,
            'status' => 'pending_review',
        ]);
    }

    public function test_tecnico_ve_as_propostas_do_processo_que_iniciou(): void
    {
        $tecnico = $this->technician();
        $minha = $this->propostaDe($tecnico);

        $ids = collect($this->actingAs($tecnico, 'sanctum')
            ->getJson('/api/quotation-responses')->assertStatus(200)->json('data'))->pluck('id');

        $this->assertContains($minha->id, $ids);
    }

    public function test_listagem_nao_mostra_propostas_de_processos_alheios(): void
    {
        $tecnico = $this->technician();
        $this->propostaDe($tecnico);
        $alheia = $this->propostaDe($this->technician());

        $ids = collect($this->actingAs($tecnico, 'sanctum')
            ->getJson('/api/quotation-responses')->json('data'))->pluck('id');

        $this->assertNotContains($alheia->id, $ids);
    }

    public function test_acesso_directo_a_proposta_alheia_da_403(): void
    {
        $tecnico = $this->technician();
        $alheia = $this->propostaDe($this->technician());

        $this->actingAs($tecnico, 'sanctum')
            ->getJson("/api/quotation-responses/{$alheia->id}")->assertStatus(403);
    }

    public function test_tecnico_negoceia_a_proposta_do_processo_dele(): void
    {
        $tecnico = $this->technician();
        $minha = $this->propostaDe($tecnico);

        $this->actingAs($tecnico, 'sanctum')
            ->postJson("/api/quotation-responses/{$minha->id}/request-revision", [
                'reason' => 'Prazo de entrega',
                'message' => 'Reveja o prazo de entrega, por favor.',
            ])->assertStatus(200);

        $this->assertDatabaseHas('quotation_responses', [
            'id' => $minha->id,
            'status' => 'needs_revision',
        ]);
    }

    public function test_tecnico_aprova_a_proposta_do_processo_dele(): void
    {
        $tecnico = $this->technician();
        $minha = $this->propostaDe($tecnico);

        $this->actingAs($tecnico, 'sanctum')
            ->postJson("/api/quotation-responses/{$minha->id}/approve", [
                'expected_delivery_date' => now()->addDays(20)->toDateString(),
                'justification' => 'Melhor proposta.',
            ])->assertStatus(200);

        $this->assertDatabaseHas('quotation_responses', ['id' => $minha->id, 'status' => 'approved']);
    }

    public function test_tecnico_nao_age_sobre_proposta_alheia(): void
    {
        $tecnico = $this->technician();
        $alheia = $this->propostaDe($this->technician());

        foreach (['approve', 'reject', 'request-revision'] as $accao) {
            $this->actingAs($tecnico, 'sanctum')
                ->postJson("/api/quotation-responses/{$alheia->id}/{$accao}", [
                    'reason' => 'x', 'message' => 'y', 'notes' => 'x',
                ])
                ->assertStatus(403, "a acção {$accao} não devia ser permitida");
        }
    }

    /** 'read' no menu deixa ver, mas não deixa decidir. */
    public function test_tecnico_so_de_leitura_ve_mas_nao_age(): void
    {
        $tecnico = $this->technician('read');
        $minha = $this->propostaDe($tecnico);

        $this->actingAs($tecnico, 'sanctum')
            ->getJson("/api/quotation-responses/{$minha->id}")->assertStatus(200);

        $this->actingAs($tecnico, 'sanctum')
            ->postJson("/api/quotation-responses/{$minha->id}/request-revision", ['reason' => 'x', 'message' => 'y'])
            ->assertStatus(403);
        $this->actingAs($tecnico, 'sanctum')
            ->postJson("/api/quotation-responses/{$minha->id}/approve", [
                'expected_delivery_date' => now()->addDays(20)->toDateString(),
            ])->assertStatus(403);
    }

    /** Sem a permissão de menu não chega sequer à listagem. */
    public function test_tecnico_sem_o_menu_nao_ve_propostas(): void
    {
        $semMenu = User::factory()->create(['role' => 'procurement_technician']);

        $this->actingAs($semMenu, 'sanctum')
            ->getJson('/api/quotation-responses')->assertStatus(403);
    }

    public function test_tecnico_atribuido_ve_e_negoceia(): void
    {
        $tecnico = $this->technician();
        $criador = $this->technician();
        $proposta = $this->propostaDe($criador);
        $processoId = $proposta->quotationSupplier->quotation_request_id;

        // Antes da atribuição não vê nada.
        $this->actingAs($tecnico, 'sanctum')
            ->getJson("/api/quotation-responses/{$proposta->id}")->assertStatus(403);

        QuotationRequestAssignment::create([
            'quotation_request_id' => $processoId,
            'user_id' => $tecnico->id,
            'status' => QuotationRequestAssignment::ACTIVE,
            'requested_by' => $criador->id,
        ]);

        $this->actingAs($tecnico, 'sanctum')
            ->getJson("/api/quotation-responses/{$proposta->id}")->assertStatus(200);
        $this->actingAs($tecnico, 'sanctum')
            ->postJson("/api/quotation-responses/{$proposta->id}/request-revision", [
                'reason' => 'Preço',
                'message' => 'Rever o preço unitário.',
            ])->assertStatus(200);
    }

    /** Atribuição pendente continua a não dar acesso às propostas. */
    public function test_atribuicao_pendente_nao_abre_as_propostas(): void
    {
        $tecnico = $this->technician();
        $criador = $this->technician();
        $proposta = $this->propostaDe($criador);

        QuotationRequestAssignment::create([
            'quotation_request_id' => $proposta->quotationSupplier->quotation_request_id,
            'user_id' => $tecnico->id,
            'status' => QuotationRequestAssignment::PENDING,
            'requested_by' => $criador->id,
        ]);

        $this->actingAs($tecnico, 'sanctum')
            ->getJson("/api/quotation-responses/{$proposta->id}")->assertStatus(403);
    }

    /** As avaliações de fornecedores são dados globais: continuam só do admin. */
    public function test_avaliacoes_de_fornecedores_continuam_so_do_admin(): void
    {
        $this->actingAs($this->technician(), 'sanctum')
            ->getJson('/api/supplier-evaluations')->assertStatus(403);

        $this->actingAs($this->admin(), 'sanctum')
            ->getJson('/api/supplier-evaluations')->assertStatus(200);
    }

    public function test_admin_continua_a_ver_e_decidir_tudo(): void
    {
        $admin = $this->admin();
        $a = $this->propostaDe($this->technician());
        $b = $this->propostaDe($this->technician());

        $ids = collect($this->actingAs($admin, 'sanctum')
            ->getJson('/api/quotation-responses')->assertStatus(200)->json('data'))->pluck('id');

        $this->assertContains($a->id, $ids);
        $this->assertContains($b->id, $ids);

        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/quotation-responses/{$a->id}/request-revision", [
                'reason' => 'Revisão',
                'message' => 'Rever a proposta.',
            ])->assertStatus(200);
    }
}
