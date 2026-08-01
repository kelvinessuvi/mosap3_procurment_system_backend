<?php

namespace App\Http\Controllers;

use App\Mail\QuotationRequestMail;
use App\Models\AuditLog;
use App\Models\DeletionRequest;
use App\Models\Notification;
use App\Models\QuotationRequest;
use App\Models\QuotationSupplier;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

/**
 * @OA\Tag(
 *     name="Cotações (Requests)",
 *     description="Gestão de Pedidos de Cotação"
 * )
 */
class QuotationRequestController extends Controller
{
    /**
     * @OA\Get(
     *     path="/api/quotation-requests",
     *     summary="Listar pedidos de cotação",
     *     tags={"Cotações (Requests)"},
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(name="status", in="query", required=false, @OA\Schema(type="string", enum={"draft", "sent", "in_progress", "completed", "cancelled"})),
     *     @OA\Response(
     *         response=200,
     *         description="Lista de pedidos",
     *         @OA\JsonContent(type="object", @OA\Property(property="data", type="array", @OA\Items(type="object")))
     *     )
     * )
     */
    public function index(Request $request)
    {
        // ...
        // Add filters later
        $query = QuotationRequest::query()->withCount('suppliers');
        
        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        return response()->json($query->orderByDesc('created_at')->paginate(15));
    }

    /**
     * Store a newly created resource in storage.
     */
    /**
     * @OA\Post(
     *     path="/api/quotation-requests",
     *     summary="Criar novo pedido de cotação",
     *     description="Cria um novo pedido de cotação com itens, fornecedores convidados e documentos anexados opcionais. O pedido é criado com status 'draft'.",
     *     tags={"Cotações (Requests)"},
     *     security={{"bearerAuth":{}}},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\MediaType(
     *             mediaType="multipart/form-data",
     *             @OA\Schema(
     *                 required={"title", "deadline", "suppliers"},
     *                 @OA\Property(property="title", type="string", example="Aquisição de Mobiliário", description="Título do pedido de cotação"),
 *                 @OA\Property(property="description", type="string", example="Mobiliário para novo escritório", description="Descrição detalhada do pedido"),
 *                 @OA\Property(property="activity_description", type="string", example="Aquisição de bens e serviços", description="Descrição da actividade"),
 *                 @OA\Property(property="deadline", type="string", format="date-time", example="2026-02-01 17:00:00", description="Data limite para envio de propostas"),
     *                 @OA\Property(property="suppliers", type="array", @OA\Items(type="integer"), example={1, 2}, description="IDs dos fornecedores convidados"),
     *                 @OA\Property(
     *                     property="attachments[]",
     *                     type="array",
     *                     description="Documentos anexados ao pedido (PDF, DOC, DOCX, JPG, PNG, XLSX, XLS — máx 10MB cada)",
     *                     @OA\Items(type="string", format="binary")
     *                 )
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=201,
     *         description="Pedido criado com sucesso",
     *         @OA\JsonContent(
     *             @OA\Property(property="id", type="integer"),
     *             @OA\Property(property="reference_number", type="string"),
 *             @OA\Property(property="title", type="string"),
 *             @OA\Property(property="activity_description", type="string", nullable=true),
 *             @OA\Property(property="status", type="string", example="draft"),
     *             @OA\Property(property="attachments", type="array", nullable=true, @OA\Items(
     *                 @OA\Property(property="path", type="string"),
     *                 @OA\Property(property="original_name", type="string")
     *             )),
     *             @OA\Property(property="suppliers", type="array", @OA\Items(type="object"))
     *         )
     *     ),
     *     @OA\Response(response=422, description="Erro de validação")
     * )
     */
    public function store(Request $request)
    {
        if ($request->has('suppliers') && is_string($request->suppliers)) {
             $request->merge(['suppliers' => explode(',', $request->suppliers)]);
        }

        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'description' => 'nullable|string',
            'activity_description' => 'nullable|string',
            'deadline' => 'required|date|after:now',
            'suppliers' => 'required|array|min:1',
            'suppliers.*' => 'exists:suppliers,id',
            'attachments' => 'nullable|array',
            'attachments.*' => 'file|mimes:pdf,doc,docx,jpg,png,xlsx,xls|max:10240',
        ]);

        return DB::transaction(function () use ($validated, $request) {
            $quotation = QuotationRequest::create([
                'title' => $validated['title'],
                'description' => $validated['description'] ?? null,
                'activity_description' => $validated['activity_description'] ?? null,
                'deadline' => $validated['deadline'],
                'status' => 'draft',
                'user_id' => $request->user()->id,
            ]);

            // Handle file attachments (Catch all files regardless of key name)
            $uploadedFiles = [];
            foreach ($request->allFiles() as $key => $files) {
                $files = is_array($files) ? $files : [$files];
                foreach ($files as $file) {
                    $uploadedFiles[] = $file;
                }
            }

            if (count($uploadedFiles) > 0) {
                $attachmentPaths = [];
                foreach ($uploadedFiles as $file) {
                    $originalName = $file->getClientOriginalName();
                    $path = $file->store('quotation_attachments', 'public');
                    $attachmentPaths[] = [
                        'path' => $path,
                        'original_name' => $originalName,
                    ];
                }
                $quotation->update(['attachments' => $attachmentPaths]);
            }

            // Attach suppliers (creates pivots with auto-token)
            $quotation->suppliers()->attach($validated['suppliers']);

            return response()->json($quotation->load(['suppliers']), 201);
        });
    }

    /**
     * Display the specified resource.
     */
    public function show(QuotationRequest $quotationRequest)
    {
        return response()->json($quotationRequest->load(['suppliers', 'user']));
    }

    /**
     * @OA\Post(
     *     path="/api/quotation-requests/{id}",
     *     summary="Atualizar pedido de cotação",
     *     description="Atualiza um pedido de cotação em rascunho. Permite alterar título, descrição, prazo e adicionar novos documentos anexados. Use _method=PUT para uploads.",
     *     tags={"Cotações (Requests)"},
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(name="id", in="path", required=true, description="ID do pedido de cotação", @OA\Schema(type="integer")),
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\MediaType(
     *             mediaType="multipart/form-data",
     *             @OA\Schema(
     *                 @OA\Property(property="_method", type="string", example="PUT", description="Necessário para upload de arquivos em PUT"),
     *                 @OA\Property(property="title", type="string", example="Aquisição de Mobiliário", description="Título do pedido"),
 *                 @OA\Property(property="description", type="string", description="Descrição detalhada"),
 *                 @OA\Property(property="activity_description", type="string", description="Descrição da actividade"),
 *                 @OA\Property(property="deadline", type="string", format="date-time", description="Novo prazo limite"),
     *                 @OA\Property(
     *                     property="attachments[]",
     *                     type="array",
     *                     description="Novos documentos a anexar (serão adicionados aos existentes). PDF, DOC, DOCX, JPG, PNG, XLSX, XLS — máx 10MB cada.",
     *                     @OA\Items(type="string", format="binary")
     *                 )
     *             )
     *         )
     *     ),
     *     @OA\Response(response=200, description="Pedido atualizado com sucesso"),
     *     @OA\Response(response=400, description="Apenas cotações em rascunho podem ser editadas"),
     *     @OA\Response(response=422, description="Erro de validação")
     * )
     */
    public function update(Request $request, QuotationRequest $quotationRequest)
    {
        if ($quotationRequest->status !== 'draft') {
            return response()->json(['message' => 'Apenas cotações em rascunho podem ser editadas.'], 400);
        }

        // Simplified for brevity, usually full update logic
        $validated = $request->validate([
            'title' => 'string|max:255',
            'description' => 'nullable|string',
            'activity_description' => 'nullable|string',
            'deadline' => 'date|after:now',
            'attachments' => 'nullable|array',
            'attachments.*' => 'file|mimes:pdf,doc,docx,jpg,png,xlsx,xls|max:10240',
        ]);

        // Handle file attachments (Catch all files regardless of key name)
        $uploadedFiles = [];
        foreach ($request->allFiles() as $key => $files) {
            $files = is_array($files) ? $files : [$files];
            foreach ($files as $file) {
                $uploadedFiles[] = $file;
            }
        }

        if (count($uploadedFiles) > 0) {
            $existingAttachments = $quotationRequest->attachments ?? [];
            foreach ($uploadedFiles as $file) {
                $originalName = $file->getClientOriginalName();
                $path = $file->store('quotation_attachments', 'public');
                $existingAttachments[] = [
                    'path' => $path,
                    'original_name' => $originalName,
                ];
            }
            $validated['attachments'] = $existingAttachments;
        }

        $quotationRequest->update($validated);

        return response()->json($quotationRequest);
    }

    /**
     * @OA\Delete(
     *     path="/api/quotation-requests/{id}",
     *     summary="Remover cotação ou solicitar exclusão",
     *     description="Admin remove diretamente se for rascunho (204). Não-admin cria pedido de exclusão pendente para aprovação (201).",
     *     tags={"Cotações (Requests)"},
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")),
     *     @OA\RequestBody(
     *         @OA\JsonContent(
     *             @OA\Property(property="reason", type="string", description="Motivo/justificativa (obrigatório para não-admin)", example="Cotação duplicada")
     *         )
     *     ),
     *     @OA\Response(response=204, description="Cotação removida diretamente (admin, apenas rascunho)"),
     *     @OA\Response(response=201, description="Pedido de exclusão criado (não-admin)"),
     *     @OA\Response(response=400, description="Apenas cotações em rascunho podem ser excluídas diretamente"),
     *     @OA\Response(response=409, description="Já existe um pedido pendente para esta cotação")
     * )
     */
    public function destroy(Request $request, QuotationRequest $quotationRequest)
    {
        $user = $request->user();

        if ($user->role === 'admin') {
            if ($quotationRequest->status !== 'draft') {
                return response()->json(['message' => 'Apenas cotações em rascunho podem ser excluídas diretamente.'], 400);
            }
            $quotationRequest->delete();
            return response()->json(null, 204);
        }

        $pending = DeletionRequest::where('requestable_type', QuotationRequest::class)
            ->where('requestable_id', $quotationRequest->id)
            ->where('status', 'pending')
            ->exists();

        if ($pending) {
            return response()->json(['message' => 'Já existe um pedido de exclusão pendente para esta cotação.'], 409);
        }

        $validated = $request->validate([
            'reason' => 'required|string',
        ]);

        $deletionRequest = DeletionRequest::create([
            'requestable_type' => QuotationRequest::class,
            'requestable_id' => $quotationRequest->id,
            'requested_by' => $user->id,
            'reason' => $validated['reason'],
        ]);

        $admins = User::where('role', 'admin')->get();
        foreach ($admins as $admin) {
            Notification::create([
                'user_id' => $admin->id,
                'type' => 'deletion_requested',
                'title' => 'Solicitação de Exclusão',
                'message' => "O utilizador {$user->name} solicitou a exclusão da cotação #{$quotationRequest->reference_number}.\nMotivo: {$validated['reason']}",
                'data' => [
                    'deletion_request_id' => $deletionRequest->id,
                    'requestable_type' => QuotationRequest::class,
                    'requestable_id' => $quotationRequest->id,
                    'identifier' => $quotationRequest->reference_number,
                    'requested_by_name' => $user->name,
                    'reason' => $validated['reason'],
                ],
            ]);
        }

        AuditLog::log('Solicitação de exclusão', "Utilizador '{$user->name}' solicitou exclusão da cotação #{$quotationRequest->reference_number}", [
            'deletion_request_id' => $deletionRequest->id,
            'quotation_request_id' => $quotationRequest->id,
            'reference_number' => $quotationRequest->reference_number,
            'reason' => $validated['reason'],
        ], $user);

        return response()->json([
            'message' => 'Solicitação de exclusão enviada para aprovação do administrador.',
            'deletion_request' => $deletionRequest,
        ], 201);
    }

    // Custom Actions

    /**
     * @OA\Post(
     *     path="/api/quotation-requests/{id}/send",
     *     summary="Enviar convites aos fornecedores",
     *     description="Dispara e-mails para todos os fornecedores convidados com status 'pending'.",
     *     tags={"Cotações (Requests)"},
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")),
     *     @OA\Response(response=200, description="Convites enviados"),
     *     @OA\Response(response=400, description="Erro (apenas rascunhos podem ser enviados)")
     * )
     */
    public function send(Request $request, QuotationRequest $quotationRequest)
    {
        if ($quotationRequest->status !== 'draft') {
            // Allow re-sending to specific suppliers? 
            // For now, strict flow draft -> sent
            return response()->json(['message' => 'Apenas cotações em rascunho podem ser enviadas.'], 400);
        }

        $quotationRequest->load('suppliers');
        
        // Loop through pivots via relationship
        // Accessing pivot table model QuotationSupplier
        $quotationSuppliers = QuotationSupplier::where('quotation_request_id', $quotationRequest->id)
                                ->where('status', 'pending')
                                ->with('supplier')
                                ->get();

        if ($quotationSuppliers->isEmpty()) {
             return response()->json(['message' => 'Nenhum fornecedor pendente para envio.'], 400);
        }

        foreach ($quotationSuppliers as $qs) {
            Mail::to($qs->supplier->email)->send(new QuotationRequestMail(
                $quotationRequest, 
                $qs->supplier, 
                $qs->token,
                $quotationRequest->user
            ));

            $qs->update([
                'status' => 'sent',
                'sent_at' => now(),
            ]);
        }

        $quotationRequest->update(['status' => 'sent']);

        AuditLog::log('Envio de cotação', "Cotação #{$quotationRequest->reference_number} foi enviada para {$quotationSuppliers->count()} fornecedor(es)", [
            'quotation_request_id' => $quotationRequest->id,
            'reference_number' => $quotationRequest->reference_number,
            'suppliers_count' => $quotationSuppliers->count(),
            'supplier_ids' => $quotationSuppliers->pluck('supplier_id'),
        ], $request->user());

        return response()->json(['message' => 'Cotação enviada para ' . $quotationSuppliers->count() . ' fornecedores.']);
    }

    /**
     * @OA\Post(
     *     path="/api/quotation-requests/{id}/cancel",
     *     summary="Cancelar pedido de cotação",
     *     tags={"Cotações (Requests)"},
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")),
     *     @OA\Response(response=200, description="Cancelado com sucesso")
     * )
     */
    public function cancel(Request $request, QuotationRequest $quotationRequest)
    {
        // Logic to cancel
        $quotationRequest->update(['status' => 'cancelled']);

        AuditLog::log('Cancelamento de cotação', "Cotação #{$quotationRequest->reference_number} foi cancelada", [
            'quotation_request_id' => $quotationRequest->id,
            'reference_number' => $quotationRequest->reference_number,
        ], $request->user());

        return response()->json(['message' => 'Cotação cancelada.']);
    }
}
