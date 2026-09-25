<?php

namespace Tests\Feature;

use App\Models\QuotationItem;
use App\Models\QuotationRequest;
use App\Models\QuotationResponse;
use App\Models\QuotationSupplier;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Termos de pagamento fora do formulário; dias de entrega não editáveis.
 */
class QuotationProposalFieldsTest extends TestCase
{
    use RefreshDatabase;

    private function cenario(): QuotationSupplier
    {
        $user = User::factory()->create(['role' => 'admin']);

        $supplier = Supplier::create([
            'company_name' => 'Fornecedor Teste',
            'email' => 'fornecedor@teste.test',
            'phone' => '900000000',
            'nif' => '5000000000',
            'province' => 'Luanda',
            'municipality' => 'Luanda',
            'is_active' => true,
            'user_id' => $user->id,
            'registration_status' => 'registered',
        ]);

        $request = QuotationRequest::create([
            'title' => 'Compra de portáteis',
            'deadline' => now()->addDays(10),
            'status' => 'in_progress',
            'user_id' => $user->id,
        ]);

        QuotationItem::create([
            'quotation_request_id' => $request->id,
            'name' => 'Portátil',
            'quantity' => 5,
            'unit' => 'un',
        ]);

        return QuotationSupplier::create([
            'quotation_request_id' => $request->id,
            'supplier_id' => $supplier->id,
            'token' => Str::random(40),
            'status' => 'sent',
        ]);
    }

    public function test_proposta_e_aceite_sem_termos_de_pagamento(): void
    {
        $qs = $this->cenario();

        $response = $this->postJson("/api/quotation/{$qs->token}/submit", [
            'delivery_date' => now()->addDays(5)->toIso8601String(),
        ]);

        $response->assertStatus(201);
        $this->assertNull(QuotationResponse::latest('id')->first()->payment_terms);
    }

    public function test_termos_de_pagamento_ainda_sao_gravados_se_enviados(): void
    {
        $qs = $this->cenario();

        $this->postJson("/api/quotation/{$qs->token}/submit", [
            'delivery_date' => now()->addDays(5)->toIso8601String(),
            'payment_terms' => '30 dias',
        ])->assertStatus(201);

        $this->assertSame('30 dias', QuotationResponse::latest('id')->first()->payment_terms);
    }

    public function test_dias_de_entrega_sao_calculados_a_partir_da_data(): void
    {
        $qs = $this->cenario();

        $this->postJson("/api/quotation/{$qs->token}/submit", [
            'delivery_date' => now()->addDays(7)->toIso8601String(),
        ])->assertStatus(201);

        $this->assertSame(7, QuotationResponse::latest('id')->first()->delivery_days);
    }

    public function test_dias_de_entrega_enviados_pelo_cliente_sao_ignorados(): void
    {
        $qs = $this->cenario();

        $this->postJson("/api/quotation/{$qs->token}/submit", [
            'delivery_date' => now()->addDays(7)->toIso8601String(),
            'delivery_days' => 999,
            'deliveryDays' => 888,
        ])->assertStatus(201);

        // Vale a data, não o que o cliente disser.
        $this->assertSame(7, QuotationResponse::latest('id')->first()->delivery_days);
    }

    public function test_formulario_publico_nao_tem_os_campos_removidos(): void
    {
        $qs = $this->cenario();

        $html = $this->get("/quotation/{$qs->token}")->assertStatus(200)->getContent();

        $this->assertStringNotContainsString('name="payment_terms"', $html);
        $this->assertStringNotContainsString('Termos de Pagamento', $html);

        // Os dias aparecem, mas como campo só-leitura e fora do payload do formulário.
        $this->assertStringNotContainsString('name="delivery_days"', $html);
        $this->assertStringContainsString('id="delivery_days_display"', $html);
        $this->assertStringContainsString('readonly', $html);
    }
}
