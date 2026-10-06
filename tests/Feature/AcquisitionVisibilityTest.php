<?php

namespace Tests\Feature;

use App\Models\Acquisition;
use App\Models\Menu;
use App\Models\QuotationItem;
use App\Models\QuotationRequest;
use App\Models\QuotationRequestAssignment;
use App\Models\QuotationResponse;
use App\Models\QuotationResponseItem;
use App\Models\QuotationSupplier;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Aquisições e agregados herdam a visibilidade do processo que lhes deu origem.
 */
class AcquisitionVisibilityTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    private function technician(): User
    {
        $user = User::factory()->create(['role' => 'procurement_technician']);

        foreach (['acquisitions', 'products', 'documents'] as $slug) {
            $menu = Menu::firstOrCreate(['slug' => $slug], ['name' => $slug, 'order' => 1]);
            $user->menuPermissions()->attach($menu->id, ['level' => 'write']);
        }

        return $user;
    }

    /** Monta processo -> convite -> proposta -> aquisição, com preço. */
    private function scenario(
        User $creator,
        ?User $approver = null,
        float $price = 500.0,
        string $itemName = 'Artigo'
    ): array {
        $supplier = Supplier::factory()->create();
        $request = QuotationRequest::factory()->create(['user_id' => $creator->id]);
        $item = QuotationItem::factory()->create([
            'quotation_request_id' => $request->id,
            'name' => $itemName,
            'quantity' => 1,
        ]);
        $invite = QuotationSupplier::factory()->create([
            'quotation_request_id' => $request->id,
            'supplier_id' => $supplier->id,
        ]);
        $response = QuotationResponse::factory()->approved()->create([
            'quotation_supplier_id' => $invite->id,
        ]);
        QuotationResponseItem::create([
            'quotation_response_id' => $response->id,
            'quotation_item_id' => $item->id,
            'unit_price' => $price,
            'total_price' => $price * $item->quantity,
        ]);
        $acquisition = Acquisition::factory()->create([
            'quotation_request_id' => $request->id,
            'quotation_response_id' => $response->id,
            'supplier_id' => $supplier->id,
            'user_id' => ($approver ?? $this->admin())->id,
        ]);

        return compact('request', 'item', 'invite', 'response', 'acquisition', 'supplier');
    }

    public function test_tecnico_ve_aquisicao_do_processo_dele_mesmo_aprovada_por_admin(): void
    {
        $tecnico = $this->technician();
        $meu = $this->scenario($tecnico, $this->admin());

        $ids = collect($this->actingAs($tecnico, 'sanctum')
            ->getJson('/api/acquisitions')->assertStatus(200)->json('data'))->pluck('id');

        $this->assertContains($meu['acquisition']->id, $ids);
    }

    /**
     * O caso que a coluna enganadora cria: acquisitions.user_id é o APROVADOR.
     * Uma aquisição aprovada pelo técnico, de um processo alheio, não é dele.
     */
    public function test_aprovar_uma_aquisicao_nao_da_visibilidade_do_processo(): void
    {
        $tecnico = $this->technician();
        $alheio = $this->scenario($this->technician(), $tecnico);

        $this->assertSame($tecnico->id, $alheio['acquisition']->user_id);

        $ids = collect($this->actingAs($tecnico, 'sanctum')
            ->getJson('/api/acquisitions')->assertStatus(200)->json('data'))->pluck('id');

        $this->assertNotContains($alheio['acquisition']->id, $ids);
    }

    public function test_atribuicao_activa_da_acesso_as_aquisicoes_do_processo(): void
    {
        $tecnico = $this->technician();
        $alheio = $this->scenario($criador = $this->technician());

        QuotationRequestAssignment::create([
            'quotation_request_id' => $alheio['request']->id,
            'user_id' => $tecnico->id,
            'status' => QuotationRequestAssignment::ACTIVE,
            'requested_by' => $criador->id,
        ]);

        $ids = collect($this->actingAs($tecnico, 'sanctum')
            ->getJson('/api/acquisitions')->json('data'))->pluck('id');

        $this->assertContains($alheio['acquisition']->id, $ids);
    }

    public function test_accoes_em_aquisicao_alheia_dao_403(): void
    {
        $tecnico = $this->technician();
        $alheio = $this->scenario($this->technician());
        $id = $alheio['acquisition']->id;

        $this->actingAs($tecnico, 'sanctum')
            ->postJson("/api/acquisitions/{$id}/confirm-delivery")->assertStatus(403);
        $this->actingAs($tecnico, 'sanctum')
            ->deleteJson("/api/acquisitions/{$id}", ['reason' => 'x'])->assertStatus(403);
    }

    public function test_historico_do_fornecedor_filtrado(): void
    {
        $tecnico = $this->technician();
        $meu = $this->scenario($tecnico);
        // mesmo fornecedor, processo de outro técnico
        $alheio = $this->scenario($this->technician());
        Acquisition::whereKey($alheio['acquisition']->id)
            ->update(['supplier_id' => $meu['supplier']->id]);

        $ids = collect($this->actingAs($tecnico, 'sanctum')
            ->getJson("/api/suppliers/{$meu['supplier']->id}/acquisitions")
            ->assertStatus(200)->json('data'))->pluck('id');

        $this->assertContains($meu['acquisition']->id, $ids);
        $this->assertNotContains($alheio['acquisition']->id, $ids);
    }

    /** Cobre a query crua de agregação, que não passa por scopes Eloquent. */
    public function test_estatisticas_de_produtos_excluem_processos_alheios(): void
    {
        $tecnico = $this->technician();
        $this->scenario($tecnico, null, 100.0, 'ARTIGO-MEU');
        $this->scenario($this->technician(), null, 999.0, 'ARTIGO-ALHEIO');

        $nomes = collect($this->actingAs($tecnico, 'sanctum')
            ->getJson('/api/acquisitions/stats/products')->assertStatus(200)->json())
            ->pluck('product_name');

        $this->assertContains('ARTIGO-MEU', $nomes);
        $this->assertNotContains('ARTIGO-ALHEIO', $nomes);
    }

    public function test_admin_ve_tudo_nas_aquisicoes_e_nos_agregados(): void
    {
        $a = $this->scenario($this->technician(), null, 100.0, 'ARTIGO-A');
        $b = $this->scenario($this->technician(), null, 999.0, 'ARTIGO-B');

        $ids = collect($this->actingAs($admin = $this->admin(), 'sanctum')
            ->getJson('/api/acquisitions')->json('data'))->pluck('id');

        $this->assertContains($a['acquisition']->id, $ids);
        $this->assertContains($b['acquisition']->id, $ids);

        $nomes = collect($this->actingAs($admin, 'sanctum')
            ->getJson('/api/acquisitions/stats/products')->json())->pluck('product_name');
        $this->assertContains('ARTIGO-A', $nomes);
        $this->assertContains('ARTIGO-B', $nomes);
    }

    public function test_documento_da_proposta_de_processo_alheio_da_403(): void
    {
        $tecnico = $this->technician();
        $alheio = $this->scenario($this->technician());

        $this->actingAs($tecnico, 'sanctum')
            ->getJson("/api/quotation-responses/{$alheio['response']->id}/document")
            ->assertStatus(403);
    }

    /** Documentos de fornecedor não são dados do processo: não devem regredir. */
    public function test_documentos_do_fornecedor_continuam_acessiveis(): void
    {
        $tecnico = $this->technician();
        $supplier = Supplier::factory()->create();

        // 404 (sem ficheiro) e não 403: o middleware ignora parâmetros que não
        // sejam processos de aquisição.
        $this->actingAs($tecnico, 'sanctum')
            ->getJson("/api/suppliers/{$supplier->id}/documents/nif_proof")
            ->assertStatus(404);
    }
}
