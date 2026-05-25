<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\Supplier;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * @OA\Tag(
 *     name="Registo Público (Email)",
 *     description="Endpoints acessíveis via link de convite enviado por email (Token)"
 * )
 */
class PublicRegistrationController extends Controller
{
    /**
     * Exibe o formulário público de registo para o fornecedor (Rota Web).
     */
    public function showForm($token)
    {
        $supplier = Supplier::where('registration_token', $token)->firstOrFail();

        if ($supplier->registration_status === 'registered') {
            return redirect('/supplier/register/' . $token . '/success');
        }

        $categories = Category::all();

        return view('supplier.register', [
            'supplier' => $supplier,
            'token' => $token,
            'categories' => $categories,
        ]);
    }

    /**
     * Exibe a página de sucesso após o registo do fornecedor.
     */
    public function success($token)
    {
        $supplier = Supplier::where('registration_token', $token)->firstOrFail();

        return view('supplier.success', [
            'supplier' => $supplier,
        ]);
    }

    /**
     * @OA\Post(
     *     path="/api/supplier/register/{token}",
     *     summary="Completar Registo de Fornecedor",
     *     description="Permite ao fornecedor preencher os dados e concluir o registo iniciado pelo convite.",
     *     tags={"Registo Público (Email)"},
     *     @OA\Parameter(name="token", in="path", required=true, @OA\Schema(type="string")),
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\MediaType(
     *             mediaType="multipart/form-data",
     *             @OA\Schema(
     *                 required={"legal_name", "commercial_name", "phone", "nif", "activity_type", "province", "municipality", "commercial_certificate", "nif_proof", "categories"},
     *                 @OA\Property(property="legal_name", type="string"),
     *                 @OA\Property(property="commercial_name", type="string"),
     *                 @OA\Property(property="phone", type="string"),
     *                 @OA\Property(property="nif", type="string"),
     *                 @OA\Property(property="activity_type", type="string"),
     *                 @OA\Property(property="province", type="string"),
     *                 @OA\Property(property="municipality", type="string"),
     *                 @OA\Property(property="address", type="string"),
     *                 @OA\Property(property="commercial_certificate", type="string", format="binary"),
     *                 @OA\Property(property="commercial_license", type="string", format="binary"),
     *                 @OA\Property(property="nif_proof", type="string", format="binary"),
     *                 @OA\Property(property="pacto_social", type="string", format="binary"),
     *                 @OA\Property(property="non_debtor_certificate", type="string", format="binary"),
     *                 @OA\Property(property="product_list", type="string", format="binary"),
     *                 @OA\Property(property="categories", type="array", @OA\Items(type="integer"))
     *             )
     *         )
     *     ),
     *     @OA\Response(response=200, description="Registo concluído com sucesso"),
     *     @OA\Response(response=422, description="Erro de validação"),
     *     @OA\Response(response=404, description="Token inválido")
     * )
     */
    public function register(Request $request, $token)
    {
        $supplier = Supplier::where('registration_token', $token)
            ->where('registration_status', 'invited')
            ->firstOrFail();

        if ($request->has('categories') && is_string($request->categories)) {
            $request->merge(['categories' => explode(',', $request->categories)]);
        }

        $validated = $request->validate([
            'legal_name' => 'required|string|max:255',
            'commercial_name' => 'required|string|max:255',
            'phone' => 'required|string|max:20',
            'nif' => 'required|string|unique:suppliers,nif,' . $supplier->id,
            'activity_type' => 'required|string',
            'province' => 'required|string',
            'municipality' => 'required|string',
            'address' => 'nullable|string',
            'categories' => 'required|array',
            'categories.*' => 'exists:categories,id',

            'commercial_certificate' => 'required|file|mimes:pdf,jpg,png|max:5120',
            'commercial_license' => 'nullable|file|mimes:pdf,jpg,png|max:5120',
            'nif_proof' => 'required|file|mimes:pdf,jpg,png|max:5120',
            'pacto_social' => 'nullable|file|mimes:pdf,jpg,png|max:5120',
            'non_debtor_certificate' => 'nullable|file|mimes:pdf,jpg,png|max:5120',
            'product_list' => 'nullable|file|mimes:pdf,jpg,png,xlsx,xls|max:5120',
        ]);

        $activityMap = [
            'Serviços' => 'service',
            'Comércio Geral' => 'commerce',
            'Comércio' => 'commerce',
            'Tecnologia' => 'service',
            'Construção' => 'service',
            'Consultoria' => 'service',
            'Transporte' => 'service',
            'Saúde' => 'service',
            'Outros' => 'service',
            'service' => 'service',
            'commerce' => 'commerce',
        ];

        $validated['activity_type'] = $activityMap[$validated['activity_type']] ?? 'service';

        $uploadPath = 'suppliers/documents';

        foreach (['commercial_certificate', 'commercial_license', 'nif_proof', 'pacto_social', 'non_debtor_certificate', 'product_list'] as $fileKey) {
            if ($request->hasFile($fileKey)) {
                $validated[$fileKey] = $request->file($fileKey)->store($uploadPath, 'public');
            }
        }

        $validated['registration_status'] = 'registered';
        $validated['registered_at'] = now();
        unset($validated['categories']);

        DB::transaction(function () use ($supplier, $validated, $request) {
            $supplier->update($validated);

            if ($request->has('categories')) {
                $supplier->categories()->sync($request->categories);
            }

            Log::info('Supplier registered via self-registration', [
                'supplier_id' => $supplier->id,
                'email' => $supplier->email,
            ]);
        });

        return redirect('/supplier/register/' . $token . '/success');
    }
}
