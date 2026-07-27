<?php

namespace App\Http\Controllers;

use App\Models\Supplier;
use App\Mail\SupplierInvitationMail;
use App\Mail\SupplierApprovedMail;
use App\Models\AuditLog;
use App\Models\Notification;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
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
      *                 @OA\Property(property="company_name", type="string"),
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
                $q->where('company_name', 'like', "%{$search}%")
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

        if ($request->filled('registration_status')) {
            $query->where('registration_status', $request->registration_status);
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
      *     description="Regista um novo fornecedor com dados cadastrais, categorias e documentos obrigatórios/opcionais.",
      *     tags={"Fornecedores"},
      *     security={{"bearerAuth":{}}},
      *     @OA\RequestBody(
      *         required=true,
      *         @OA\MediaType(
      *             mediaType="multipart/form-data",
      *             @OA\Schema(
       *                 required={"company_name", "email", "phone", "nif", "activity_type", "province", "municipality", "address", "commercial_certificate", "nif_proof"},
      *                 @OA\Property(property="company_name", type="string", example="Empresa Exemplo SA", description="Nome da empresa"),
      *                 @OA\Property(property="email", type="string", format="email", example="contato@exemplo.ao", description="Email do fornecedor (único)"),
 *                 @OA\Property(property="phone", type="string", example="+244923456789", description="Telefone de contacto"),
 *                 @OA\Property(property="alt_phone", type="string", example="+244923456788", description="Telefone alternativo"),
 *                 @OA\Property(property="nif", type="string", example="5001234567", description="NIF do fornecedor (único)"),
      *                 @OA\Property(property="activity_type", type="string", enum={"Serviços", "Comércio Geral", "Tecnologia", "Construção", "Consultoria", "Transporte", "Saúde", "Outros"}, description="Tipo de atividade da empresa"),
      *                 @OA\Property(property="province", type="string", example="Luanda", description="Província"),
      *                 @OA\Property(property="municipality", type="string", example="Belas", description="Município"),
      *                 @OA\Property(property="address", type="string", example="Rua das Acácias, 12", description="Endereço completo"),
      *                 @OA\Property(property="commercial_certificate", type="string", format="binary", description="Certificado Comercial (PDF/JPG/PNG, máx 5MB) — Obrigatório"),
      *                 @OA\Property(property="commercial_license", type="string", format="binary", description="Alvará Comercial (PDF/JPG/PNG, máx 5MB) — Opcional"),
      *                 @OA\Property(property="nif_proof", type="string", format="binary", description="Comprovativo de NIF (PDF/JPG/PNG, máx 5MB) — Obrigatório"),
       *                 @OA\Property(property="pacto_social", type="string", format="binary", description="Pacto Social (PDF/JPG/PNG, máx 5MB) — Opcional"),
       *                 @OA\Property(property="non_debtor_certificate_agt", type="string", format="binary", description="Certificado de Não Devedor AGT (PDF/JPG/PNG, máx 5MB) — Opcional"),
       *                 @OA\Property(property="non_debtor_certificate_inss", type="string", format="binary", description="Certificado de Não Devedor INSS (PDF/JPG/PNG, máx 5MB) — Opcional"),
       *                 @OA\Property(property="product_list", type="string", format="binary", description="Lista de Produtos (PDF/JPG/PNG, máx 5MB) — Opcional"),
      *                 @OA\Property(property="categories", type="array", @OA\Items(type="integer"), description="IDs das categorias associadas ao fornecedor")
      *             )
      *         )
      *     ),
      *     @OA\Response(
      *         response=201,
      *         description="Fornecedor criado com sucesso",
      *         @OA\JsonContent(
      *             @OA\Property(property="id", type="integer", example=1),
       *             @OA\Property(property="company_name", type="string"),
      *             @OA\Property(property="email", type="string"),
      *             @OA\Property(property="nif", type="string"),
      *             @OA\Property(property="is_active", type="boolean"),
      *             @OA\Property(property="commercial_certificate_url", type="string", nullable=true),
      *             @OA\Property(property="commercial_license_url", type="string", nullable=true),
      *             @OA\Property(property="nif_proof_url", type="string", nullable=true),
      *             @OA\Property(property="pacto_social_url", type="string", nullable=true),
       *             @OA\Property(property="non_debtor_certificate_agt_url", type="string", nullable=true),
       *             @OA\Property(property="non_debtor_certificate_inss_url", type="string", nullable=true),
      *             @OA\Property(property="product_list_url", type="string", nullable=true),
      *             @OA\Property(property="categories", type="array", @OA\Items(type="object"))
      *         )
      *     ),
      *     @OA\Response(response=422, description="Erro de validação")
      * )
     */
    public function store(Request $request)
    {
        if ($request->has('categories') && is_string($request->categories)) {
             $request->merge(['categories' => explode(',', $request->categories)]);
        }

        $validated = $request->validate([
            'company_name' => 'required|string|max:255',
            'email' => 'required|email|unique:suppliers',
            'phone' => 'required|string|max:20',
            'alt_phone' => 'nullable|string|max:20',
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
            'pacto_social' => 'nullable|file|mimes:pdf,jpg,png|max:5120',
            'non_debtor_certificate_agt' => 'nullable|file|mimes:pdf,jpg,png|max:5120',
            'non_debtor_certificate_inss' => 'nullable|file|mimes:pdf,jpg,png|max:5120',
            'product_list' => 'nullable|file|mimes:pdf,jpg,png,xlsx,xls|max:5120',
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
        if ($request->hasFile('pacto_social')) {
            $validated['pacto_social'] = $request->file('pacto_social')->store($uploadPath, 'public');
        }
        if ($request->hasFile('non_debtor_certificate_agt')) {
            $validated['non_debtor_certificate_agt'] = $request->file('non_debtor_certificate_agt')->store($uploadPath, 'public');
        }
        if ($request->hasFile('non_debtor_certificate_inss')) {
            $validated['non_debtor_certificate_inss'] = $request->file('non_debtor_certificate_inss')->store($uploadPath, 'public');
        }
        if ($request->hasFile('product_list')) {
            $validated['product_list'] = $request->file('product_list')->store($uploadPath, 'public');
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
      *             @OA\Property(property="company_name", type="string"),
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
                'company_name' => $supplier->company_name,
                'overall_score' => 0,
                'message' => 'Nenhuma avaliação disponível ainda'
            ], 200);
        }

        return response()->json([
            'supplier_id' => $supplier->id,
            'company_name' => $supplier->company_name,
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
     * @OA\Post(
     *     path="/api/suppliers/{id}",
     *     summary="Atualizar fornecedor",
     *     description="Atualiza dados cadastrais e documentos de um fornecedor existente. Use _method=PUT para uploads de arquivos.",
     *     tags={"Fornecedores"},
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(name="id", in="path", required=true, description="ID do fornecedor", @OA\Schema(type="integer")),
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\MediaType(
     *             mediaType="multipart/form-data",
     *             @OA\Schema(
     *                 @OA\Property(property="_method", type="string", example="PUT", description="Necessário para upload de arquivos em PUT"),
      *                 @OA\Property(property="company_name", type="string", description="Nome da empresa"),
     *                 @OA\Property(property="email", type="string", format="email", description="Email (único)"),
     *                 @OA\Property(property="phone", type="string", description="Telefone"),
     *                 @OA\Property(property="nif", type="string", description="NIF (único)"),
     *                 @OA\Property(property="activity_type", type="string", enum={"Serviços", "Comércio Geral"}, description="Tipo de atividade"),
     *                 @OA\Property(property="province", type="string", description="Província"),
     *                 @OA\Property(property="municipality", type="string", description="Município"),
     *                 @OA\Property(property="address", type="string", description="Endereço"),
     *                 @OA\Property(property="is_active", type="boolean", description="Estado ativo/inativo"),
     *                 @OA\Property(property="commercial_certificate", type="string", format="binary", description="Certificado Comercial (PDF/JPG/PNG, máx 5MB)"),
     *                 @OA\Property(property="commercial_license", type="string", format="binary", description="Alvará Comercial (PDF/JPG/PNG, máx 5MB)"),
     *                 @OA\Property(property="nif_proof", type="string", format="binary", description="Comprovativo de NIF (PDF/JPG/PNG, máx 5MB)"),
     *                 @OA\Property(property="pacto_social", type="string", format="binary", description="Pacto Social (PDF/JPG/PNG, máx 5MB)"),
      *                 @OA\Property(property="non_debtor_certificate_agt", type="string", format="binary", description="Certificado de Não Devedor AGT (PDF/JPG/PNG, máx 5MB)"),
      *                 @OA\Property(property="non_debtor_certificate_inss", type="string", format="binary", description="Certificado de Não Devedor INSS (PDF/JPG/PNG, máx 5MB)"),
     *                 @OA\Property(property="product_list", type="string", format="binary", description="Lista de Produtos (PDF/JPG/PNG/XLSX, máx 5MB)"),
     *                 @OA\Property(property="categories", type="array", @OA\Items(type="integer"), description="IDs das categorias")
     *             )
     *         )
     *     ),
     *     @OA\Response(response=200, description="Fornecedor atualizado com sucesso"),
     *     @OA\Response(response=422, description="Erro de validação")
     * )
     */
    public function update(Request $request, Supplier $supplier)
    {
        if ($request->has('categories') && is_string($request->categories)) {
             $request->merge(['categories' => explode(',', $request->categories)]);
        }

        $validated = $request->validate([
            'company_name' => 'string|max:255',
            'email' => ['email', Rule::unique('suppliers')->ignore($supplier->id)],
            'phone' => 'string|max:20',
            'alt_phone' => 'nullable|string|max:20',
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
            'pacto_social' => 'nullable|file|mimes:pdf,jpg,png|max:5120',
            'non_debtor_certificate_agt' => 'nullable|file|mimes:pdf,jpg,png|max:5120',
            'non_debtor_certificate_inss' => 'nullable|file|mimes:pdf,jpg,png|max:5120',
            'product_list' => 'nullable|file|mimes:pdf,jpg,png,xlsx,xls|max:5120',
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

        foreach (['commercial_certificate', 'commercial_license', 'nif_proof', 'pacto_social', 'non_debtor_certificate_agt', 'non_debtor_certificate_inss', 'product_list'] as $fileKey) {
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

    /**
     * @OA\Post(
     *     path="/api/suppliers/invite",
     *     summary="Convidar Fornecedor para Registo",
     *     description="Envia um convite por email para o fornecedor se registar na plataforma.",
     *     tags={"Fornecedores"},
     *     security={{"bearerAuth":{}}},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"email"},
     *             @OA\Property(property="email", type="string", format="email", example="fornecedor@exemplo.ao")
     *         )
     *     ),
     *     @OA\Response(response=201, description="Convite enviado com sucesso"),
     *     @OA\Response(response=422, description="Erro de validação")
     * )
     */
    public function invite(Request $request)
    {
        $validated = $request->validate([
            'email' => 'required|email|unique:suppliers,email',
        ]);

        $supplier = Supplier::create([
            'company_name' => 'Pendente',
            'company_name' => 'Pendente',
            'email' => $validated['email'],
            'phone' => 'Pendente',
            'nif' => 'TEMP-' . Str::random(8),
            'activity_type' => 'service',
            'province' => 'Pendente',
            'municipality' => 'Pendente',
            'is_active' => false,
            'registration_status' => 'invited',
            'user_id' => $request->user()->id,
        ]);

        Mail::to($supplier->email)->send(new SupplierInvitationMail($supplier));

        AuditLog::log('Convite para registo', "Fornecedor '{$supplier->email}' foi convidado para se registar", [
            'supplier_id' => $supplier->id,
            'email' => $supplier->email,
        ], $request->user());

        return response()->json([
            'message' => 'Convite enviado com sucesso.',
            'supplier' => $supplier,
        ], 201);
    }

    /**
     * @OA\Post(
     *     path="/api/suppliers/{id}/approve",
     *     summary="Aprovar Fornecedor",
     *     description="Ativa o fornecedor após o registo ter sido concluído. Apenas admin e técnicos de procurement podem aprovar.",
     *     tags={"Fornecedores"},
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")),
     *     @OA\Response(response=200, description="Fornecedor aprovado com sucesso"),
     *     @OA\Response(response=404, description="Fornecedor não encontrado")
     * )
     */
    public function approve(Supplier $supplier)
    {
        if ($supplier->registration_status !== 'registered') {
            return response()->json([
                'message' => 'O fornecedor ainda não concluiu o registo.',
            ], 422);
        }

        if ($supplier->is_active) {
            return response()->json([
                'message' => 'O fornecedor já está ativo.',
            ], 422);
        }

        $supplier->update(['is_active' => true]);

        Mail::to($supplier->email)->send(new SupplierApprovedMail($supplier));

        AuditLog::log('Aprovação de fornecedor', "Fornecedor '{$supplier->company_name}' foi aprovado e ativado", [
            'supplier_id' => $supplier->id,
            'supplier_name' => $supplier->company_name,
            'email' => $supplier->email,
            'registration_status' => $supplier->registration_status,
        ], request()->user());

        Notification::create([
            'user_id' => $supplier->user_id ?? 1,
            'type' => 'supplier_approved',
            'title' => 'Fornecedor Aprovado',
            'message' => "O fornecedor {$supplier->company_name} foi aprovado e está agora ativo no sistema.",
            'data' => [
                'supplier_id' => $supplier->id,
                'supplier_name' => $supplier->company_name,
            ],
        ]);

        return response()->json([
            'message' => 'Fornecedor aprovado com sucesso.',
            'supplier' => $supplier,
        ]);
    }
}
