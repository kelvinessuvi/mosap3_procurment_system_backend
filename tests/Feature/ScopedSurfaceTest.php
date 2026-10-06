<?php

namespace Tests\Feature;

use App\Models\Menu;
use App\Models\QuotationRequest;
use App\Models\QuotationRequestAssignment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Rede de segurança estrutural: garante que nenhuma rota que lide com processos
 * de aquisição fica sem guarda de visibilidade por esquecimento.
 *
 * Se alguém acrescentar uma rota nova a estes prefixos fora dos grupos com
 * `visible`, este teste fica vermelho — é o mecanismo, e não a boa memória, que
 * assegura a cobertura.
 */
class ScopedSurfaceTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Rotas destes prefixos que legitimamente não precisam do middleware, porque
     * não recebem um processo concreto: criação, listagens (filtradas pelo scope
     * no controller) e agregados.
     */
    private const SEM_PARAMETRO_DE_PROCESSO = [
        'api/quotation-requests',                      // index (scope) + store
        'api/acquisitions',                            // index (scope)
        'api/acquisitions/stats/products',             // agregado (filtro na query crua)
        'api/quotation-responses',                     // index (admin-only)
        'api/reports/summary',                         // agregados globais, por decisão
        'api/suppliers/{id}/acquisitions',             // filtrado pelo scope no controller
    ];

    public function test_toda_a_rota_de_processo_tem_guarda_de_visibilidade(): void
    {
        $prefixos = ['api/quotation-requests', 'api/acquisitions', 'api/quotation-responses'];
        $desprotegidas = [];

        foreach (Route::getRoutes() as $route) {
            if (! Str::startsWith($route->uri(), $prefixos)) {
                continue;
            }

            if (in_array($route->uri(), self::SEM_PARAMETRO_DE_PROCESSO, true)) {
                continue;
            }

            if (! in_array('visible', $route->gatherMiddleware(), true)) {
                $desprotegidas[] = $route->methods()[0].' '.$route->uri();
            }
        }

        $this->assertSame([], $desprotegidas, implode(
            "\n",
            array_merge(
                ['Rotas de processos sem a guarda `visible` nem na lista de excepções:'],
                $desprotegidas
            )
        ));
    }

    /** O documento da proposta estava acessível por ID a qualquer técnico. */
    public function test_documento_da_proposta_esta_sob_guarda(): void
    {
        $rota = collect(Route::getRoutes())->first(
            fn ($r) => $r->uri() === 'api/quotation-responses/{quotationResponse}/document'
        );

        $this->assertNotNull($rota);
        $this->assertContains('visible', $rota->gatherMiddleware());
    }

    /** O token do fornecedor é o segredo que autentica a submissão da proposta. */
    public function test_detalhe_do_processo_nao_devolve_o_token_do_fornecedor(): void
    {
        $menu = Menu::firstOrCreate(['slug' => 'quotation-requests'], ['name' => 'Cotações', 'order' => 3]);
        $tecnico = User::factory()->create(['role' => 'procurement_technician']);
        $tecnico->menuPermissions()->attach($menu->id, ['level' => 'write']);

        $processo = QuotationRequest::factory()->create(['user_id' => $tecnico->id]);
        $fornecedor = \App\Models\Supplier::factory()->create();
        $processo->suppliers()->attach($fornecedor->id, ['token' => 'segredo-do-fornecedor', 'status' => 'sent']);

        $corpo = $this->actingAs($tecnico, 'sanctum')
            ->getJson("/api/quotation-requests/{$processo->id}")
            ->assertStatus(200)
            ->getContent();

        $this->assertStringNotContainsString('segredo-do-fornecedor', $corpo);
        $this->assertArrayNotHasKey('token', $this->actingAs($tecnico, 'sanctum')
            ->getJson("/api/quotation-requests/{$processo->id}")->json('suppliers.0.pivot'));
    }

    /** Cancelar duas vezes, ou cancelar um processo concluído, deve falhar. */
    public function test_cancelar_processo_concluido_da_400(): void
    {
        $menu = Menu::firstOrCreate(['slug' => 'quotation-requests'], ['name' => 'Cotações', 'order' => 3]);
        $tecnico = User::factory()->create(['role' => 'procurement_technician']);
        $tecnico->menuPermissions()->attach($menu->id, ['level' => 'write']);

        $processo = QuotationRequest::factory()->create([
            'user_id' => $tecnico->id,
            'status' => 'sent',
        ]);

        $this->actingAs($tecnico, 'sanctum')
            ->postJson("/api/quotation-requests/{$processo->id}/cancel")->assertStatus(200);
        $this->actingAs($tecnico, 'sanctum')
            ->postJson("/api/quotation-requests/{$processo->id}/cancel")->assertStatus(400);
    }

    /** Os relatórios agregados ficam globais, por decisão expressa. */
    public function test_relatorios_agregados_continuam_globais(): void
    {
        $menu = Menu::firstOrCreate(['slug' => 'acquisitions'], ['name' => 'Aquisições', 'order' => 4]);
        $tecnico = User::factory()->create(['role' => 'procurement_technician']);
        $tecnico->menuPermissions()->attach($menu->id, ['level' => 'read']);

        QuotationRequest::factory()->count(2)->create([
            'user_id' => User::factory()->create(['role' => 'procurement_technician'])->id,
        ]);

        $metrics = $this->actingAs($tecnico, 'sanctum')
            ->getJson('/api/reports/summary')->assertStatus(200)->json('metrics');

        // Conta as cotações de outros: se um dia alguém filtrar isto, este teste
        // avisa que está a mudar uma decisão tomada, não a corrigir um bug.
        $this->assertSame(2, $metrics['total_quotations']);
    }

    /** Um técnico atribuído passa a receber as notificações do processo. */
    public function test_atribuido_entra_nos_destinatarios_do_processo(): void
    {
        $criador = User::factory()->create(['role' => 'procurement_technician']);
        $colega = User::factory()->create(['role' => 'procurement_technician']);
        $processo = QuotationRequest::factory()->create(['user_id' => $criador->id]);

        $notifier = app(\App\Services\ProcurementNotifier::class);
        $this->assertNotContains($colega->id, $notifier->recipients($processo)->pluck('id'));

        QuotationRequestAssignment::create([
            'quotation_request_id' => $processo->id,
            'user_id' => $colega->id,
            'status' => QuotationRequestAssignment::ACTIVE,
            'requested_by' => $criador->id,
        ]);

        $this->assertContains($colega->id, $notifier->recipients($processo)->pluck('id'));
    }
}
