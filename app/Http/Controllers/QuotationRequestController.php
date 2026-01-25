<?php

namespace App\Http\Controllers;

use App\Mail\QuotationRequestMail;
use App\Models\QuotationRequest;
use App\Models\QuotationSupplier;
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
     *     tags={"Cotações (Requests)"},
     *     security={{"bearerAuth":{}}},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"title", "deadline", "items", "suppliers"},
     *             @OA\Property(property="title", type="string", example="Aquisição de Mobiliário"),
     *             @OA\Property(property="description", type="string", example="Mobiliário para novo escritório"),
     *             @OA\Property(property="deadline", type="string", format="date-time", example="2026-02-01 17:00:00"),
     *             @OA\Property(property="items", type="array", @OA\Items(
     *                 required={"name", "quantity", "unit"},
     *                 @OA\Property(property="name", type="string", example="Cadeira Giratória"),
     *                 @OA\Property(property="quantity", type="integer", example=10),
     *                 @OA\Property(property="unit", type="string", example="un"),
     *                 @OA\Property(property="specifications", type="string", example="Cor preta, ergonômica")
     *             )),
     *             @OA\Property(property="suppliers", type="array", @OA\Items(type="integer"), example={1, 2}, description="IDs dos fornecedores convidados")
     *         )
     *     ),
     *     @OA\Response(response=201, description="Criado com sucesso"),
     *     @OA\Response(response=422, description="Erro de validação")
     * )
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'description' => 'nullable|string',
            'deadline' => 'required|date|after:now',
            'items' => 'required|array|min:1',
            'items.*.name' => 'required|string',
            'items.*.quantity' => 'required|integer|min:1',
            'items.*.unit' => 'required|string',
            'items.*.specifications' => 'nullable|string',
            'items.*.product_id' => 'nullable|exists:products,id',
            'suppliers' => 'required|array|min:1',
            'suppliers.*' => 'exists:suppliers,id',
        ]);

        return DB::transaction(function () use ($validated, $request) {
            $quotation = QuotationRequest::create([
                'title' => $validated['title'],
                'description' => $validated['description'],
                'deadline' => $validated['deadline'],
                'status' => 'draft',
                'user_id' => $request->user()->id,
            ]);

            foreach ($validated['items'] as $item) {
                // Auto-link to product catalog
                if (!isset($item['product_id'])) {
                    // Check if product exists by name (case insensitive ideally, but exact for now)
                    $product = \App\Models\Product::firstOrCreate(
                        ['name' => $item['name']],
                        [
                            'unit' => $item['unit'],
                            'description' => $item['specifications'] ?? null
                        ]
                    );
                    $item['product_id'] = $product->id;
                }
                
                $quotation->items()->create($item);
            }

            // Attach suppliers (creates pivots with auto-token)
            $quotation->suppliers()->attach($validated['suppliers']);

            return response()->json($quotation->load(['items', 'suppliers']), 201);
        });
    }

    /**
     * Display the specified resource.
     */
    public function show(QuotationRequest $quotationRequest)
    {
        return response()->json($quotationRequest->load(['items', 'suppliers', 'user']));
    }

    /**
     * Update the specified resource in storage.
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
            'deadline' => 'date|after:now',
        ]);

        $quotationRequest->update($validated);

        return response()->json($quotationRequest);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(QuotationRequest $quotationRequest)
    {
        if ($quotationRequest->status !== 'draft') {
            return response()->json(['message' => 'Apenas cotações em rascunho podem ser excluídas.'], 400);
        }
        $quotationRequest->delete();
        return response()->json(null, 204);
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
    public function send(QuotationRequest $quotationRequest)
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
                $qs->token
            ));

            $qs->update([
                'status' => 'sent',
                'sent_at' => now(),
            ]);
        }

        $quotationRequest->update(['status' => 'sent']);

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
    public function cancel(QuotationRequest $quotationRequest)
    {
        // Logic to cancel
        $quotationRequest->update(['status' => 'cancelled']);
        return response()->json(['message' => 'Cotação cancelada.']);
    }
}
