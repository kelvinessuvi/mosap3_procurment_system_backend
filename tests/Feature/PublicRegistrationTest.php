<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PublicRegistrationTest extends TestCase
{
    use RefreshDatabase;

    private function invitedSupplier(): Supplier
    {
        $user = User::factory()->create();
        $supplier = Supplier::factory()->create([
            'user_id' => $user->id,
            'registration_status' => 'invited',
            'is_active' => false,
            'activity_type' => 'service',
        ]);
        $supplier->categories()->attach(Category::factory()->create()->id);

        return $supplier;
    }

    private function payload(Supplier $supplier, array $overrides = []): array
    {
        return array_merge([
            'company_name' => 'Empresa Nova Lda',
            'phone' => '923000000',
            'nif' => '500000000',
            'province' => 'Luanda',
            'municipality' => 'Belas',
            'address' => 'Rua X',
            'categories' => $supplier->categories->pluck('id')->all(),
            'commercial_certificate' => UploadedFile::fake()->create('cert.pdf', 100),
            'nif_proof' => UploadedFile::fake()->create('nif.pdf', 100),
        ], $overrides);
    }

    public function test_registration_without_activity_type_stores_blank()
    {
        Storage::fake('public');
        $supplier = $this->invitedSupplier();

        $response = $this->post('/api/supplier/register/' . $supplier->registration_token, $this->payload($supplier));

        $response->assertStatus(302);
        $supplier->refresh();
        $this->assertNull($supplier->activity_type);
        $this->assertEquals('registered', $supplier->registration_status);
    }

    public function test_registration_still_accepts_activity_type_when_sent()
    {
        Storage::fake('public');
        $supplier = $this->invitedSupplier();

        $this->post('/api/supplier/register/' . $supplier->registration_token, $this->payload($supplier, [
            'activity_type' => 'commerce',
        ]))->assertStatus(302);

        $supplier->refresh();
        $this->assertEquals('commerce', $supplier->activity_type);
    }

    public function test_registration_form_renders_province_and_municipality_selects()
    {
        Http::fake([
            'angolaprovinciasapi.ggwp.com.br/*' => Http::response([
                'success' => true,
                'code' => 200,
                'data' => [
                    ['nome' => 'Luanda', 'municipios' => [['nome' => 'Belas'], ['nome' => 'Cacuaco']]],
                    ['nome' => 'Huíla', 'municipios' => [['nome' => 'Lubango'], ['nome' => 'Caconda']]],
                ],
            ]),
        ]);

        $supplier = $this->invitedSupplier();

        $response = $this->get('/supplier/register/' . $supplier->registration_token);

        $response->assertStatus(200);
        $response->assertSee('<select name="province" id="province" required', false);
        $response->assertSee('<select name="municipality" id="municipality" required disabled', false);
        $response->assertSee('Luanda', false);
        $response->assertSee('Huíla', false);
        $response->assertViewHas('provinces', function (array $provinces) {
            return count($provinces) === 2
                && $provinces[0]['nome'] === 'Luanda'
                && $provinces[0]['municipios'] === ['Belas', 'Cacuaco'];
        });
    }

    public function test_registration_form_falls_back_to_text_inputs_when_api_is_down()
    {
        Http::fake([
            'angolaprovinciasapi.ggwp.com.br/*' => Http::response([], 500),
        ]);

        $supplier = $this->invitedSupplier();

        $response = $this->get('/supplier/register/' . $supplier->registration_token);

        $response->assertStatus(200);
        $response->assertSee('<input type="text" name="province"', false);
        $response->assertSee('<input type="text" name="municipality"', false);
        $response->assertViewHas('provinces', []);
    }
}
