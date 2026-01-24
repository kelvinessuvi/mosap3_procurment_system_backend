<?php

namespace App\Http\Controllers;

use App\Models\Supplier;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

/**
 * @OA\Tag(
 *     name="Gestão de Fornecedores",
 *     description="Gestão de Fornecedores (Admin e Técnicos)"
 * )
 */
class SupplierController extends Controller
{
    /**
     * @OA\Get(
     *     path="/api/suppliers",
     *     summary="Listar fornecedores",
     *     description="Retorna uma lista paginada de fornecedores com filtros opcionais.",
     *     tags={"Fornecedores"},
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(name="search", in="query", description="Busca por nome ou NIF", required=false, @OA\Schema(type="string")),
     *     @OA\Parameter(name="activity_type", in="query", description="Filtro por tipo de atividade", required=false, @OA\Schema(type="string")),
     *     @OA\Response(
     *         response=200,
     *         description="Lista recuperada com sucesso",
     *         @OA\JsonContent(type="object",
     *             @OA\Property(property="data", type="array", @OA\Items(
     *                 @OA\Property(property="id", type="integer"),
     *                 @OA\Property(property="commercial_name", type="string"),
     *                 @OA\Property(property="nif", type="string"),
     *                 @OA\Property(property="email", type="string"),
     *                 @OA\Property(property="is_active", type="boolean")
     *             ))
     *         )
     *     )
     * )
     */
    public function index(Request $request)
    {
        // ...
        $query = Supplier::query()->with(['categories', 'user']);

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function($q) use ($search) {
                $q->where('legal_name', 'like', "%{$search}%")
                  ->orWhere('commercial_name', 'like', "%{$search}%")
                  ->orWhere('nif', 'like', "%{$search}%");
            });
        }

        if ($request->filled('activity_type')) {
            $query->where('activity_type', $request->activity_type);
        }

        if ($request->filled('province')) {
            $query->where('province', $request->province);
        }

        if ($request->filled('is_active')) {
            $query->where('is_active', $request->boolean('is_active'));
        }

        if ($request->filled('category_id')) {
            $query->whereHas('categories', function($q) use ($request) {
                $q->where('categories.id', $request->category_id);
            });
        }

        return response()->json($query->paginate($request->input('per_page', 15)));
    }

    /**
     * Store a newly created resource in storage.
     */
    /**
     * @OA\Post(
     *     path="/api/suppliers",
     *     summary="Criar novo fornecedor",
     *     tags={"Fornecedores"},
     *     security={{"bearerAuth":{}}},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\MediaType(
     *             mediaType="multipart/form-data",
     *             @OA\Schema(
     *                 required={"legal_name", "commercial_name", "email", "phone", "nif", "activity_type", "province", "municipality", "address"},
     *                 @OA\Property(property="legal_name", type="string", example="Empresa Exemplo SA"),
     *                 @OA\Property(property="commercial_name", type="string", example="Exemplo Comercial"),
     *                 @OA\Property(property="email", type="string", format="email", example="contato@exemplo.ao"),
     *                 @OA\Property(property="phone", type="string", example="+244923456789"),
     *                 @OA\Property(property="nif", type="string", example="5001234567"),
     *                 @OA\Property(property="activity_type", type="string", enum={"Serviços", "Comércio Geral", "Tecnologia", "Construção", "Consultoria", "Transporte", "Saúde", "Outros"}),
     *                 @OA\Property(property="province", type="string", example="Luanda"),
     *                 @OA\Property(property="municipality", type="string", example="Belas"),
     *                 @OA\Property(property="address", type="string", example="Rua das Acácias, 12"),
     *                 @OA\Property(property="commercial_certificate", type="string", format="binary", description="Certificado Comercial (PDF/IMG)"),
     *                 @OA\Property(property="commercial_license", type="string", format="binary", description="Alvará (PDF/IMG)"),
     *                 @OA\Property(property="nif_proof", type="string", format="binary", description="Comprovativo NIF (PDF/IMG)"),
     *                 @OA\Property(property="categories", type="array", @OA\Items(type="integer"), description="IDs das categorias")
     *             )
     *         )
     *     ),
     *     @OA\Response(response=201, description="Fornecedor criado com sucesso"),
     *     @OA\Response(response=422, description="Erro de validação")
     * )
     */
    public function store(Request $request)
    {
        if ($request->has('categories') && is_string($request->categories)) {
             $request->merge(['categories' => explode(',', $request->categories)]);
        }

        $validated = $request->validate([
            'legal_name' => 'required|string|max:255',
            'commercial_name' => 'required|string|max:255',
            'email' => 'required|email|unique:suppliers',
            'phone' => 'required|string|max:20',
            'nif' => 'required|string|unique:suppliers',
            // Update to match Swagger enums or accept both. Let's make it flexible.
            'activity_type' => 'required|string', 
            'province' => 'required|string',
            'municipality' => 'required|string',
            'address' => 'nullable|string',
            'categories' => 'required|array',
            'categories.*' => 'exists:categories,id',
            
            // Documents
            'commercial_certificate' => 'required|file|mimes:pdf,jpg,png|max:5120', 
            'commercial_license' => 'nullable|file|mimes:pdf,jpg,png|max:5120',
            'nif_proof' => 'required|file|mimes:pdf,jpg,png|max:5120',
        ]);

        // Handle file uploads
        $uploadPath = 'suppliers/documents';
        
        if ($request->hasFile('commercial_certificate')) {
            $validated['commercial_certificate'] = $request->file('commercial_certificate')->store($uploadPath, 'public');
        }
        if ($request->hasFile('commercial_license')) {
            $validated['commercial_license'] = $request->file('commercial_license')->store($uploadPath, 'public');
        }
        if ($request->hasFile('nif_proof')) {
            $validated['nif_proof'] = $request->file('nif_proof')->store($uploadPath, 'public');
        }

        // Map activity_type to DB enum
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

        $validated['user_id'] = $request->user()->id;
        $validated['is_active'] = true;

        $supplier = Supplier::create($validated);
        
        $supplier->categories()->sync($validated['categories']);

        return response()->json($supplier->load('categories'), 201);
    }

    /**
     * Display the specified resource.
     */
    public function show(Supplier $supplier)
    {
        return response()->json($supplier->load(['categories', 'user']));
    }

    /**
     * @OA\Get(
     *     path="/api/suppliers/{id}/classification",
     *     summary="Obter Classificação do Fornecedor",
     *     description="Retorna a classificação (overall_score) e métricas de desempenho do fornecedor",
     *     tags={"Fornecedores"},
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")),
     *     @OA\Response(
     *         response=200,
     *         description="Classificação recuperada com sucesso",
     *         @OA\JsonContent(
     *             @OA\Property(property="supplier_id", type="integer"),
     *             @OA\Property(property="commercial_name", type="string"),
     *             @OA\Property(property="overall_score", type="number", format="float", example=85.5),
     *             @OA\Property(property="success_rate", type="number", format="float"),
     *             @OA\Property(property="response_rate", type="number", format="float"),
     *             @OA\Property(property="acquisition_rate", type="number", format="float"),
     *             @OA\Property(property="total_approved", type="integer"),
     *             @OA\Property(property="total_rejected", type="integer"),
     *             @OA\Property(property="total_acquisitions", type="integer")
     *         )
     *     ),
     *     @OA\Response(response=404, description="Fornecedor ou avaliação não encontrada")
     * )
     */
    public function classification(Supplier $supplier)
    {
        $evaluation = $supplier->evaluation;
        
        if (!$evaluation) {
            return response()->json([
                'supplier_id' => $supplier->id,
                'commercial_name' => $supplier->commercial_name,
                'overall_score' => 0,
                'message' => 'Nenhuma avaliação disponível ainda'
            ], 200);
        }

        return response()->json([
            'supplier_id' => $supplier->id,
            'commercial_name' => $supplier->commercial_name,
            'overall_score' => round($evaluation->overall_score, 2),
            'success_rate' => round($evaluation->success_rate, 2),
            'response_rate' => round($evaluation->response_rate, 2),
            'acquisition_rate' => round($evaluation->acquisition_rate, 2),
            'total_quotations' => $evaluation->total_quotations,
            'total_responses' => $evaluation->total_responses,
            'total_approved' => $evaluation->total_approved,
            'total_rejected' => $evaluation->total_rejected,
            'total_acquisitions' => $evaluation->total_acquisitions,
            'total_revisions_requested' => $evaluation->total_revisions_requested,
            'avg_response_time_hours' => $evaluation->avg_response_time_hours,
        ]);
    }

    /**
     * @OA\Put(
     *     path="/api/suppliers/{id}",
     *     summary="Atualizar fornecedor",
     *     tags={"Fornecedores"},
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")),
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\MediaType(
     *             mediaType="multipart/form-data",
     *             @OA\Schema(
     *                 @OA\Property(property="legal_name", type="string"),
     *                 @OA\Property(property="commercial_name", type="string"),
     *                 @OA\Property(property="email", type="string", format="email"),
     *                 @OA\Property(property="phone", type="string"),
     *                 @OA\Property(property="nif", type="string"),
     *                 @OA\Property(property="activity_type", type="string", enum={"Serviços", "Comércio Geral"}),
     *                 @OA\Property(property="province", type="string"),
     *                 @OA\Property(property="municipality", type="string"),
     *                 @OA\Property(property="address", type="string"),
     *                 @OA\Property(property="is_active", type="boolean"),
     *                 @OA\Property(property="commercial_certificate", type="string", format="binary"),
     *                 @OA\Property(property="commercial_license", type="string", format="binary"),
     *                 @OA\Property(property="nif_proof", type="string", format="binary"),
     *                 @OA\Property(property="categories", type="array", @OA\Items(type="integer")),
     *                 @OA\Property(property="_method", type="string", example="PUT", description="Necessário para upload de arquivos em PUT")
     *             )
     *         )
     *     ),
     *     @OA\Response(response=200, description="Fornecedor atualizado"),
     *     @OA\Response(response=422, description="Erro de validação")
     * )
     */
    public function update(Request $request, Supplier $supplier)
    {
        if ($request->has('categories') && is_string($request->categories)) {
             $request->merge(['categories' => explode(',', $request->categories)]);
        }

        $validated = $request->validate([
            'legal_name' => 'string|max:255',
            'commercial_name' => 'string|max:255',
            'email' => ['email', Rule::unique('suppliers')->ignore($supplier->id)],
            'phone' => 'string|max:20',
            'nif' => ['string', Rule::unique('suppliers')->ignore($supplier->id)],
            'activity_type' => 'nullable|string',
            'province' => 'string',
            'municipality' => 'string',
            'address' => 'nullable|string',
            'categories' => 'array',
            'categories.*' => 'exists:categories,id',
            'is_active' => 'boolean',
            
            'commercial_certificate' => 'nullable|file|mimes:pdf,jpg,png|max:5120',
            'commercial_license' => 'nullable|file|mimes:pdf,jpg,png|max:5120',
            'nif_proof' => 'nullable|file|mimes:pdf,jpg,png|max:5120',
        ]);

        if (isset($validated['activity_type'])) {
             // Map activity_type to DB enum
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
        }

        $uploadPath = 'suppliers/documents';

        foreach (['commercial_certificate', 'commercial_license', 'nif_proof'] as $fileKey) {
            if ($request->hasFile($fileKey)) {
                // Delete old file
                if ($supplier->$fileKey) {
                    Storage::disk('public')->delete($supplier->$fileKey);
                }
                $validated[$fileKey] = $request->file($fileKey)->store($uploadPath, 'public');
            }
        }

        $supplier->update($validated);

        if (isset($validated['categories'])) {
            $supplier->categories()->sync($validated['categories']);
        }

        return response()->json($supplier->load('categories'));
    }

    /**
     * @OA\Delete(
     *     path="/api/suppliers/{id}",
     *     summary="Remover fornecedor",
     *     tags={"Fornecedores"},
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")),
     *     @OA\Response(response=204, description="Fornecedor removido"),
     *     @OA\Response(response=404, description="Fornecedor não encontrado")
     * )
     */
    public function destroy(Supplier $supplier)
    {
        $supplier->delete();

        return response()->json(null, 204);
    }
}
