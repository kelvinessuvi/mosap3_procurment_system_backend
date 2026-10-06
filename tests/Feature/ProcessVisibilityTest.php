<?php

namespace Tests\Feature;

use App\Models\Menu;
use App\Models\QuotationRequest;
use App\Models\QuotationRequestAssignment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Técnicos só vêem os processos que iniciaram ou que lhes foram atribuídos.
 */
class ProcessVisibilityTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    private function technician(string $level = 'write'): User
    {
        $user = User::factory()->create(['role' => 'procurement_technician']);

        foreach (['quotation-requests', 'acquisitions', 'documents'] as $slug) {
            $menu = Menu::firstOrCreate(['slug' => $slug], ['name' => $slug, 'order' => 1]);
            $user->menuPermissions()->attach($menu->id, ['level' => $level]);
        }

        return $user;
    }

    private function processOf(User $owner): QuotationRequest
    {
        return QuotationRequest::factory()->create(['user_id' => $owner->id]);
    }

    public function test_listagem_mostra_so_o_processo_do_proprio_tecnico(): void
    {
        $meu = $this->processOf($tecnico = $this->technician());
        $alheio = $this->processOf($this->technician());

        $ids = collect($this->actingAs($tecnico, 'sanctum')
            ->getJson('/api/quotation-requests')->assertStatus(200)->json('data'))
            ->pluck('id');

        $this->assertContains($meu->id, $ids);
        $this->assertNotContains($alheio->id, $ids);
    }

    public function test_admin_continua_a_ver_todos(): void
    {
        $a = $this->processOf($this->technician());
        $b = $this->processOf($this->technician());

        $ids = collect($this->actingAs($this->admin(), 'sanctum')
            ->getJson('/api/quotation-requests')->assertStatus(200)->json('data'))
            ->pluck('id');

        $this->assertContains($a->id, $ids);
        $this->assertContains($b->id, $ids);
    }

    /**
     * O que prova que o middleware recebeu o Model e não o id em bruto: se
     * tivesse recebido uma string, ignorava-a e devolvia 200.
     */
    public function test_acesso_directo_por_id_a_processo_alheio_da_403(): void
    {
        $tecnico = $this->technician();
        $alheio = $this->processOf($this->technician());

        $this->actingAs($tecnico, 'sanctum')
            ->getJson("/api/quotation-requests/{$alheio->id}")
            ->assertStatus(403);
    }

    public function test_escritas_em_processo_alheio_dao_403(): void
    {
        $tecnico = $this->technician();
        $alheio = $this->processOf($this->technician());

        $this->actingAs($tecnico, 'sanctum')->putJson("/api/quotation-requests/{$alheio->id}", ['title' => 'x'])->assertStatus(403);
        $this->actingAs($tecnico, 'sanctum')->postJson("/api/quotation-requests/{$alheio->id}/send")->assertStatus(403);
        $this->actingAs($tecnico, 'sanctum')->postJson("/api/quotation-requests/{$alheio->id}/cancel")->assertStatus(403);
        $this->actingAs($tecnico, 'sanctum')->deleteJson("/api/quotation-requests/{$alheio->id}", ['reason' => 'x'])->assertStatus(403);
    }

    public function test_processo_proprio_continua_acessivel(): void
    {
        $tecnico = $this->technician();
        $meu = $this->processOf($tecnico);

        $this->actingAs($tecnico, 'sanctum')
            ->getJson("/api/quotation-requests/{$meu->id}")
            ->assertStatus(200);
    }

    public function test_atribuicao_pendente_nao_da_acesso(): void
    {
        $tecnico = $this->technician();
        $alheio = $this->processOf($criador = $this->technician());

        QuotationRequestAssignment::create([
            'quotation_request_id' => $alheio->id,
            'user_id' => $tecnico->id,
            'status' => QuotationRequestAssignment::PENDING,
            'requested_by' => $criador->id,
            'reason' => 'preciso de ajuda',
        ]);

        $this->actingAs($tecnico, 'sanctum')
            ->getJson("/api/quotation-requests/{$alheio->id}")
            ->assertStatus(403);

        $ids = collect($this->actingAs($tecnico, 'sanctum')
            ->getJson('/api/quotation-requests')->json('data'))->pluck('id');
        $this->assertNotContains($alheio->id, $ids);
    }

    public function test_atribuicao_activa_da_acesso_de_leitura_e_escrita(): void
    {
        $tecnico = $this->technician();
        $alheio = $this->processOf($criador = $this->technician());

        QuotationRequestAssignment::create([
            'quotation_request_id' => $alheio->id,
            'user_id' => $tecnico->id,
            'status' => QuotationRequestAssignment::ACTIVE,
            'requested_by' => $criador->id,
        ]);

        $this->actingAs($tecnico, 'sanctum')
            ->getJson("/api/quotation-requests/{$alheio->id}")
            ->assertStatus(200);

        $ids = collect($this->actingAs($tecnico, 'sanctum')
            ->getJson('/api/quotation-requests')->json('data'))->pluck('id');
        $this->assertContains($alheio->id, $ids);

        // decisão do utilizador: o atribuído age como o criador
        $this->actingAs($tecnico, 'sanctum')
            ->postJson("/api/quotation-requests/{$alheio->id}/cancel")
            ->assertStatus(200);
    }

    public function test_atribuicao_rejeitada_ou_revogada_nao_da_acesso(): void
    {
        $tecnico = $this->technician();
        $criador = $this->technician();

        foreach ([QuotationRequestAssignment::REJECTED, QuotationRequestAssignment::REVOKED] as $estado) {
            $processo = $this->processOf($criador);
            QuotationRequestAssignment::create([
                'quotation_request_id' => $processo->id,
                'user_id' => $tecnico->id,
                'status' => $estado,
                'requested_by' => $criador->id,
            ]);

            $this->actingAs($tecnico, 'sanctum')
                ->getJson("/api/quotation-requests/{$processo->id}")
                ->assertStatus(403, "estado {$estado} nao devia dar acesso");
        }
    }

    /** O OR do scope tem de estar entre parênteses, senão um filtro vaza tudo. */
    public function test_filtro_de_status_nao_vaza_processos_alheios(): void
    {
        $tecnico = $this->technician();
        $alheio = $this->processOf($this->technician());   // factory cria em 'draft'

        $ids = collect($this->actingAs($tecnico, 'sanctum')
            ->getJson('/api/quotation-requests?status=draft')->assertStatus(200)->json('data'))
            ->pluck('id');

        $this->assertNotContains($alheio->id, $ids);
    }

    public function test_atribuido_sem_permissao_de_escrita_nao_pode_cancelar(): void
    {
        $tecnico = $this->technician('read');
        $alheio = $this->processOf($criador = $this->technician());

        QuotationRequestAssignment::create([
            'quotation_request_id' => $alheio->id,
            'user_id' => $tecnico->id,
            'status' => QuotationRequestAssignment::ACTIVE,
            'requested_by' => $criador->id,
        ]);

        $this->actingAs($tecnico, 'sanctum')->getJson("/api/quotation-requests/{$alheio->id}")->assertStatus(200);
        $this->actingAs($tecnico, 'sanctum')->postJson("/api/quotation-requests/{$alheio->id}/cancel")->assertStatus(403);
    }

    /** O índice único fecha a race condition, não o exists() do código. */
    public function test_indice_impede_pedido_pendente_duplicado(): void
    {
        $tecnico = $this->technician();
        $processo = $this->processOf($criador = $this->technician());

        $dados = [
            'quotation_request_id' => $processo->id,
            'user_id' => $tecnico->id,
            'status' => QuotationRequestAssignment::PENDING,
            'requested_by' => $criador->id,
        ];

        QuotationRequestAssignment::create($dados);

        $this->expectException(\Illuminate\Database\QueryException::class);
        QuotationRequestAssignment::create($dados);
    }

    /** Histórico ilimitado: o índice único só restringe pending/active. */
    public function test_historico_permite_varias_linhas_fechadas(): void
    {
        $tecnico = $this->technician();
        $processo = $this->processOf($criador = $this->technician());

        foreach ([QuotationRequestAssignment::REJECTED, QuotationRequestAssignment::REJECTED, QuotationRequestAssignment::REVOKED] as $estado) {
            QuotationRequestAssignment::create([
                'quotation_request_id' => $processo->id,
                'user_id' => $tecnico->id,
                'status' => $estado,
                'requested_by' => $criador->id,
            ]);
        }

        $this->assertSame(3, QuotationRequestAssignment::count());
        $this->assertNull(QuotationRequestAssignment::first()->open_state);
    }
}
