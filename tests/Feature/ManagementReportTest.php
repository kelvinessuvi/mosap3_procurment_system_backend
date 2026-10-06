<?php

namespace Tests\Feature;

use App\Models\Acquisition;
use App\Models\Menu;
use App\Models\QuotationRequest;
use App\Models\QuotationRequestAssignment;
use App\Models\QuotationResponse;
use App\Models\QuotationSupplier;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Relatório de Gestão de Pequenas Aquisições, conforme o documento oficial.
 */
class ManagementReportTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    private function technician(): User
    {
        $user = User::factory()->create(['role' => 'procurement_technician']);
        foreach (['acquisitions', 'quotation-requests'] as $slug) {
            $menu = Menu::firstOrCreate(['slug' => $slug], ['name' => $slug, 'order' => 1]);
            $user->menuPermissions()->attach($menu->id, ['level' => 'write']);
        }

        return $user;
    }

    /** @param  array<string,mixed>  $attrs */
    private function processo(User $dono, string $categoria, array $attrs = []): QuotationRequest
    {
        return QuotationRequest::factory()->create(array_merge([
            'user_id' => $dono->id,
            'procurement_category' => $categoria,
            'created_at' => now(),
        ], $attrs));
    }

    private function adjudicar(QuotationRequest $processo, float $valor, array $attrs = []): Acquisition
    {
        $fornecedor = Supplier::factory()->create();
        $convite = QuotationSupplier::factory()->create([
            'quotation_request_id' => $processo->id,
            'supplier_id' => $fornecedor->id,
        ]);
        $resposta = QuotationResponse::factory()->approved()->create([
            'quotation_supplier_id' => $convite->id,
        ]);

        return Acquisition::factory()->create(array_merge([
            'quotation_request_id' => $processo->id,
            'quotation_response_id' => $resposta->id,
            'supplier_id' => $fornecedor->id,
            'total_amount' => $valor,
        ], $attrs));
    }

    public function test_resumo_executivo_tem_as_quatro_categorias_do_documento(): void
    {
        $admin = $this->admin();

        $resumo = $this->actingAs($admin, 'sanctum')
            ->getJson('/api/reports/management')->assertStatus(200)->json('summary.by_category');

        $this->assertSame(
            ['bens', 'consultoria', 'nao_consultoria', 'obras'],
            collect($resumo)->pluck('category')->all()
        );
        $this->assertSame(
            ['Bens', 'Serviços de Consultoria', 'Serviços de Não Consultoria', 'Obras'],
            collect($resumo)->pluck('label')->all()
        );
    }

    public function test_contagens_e_valor_por_categoria(): void
    {
        $admin = $this->admin();

        $bens = $this->processo($admin, 'bens', ['status' => 'completed']);
        $this->adjudicar($bens, 1000.0, ['status' => 'completed']);

        $obras = $this->processo($admin, 'obras', ['status' => 'in_progress']);
        $this->adjudicar($obras, 2500.0, ['status' => 'in_progress']);

        $json = $this->actingAs($admin, 'sanctum')->getJson('/api/reports/management')->json();
        $linhas = collect($json['summary']['by_category'])->keyBy('category');

        $this->assertSame(1, $linhas['bens']['completed']);
        $this->assertSame(1000.0, (float) $linhas['bens']['total_amount']);
        $this->assertSame(1, $linhas['obras']['in_progress']);
        $this->assertSame(2500.0, (float) $linhas['obras']['total_amount']);
        $this->assertSame(3500.0, (float) $json['summary']['total_amount']);
        $this->assertSame(2, $json['summary']['total_processes']);
    }

    /** Em atraso = entrega prevista passou e o processo não terminou. */
    public function test_processos_em_atraso_sao_contados(): void
    {
        $admin = $this->admin();

        $atrasado = $this->processo($admin, 'bens', ['status' => 'in_progress']);
        $this->adjudicar($atrasado, 500.0, [
            'status' => 'in_progress',
            'expected_delivery_date' => now()->subDays(10),
        ]);

        $aPrazo = $this->processo($admin, 'bens', ['status' => 'in_progress']);
        $this->adjudicar($aPrazo, 500.0, [
            'status' => 'in_progress',
            'expected_delivery_date' => now()->addDays(10),
        ]);

        $concluidoForaDePrazo = $this->processo($admin, 'bens', ['status' => 'completed']);
        $this->adjudicar($concluidoForaDePrazo, 500.0, [
            'status' => 'completed',
            'expected_delivery_date' => now()->subDays(10),
        ]);

        $json = $this->actingAs($admin, 'sanctum')->getJson('/api/reports/management')->json();
        $bens = collect($json['summary']['by_category'])->firstWhere('category', 'bens');

        $this->assertSame(1, $bens['late'], 'só o que passou do prazo e não terminou conta');
        $this->assertSame(1, $json['performance']['attention_points']['late_processes']);
    }

    public function test_detalhe_tem_as_colunas_especificas_de_cada_categoria(): void
    {
        $admin = $this->admin();
        $cats = $this->actingAs($admin, 'sanctum')->getJson('/api/reports/management')->json('categories');

        $this->assertArrayHasKey('execution_period', $cats['consultoria']['columns']);
        $this->assertSame('Período de Execução', $cats['consultoria']['columns']['execution_period']);
        $this->assertArrayHasKey('work_location', $cats['obras']['columns']);
        $this->assertSame('Local da Obra', $cats['obras']['columns']['work_location']);
        $this->assertSame('Data de Aprovação', $cats['bens']['columns']['approved_at']);
        $this->assertSame('Data do Contrato / Ordem', $cats['nao_consultoria']['columns']['approved_at']);

        foreach (['bens', 'consultoria', 'nao_consultoria', 'obras'] as $c) {
            $this->assertNotEmpty($cats[$c]['description'], "falta a descrição de {$c}");
        }
    }

    public function test_linha_de_detalhe_traz_codigo_fornecedor_valor_e_estado(): void
    {
        $admin = $this->admin();
        $obra = $this->processo($admin, 'obras', [
            'status' => 'in_progress',
            'work_location' => 'Armazém Central, Luanda',
        ]);
        $aquisicao = $this->adjudicar($obra, 7500.0);

        $linha = $this->actingAs($admin, 'sanctum')
            ->getJson('/api/reports/management')->json('categories.obras.rows.0');

        $this->assertSame('PROC-OBR-'.str_pad((string) $obra->id, 3, '0', STR_PAD_LEFT), $linha['code']);
        $this->assertSame('Armazém Central, Luanda', $linha['work_location']);
        $this->assertSame(7500.0, (float) $linha['total_amount']);
        $this->assertSame($aquisicao->supplier->company_name, $linha['supplier']);
        $this->assertSame($aquisicao->created_at->toDateString(), $linha['approved_at']);
        $this->assertSame('Em Execução', $linha['status_label']);
    }

    public function test_periodo_escolhido_por_calendario(): void
    {
        $admin = $this->admin();
        $this->processo($admin, 'bens', ['created_at' => '2026-04-15 10:00:00']);
        $this->processo($admin, 'obras', ['created_at' => '2026-07-15 10:00:00']);

        $abril = $this->actingAs($admin, 'sanctum')
            ->getJson('/api/reports/management?period=monthly&year=2026&month=4')->assertStatus(200)->json();
        $this->assertSame(1, $abril['summary']['total_processes']);
        $this->assertSame('2026-04-01', $abril['report']['period']['start']);
        $this->assertSame('2026-04-30', $abril['report']['period']['end']);

        $ano = $this->actingAs($admin, 'sanctum')
            ->getJson('/api/reports/management?period=yearly&year=2026')->json();
        $this->assertSame(2, $ano['summary']['total_processes']);
        $this->assertSame('2026', $ano['report']['period']['label']);

        $semana = $this->actingAs($admin, 'sanctum')
            ->getJson('/api/reports/management?period=weekly&year=2026&week=16')->json();
        $this->assertStringContainsString('Semana 16/2026', $semana['report']['period']['label']);
    }

    public function test_periodo_invalido_da_422(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/reports/management?period=monthly&year=2026&month=13')->assertStatus(422);
        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/reports/management?period=weekly&year=2026&week=60')->assertStatus(422);
    }

    /** O documento exige selecção de vários estados em simultâneo. */
    public function test_filtro_por_varios_estados(): void
    {
        $admin = $this->admin();
        $this->processo($admin, 'bens', ['status' => 'completed']);
        $this->processo($admin, 'bens', ['status' => 'cancelled']);
        $this->processo($admin, 'bens', ['status' => 'in_progress']);

        $json = $this->actingAs($admin, 'sanctum')
            ->getJson('/api/reports/management?status[]=completed&status[]=cancelled')
            ->assertStatus(200)->json();

        $this->assertSame(2, $json['summary']['total_processes']);
        $this->assertSame(['completed', 'cancelled'], $json['report']['filters']['statuses']);
    }

    /** "atrasado" não é um estado guardado: é uma condição sobre as datas. */
    public function test_filtro_por_atrasado(): void
    {
        $admin = $this->admin();
        $atrasado = $this->processo($admin, 'bens', ['status' => 'in_progress']);
        $this->adjudicar($atrasado, 100.0, [
            'status' => 'in_progress',
            'expected_delivery_date' => now()->subDays(5),
        ]);
        $this->processo($admin, 'bens', ['status' => 'in_progress']);

        $json = $this->actingAs($admin, 'sanctum')
            ->getJson('/api/reports/management?status[]=atrasado')->assertStatus(200)->json();

        $this->assertSame(1, $json['summary']['total_processes']);
    }

    public function test_processos_antigos_aparecem_em_sem_categoria(): void
    {
        $admin = $this->admin();
        $this->processo($admin, 'bens');
        QuotationRequest::factory()->create(['user_id' => $admin->id, 'procurement_category' => null]);

        $json = $this->actingAs($admin, 'sanctum')->getJson('/api/reports/management')->json();

        $this->assertSame('Sem categoria', collect($json['summary']['by_category'])->last()['label']);
        $this->assertArrayHasKey('sem_categoria', $json['categories']);
        $this->assertSame(1, $json['performance']['attention_points']['unclassified_processes']);
    }

    /** A secção 3 lista processos individuais: tem de respeitar a visibilidade. */
    public function test_relatorio_respeita_a_visibilidade_dos_processos(): void
    {
        $tecnico = $this->technician();
        $meu = $this->processo($tecnico, 'bens');
        $this->adjudicar($meu, 100.0);

        $alheio = $this->processo($outro = $this->technician(), 'bens');
        $this->adjudicar($alheio, 9999.0);

        $json = $this->actingAs($tecnico, 'sanctum')->getJson('/api/reports/management')->json();
        $this->assertSame(1, $json['summary']['total_processes']);
        $this->assertSame(100.0, (float) $json['summary']['total_amount']);

        $json = $this->actingAs($this->admin(), 'sanctum')->getJson('/api/reports/management')->json();
        $this->assertSame(2, $json['summary']['total_processes']);
    }

    public function test_atribuido_ve_o_processo_no_relatorio(): void
    {
        $tecnico = $this->technician();
        $alheio = $this->processo($criador = $this->technician(), 'obras');
        $this->adjudicar($alheio, 4200.0);

        QuotationRequestAssignment::create([
            'quotation_request_id' => $alheio->id,
            'user_id' => $tecnico->id,
            'status' => QuotationRequestAssignment::ACTIVE,
            'requested_by' => $criador->id,
        ]);

        $json = $this->actingAs($tecnico, 'sanctum')->getJson('/api/reports/management')->json();
        $this->assertSame(4200.0, (float) $json['summary']['total_amount']);
    }

    public function test_analise_de_desempenho(): void
    {
        $admin = $this->admin();
        $processo = $this->processo($admin, 'bens', [
            'created_at' => now()->subDays(10),
            'attachments' => ['docs/a.pdf'],
        ]);
        $this->adjudicar($processo, 100.0, [
            'created_at' => now()->subDays(6),
            'expected_delivery_date' => now()->subDays(3),
            'actual_delivery_date' => now()->subDay(),
            'status' => 'completed',
        ]);

        $intervalo = 'start_date='.now()->subDays(30)->toDateString().'&end_date='.now()->toDateString();
        $perf = $this->actingAs($admin, 'sanctum')
            ->getJson("/api/reports/management?{$intervalo}")->json('performance');

        $this->assertSame(4.0, (float) $perf['deadlines']['avg_days_to_approval']);
        $this->assertSame(2.0, (float) $perf['deadlines']['avg_delivery_deviation_days']);
        $this->assertSame(1, $perf['deadlines']['delivered_late']);
        $this->assertSame(100.0, (float) $perf['traceability']['attachment_coverage_pct']);
    }

    public function test_categoria_e_obrigatoria_ao_criar_processo(): void
    {
        $admin = $this->admin();
        $fornecedores = Supplier::factory()->count(1)->create()->pluck('id')->toArray();

        $this->actingAs($admin, 'sanctum')->postJson('/api/quotation-requests', [
            'title' => 'Sem categoria',
            'deadline' => now()->addDays(7)->toIso8601String(),
            'suppliers' => $fornecedores,
        ])->assertStatus(422)->assertJsonValidationErrors('procurement_category');

        $this->actingAs($admin, 'sanctum')->postJson('/api/quotation-requests', [
            'title' => 'Com categoria',
            'procurement_category' => 'consultoria',
            'deadline' => now()->addDays(7)->toIso8601String(),
            'suppliers' => $fornecedores,
        ])->assertStatus(201);
    }

    public function test_classificar_processo_antigo_em_qualquer_estado(): void
    {
        $tecnico = $this->technician();
        $antigo = QuotationRequest::factory()->create([
            'user_id' => $tecnico->id,
            'procurement_category' => null,
            'status' => 'completed',   // a edição normal só permite rascunhos
        ]);

        $this->actingAs($tecnico, 'sanctum')
            ->putJson("/api/quotation-requests/{$antigo->id}/classification", [
                'procurement_category' => 'obras',
                'work_location' => 'Huambo',
            ])->assertStatus(200);

        $this->assertDatabaseHas('quotation_requests', [
            'id' => $antigo->id,
            'procurement_category' => 'obras',
            'work_location' => 'Huambo',
        ]);
    }

    public function test_classificar_processo_alheio_da_403(): void
    {
        $tecnico = $this->technician();
        $alheio = QuotationRequest::factory()->create([
            'user_id' => $this->technician()->id,
            'procurement_category' => null,
        ]);

        $this->actingAs($tecnico, 'sanctum')
            ->putJson("/api/quotation-requests/{$alheio->id}/classification", [
                'procurement_category' => 'bens',
            ])->assertStatus(403);
    }

    public function test_endpoint_de_categorias_para_o_selector(): void
    {
        $json = $this->actingAs($this->admin(), 'sanctum')
            ->getJson('/api/reports/categories')->assertStatus(200)->json();

        $this->assertCount(4, $json);
        $this->assertSame('bens', $json[0]['value']);
        $this->assertNotEmpty($json[0]['description']);
    }
}
