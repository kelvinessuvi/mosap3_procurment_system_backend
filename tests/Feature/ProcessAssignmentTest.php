<?php

namespace Tests\Feature;

use App\Models\Menu;
use App\Models\Notification;
use App\Models\QuotationRequest;
use App\Models\QuotationRequestAssignment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * O criador pede a atribuição, o administrador aprova. O administrador também
 * pode atribuir directamente.
 */
class ProcessAssignmentTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        $admin = User::factory()->create(['role' => 'admin']);
        Menu::firstOrCreate(['slug' => 'assignment-requests'], ['name' => 'Pedidos de Atribuição', 'order' => 13]);

        return $admin;
    }

    private function technician(string $level = 'write'): User
    {
        $user = User::factory()->create(['role' => 'procurement_technician']);
        $menu = Menu::firstOrCreate(['slug' => 'quotation-requests'], ['name' => 'Cotações', 'order' => 3]);
        $user->menuPermissions()->attach($menu->id, ['level' => $level]);

        return $user;
    }

    public function test_criador_pede_atribuicao_e_admins_sao_notificados(): void
    {
        $admin = $this->admin();
        $criador = $this->technician();
        $colega = $this->technician();
        $processo = QuotationRequest::factory()->create(['user_id' => $criador->id]);

        $res = $this->actingAs($criador, 'sanctum')
            ->postJson("/api/quotation-requests/{$processo->id}/assignments", [
                'user_id' => $colega->id,
                'reason' => 'Preciso de apoio neste processo.',
            ]);

        $res->assertStatus(201)
            ->assertJsonPath('assignment.status', QuotationRequestAssignment::PENDING);

        $this->assertDatabaseHas('quotation_request_assignments', [
            'quotation_request_id' => $processo->id,
            'user_id' => $colega->id,
            'status' => QuotationRequestAssignment::PENDING,
            'requested_by' => $criador->id,
            'open_state' => QuotationRequestAssignment::PENDING,
        ]);

        $this->assertDatabaseHas('notifications', [
            'user_id' => $admin->id,
            'type' => 'assignment_requested',
        ]);

        $this->assertDatabaseHas('audit_logs', ['event' => 'Solicitação de atribuição']);

        // pendente não dá acesso
        $this->actingAs($colega, 'sanctum')
            ->getJson("/api/quotation-requests/{$processo->id}")->assertStatus(403);
    }

    public function test_justificacao_obrigatoria_para_tecnico_opcional_para_admin(): void
    {
        $admin = $this->admin();
        $criador = $this->technician();
        $processo = QuotationRequest::factory()->create(['user_id' => $criador->id]);

        $this->actingAs($criador, 'sanctum')
            ->postJson("/api/quotation-requests/{$processo->id}/assignments", [
                'user_id' => $this->technician()->id,
            ])->assertStatus(422)->assertJsonValidationErrors('reason');

        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/quotation-requests/{$processo->id}/assignments", [
                'user_id' => $this->technician()->id,
            ])->assertStatus(201);
    }

    public function test_admin_atribui_directamente_e_o_acesso_e_imediato(): void
    {
        $admin = $this->admin();
        $criador = $this->technician();
        $colega = $this->technician();
        $processo = QuotationRequest::factory()->create(['user_id' => $criador->id]);

        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/quotation-requests/{$processo->id}/assignments", ['user_id' => $colega->id])
            ->assertStatus(201)
            ->assertJsonPath('assignment.status', QuotationRequestAssignment::ACTIVE);

        $this->actingAs($colega, 'sanctum')
            ->getJson("/api/quotation-requests/{$processo->id}")->assertStatus(200);

        $this->assertDatabaseHas('notifications', [
            'user_id' => $colega->id,
            'type' => 'assignment_granted',
        ]);
    }

    public function test_tecnico_que_nao_criou_nao_pode_pedir_atribuicao(): void
    {
        $this->admin();
        $criador = $this->technician();
        $atribuido = $this->technician();
        $processo = QuotationRequest::factory()->create(['user_id' => $criador->id]);

        // o atribuído vê o processo, mas não pode juntar mais ninguém
        QuotationRequestAssignment::create([
            'quotation_request_id' => $processo->id,
            'user_id' => $atribuido->id,
            'status' => QuotationRequestAssignment::ACTIVE,
            'requested_by' => $criador->id,
        ]);

        $this->actingAs($atribuido, 'sanctum')
            ->postJson("/api/quotation-requests/{$processo->id}/assignments", [
                'user_id' => $this->technician()->id,
                'reason' => 'x',
            ])->assertStatus(403);
    }

    public function test_tecnico_de_fora_nao_chega_ao_processo(): void
    {
        $this->admin();
        $processo = QuotationRequest::factory()->create(['user_id' => $this->technician()->id]);

        $this->actingAs($this->technician(), 'sanctum')
            ->postJson("/api/quotation-requests/{$processo->id}/assignments", [
                'user_id' => $this->technician()->id,
                'reason' => 'x',
            ])->assertStatus(403);
    }

    public function test_pedido_duplicado_da_409(): void
    {
        $this->admin();
        $criador = $this->technician();
        $colega = $this->technician();
        $processo = QuotationRequest::factory()->create(['user_id' => $criador->id]);

        $payload = ['user_id' => $colega->id, 'reason' => 'x'];

        $this->actingAs($criador, 'sanctum')
            ->postJson("/api/quotation-requests/{$processo->id}/assignments", $payload)->assertStatus(201);
        $this->actingAs($criador, 'sanctum')
            ->postJson("/api/quotation-requests/{$processo->id}/assignments", $payload)->assertStatus(409);
    }

    public function test_alvos_invalidos_dao_422(): void
    {
        $admin = $this->admin();
        $criador = $this->technician();
        $processo = QuotationRequest::factory()->create(['user_id' => $criador->id]);
        $inactivo = User::factory()->create(['role' => 'procurement_technician', 'is_active' => false]);

        $casos = [
            ['user_id' => $admin->id, 'esperado' => 'administradores'],
            ['user_id' => $criador->id, 'esperado' => 'criador'],
            ['user_id' => $inactivo->id, 'esperado' => 'inactivo'],
        ];

        foreach ($casos as $caso) {
            $res = $this->actingAs($admin, 'sanctum')
                ->postJson("/api/quotation-requests/{$processo->id}/assignments", ['user_id' => $caso['user_id']]);
            $res->assertStatus(422);
            $this->assertStringContainsStringIgnoringCase($caso['esperado'], $res->json('message'));
        }
    }

    public function test_aprovacao_da_acesso_e_notifica_ambos(): void
    {
        $admin = $this->admin();
        $criador = $this->technician();
        $colega = $this->technician();
        $processo = QuotationRequest::factory()->create(['user_id' => $criador->id]);

        $id = $this->actingAs($criador, 'sanctum')
            ->postJson("/api/quotation-requests/{$processo->id}/assignments", ['user_id' => $colega->id, 'reason' => 'x'])
            ->json('assignment.id');

        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/assignment-requests/{$id}/approve")->assertStatus(200);

        $this->assertDatabaseHas('quotation_request_assignments', [
            'id' => $id,
            'status' => QuotationRequestAssignment::ACTIVE,
            'reviewed_by' => $admin->id,
        ]);

        $this->actingAs($colega, 'sanctum')
            ->getJson("/api/quotation-requests/{$processo->id}")->assertStatus(200);

        $this->assertDatabaseHas('notifications', ['user_id' => $criador->id, 'type' => 'assignment_approved']);
        $this->assertDatabaseHas('notifications', ['user_id' => $colega->id, 'type' => 'assignment_granted']);
        $this->assertDatabaseHas('audit_logs', ['event' => 'Aprovação de atribuição']);
    }

    public function test_aprovar_duas_vezes_da_400(): void
    {
        $admin = $this->admin();
        $criador = $this->technician();
        $processo = QuotationRequest::factory()->create(['user_id' => $criador->id]);

        $id = $this->actingAs($criador, 'sanctum')
            ->postJson("/api/quotation-requests/{$processo->id}/assignments", ['user_id' => $this->technician()->id, 'reason' => 'x'])
            ->json('assignment.id');

        $this->actingAs($admin, 'sanctum')->postJson("/api/assignment-requests/{$id}/approve")->assertStatus(200);
        $this->actingAs($admin, 'sanctum')->postJson("/api/assignment-requests/{$id}/approve")->assertStatus(400);
    }

    public function test_rejeicao_exige_justificacao_e_nao_da_acesso(): void
    {
        $admin = $this->admin();
        $criador = $this->technician();
        $colega = $this->technician();
        $processo = QuotationRequest::factory()->create(['user_id' => $criador->id]);

        $id = $this->actingAs($criador, 'sanctum')
            ->postJson("/api/quotation-requests/{$processo->id}/assignments", ['user_id' => $colega->id, 'reason' => 'x'])
            ->json('assignment.id');

        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/assignment-requests/{$id}/reject")->assertStatus(422);

        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/assignment-requests/{$id}/reject", ['rejection_reason' => 'Não é necessário.'])
            ->assertStatus(200);

        $this->assertDatabaseHas('quotation_request_assignments', [
            'id' => $id,
            'status' => QuotationRequestAssignment::REJECTED,
            'rejection_reason' => 'Não é necessário.',
            'open_state' => null,
        ]);

        $this->actingAs($colega, 'sanctum')
            ->getJson("/api/quotation-requests/{$processo->id}")->assertStatus(403);
        $this->assertDatabaseHas('notifications', ['user_id' => $criador->id, 'type' => 'assignment_rejected']);
    }

    public function test_re_pedir_depois_de_rejeicao_funciona_e_preserva_historico(): void
    {
        $admin = $this->admin();
        $criador = $this->technician();
        $colega = $this->technician();
        $processo = QuotationRequest::factory()->create(['user_id' => $criador->id]);
        $payload = ['user_id' => $colega->id, 'reason' => 'x'];

        $id = $this->actingAs($criador, 'sanctum')
            ->postJson("/api/quotation-requests/{$processo->id}/assignments", $payload)->json('assignment.id');
        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/assignment-requests/{$id}/reject", ['rejection_reason' => 'não'])->assertStatus(200);

        $this->actingAs($criador, 'sanctum')
            ->postJson("/api/quotation-requests/{$processo->id}/assignments", $payload)->assertStatus(201);

        $this->assertSame(2, QuotationRequestAssignment::where('quotation_request_id', $processo->id)->count());
    }

    public function test_revogar_retira_o_acesso(): void
    {
        $admin = $this->admin();
        $criador = $this->technician();
        $colega = $this->technician();
        $processo = QuotationRequest::factory()->create(['user_id' => $criador->id]);

        $id = $this->actingAs($admin, 'sanctum')
            ->postJson("/api/quotation-requests/{$processo->id}/assignments", ['user_id' => $colega->id])
            ->json('assignment.id');

        $this->actingAs($colega, 'sanctum')->getJson("/api/quotation-requests/{$processo->id}")->assertStatus(200);

        $this->actingAs($criador, 'sanctum')
            ->deleteJson("/api/quotation-requests/{$processo->id}/assignments/{$id}")->assertStatus(200);

        $this->actingAs($colega, 'sanctum')->getJson("/api/quotation-requests/{$processo->id}")->assertStatus(403);
        $this->assertDatabaseHas('notifications', ['user_id' => $colega->id, 'type' => 'assignment_revoked']);

        // segunda vez já não está activa
        $this->actingAs($criador, 'sanctum')
            ->deleteJson("/api/quotation-requests/{$processo->id}/assignments/{$id}")->assertStatus(400);
    }

    public function test_tecnico_nao_acede_a_fila_de_pedidos_do_admin(): void
    {
        $this->admin();
        $this->actingAs($this->technician(), 'sanctum')
            ->getJson('/api/assignment-requests')->assertStatus(403);
    }

    public function test_admin_ve_a_fila_de_pendentes(): void
    {
        $admin = $this->admin();
        $criador = $this->technician();
        $processo = QuotationRequest::factory()->create(['user_id' => $criador->id]);

        $this->actingAs($criador, 'sanctum')
            ->postJson("/api/quotation-requests/{$processo->id}/assignments", ['user_id' => $this->technician()->id, 'reason' => 'x']);

        $this->actingAs($admin, 'sanctum')->getJson('/api/assignment-requests')
            ->assertStatus(200)->assertJsonCount(1, 'data');
    }

    public function test_listagem_de_tecnicos_exclui_o_proprio_e_inactivos(): void
    {
        $this->admin();
        $tecnico = $this->technician();
        $outro = $this->technician();
        User::factory()->create(['role' => 'procurement_technician', 'is_active' => false]);

        $ids = collect($this->actingAs($tecnico, 'sanctum')
            ->getJson('/api/users/technicians')->assertStatus(200)->json())->pluck('id');

        $this->assertContains($outro->id, $ids);
        $this->assertNotContains($tecnico->id, $ids);
        $this->assertCount(1, $ids);
    }
}
