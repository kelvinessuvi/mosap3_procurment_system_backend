<?php

namespace App\Http\Controllers;

use App\Models\Product;
use Illuminate\Http\Request;

/**
 * @OA\Tag(
 *     name="Catálogo de Produtos",
 *     description="Gestão de Produtos Reutilizáveis"
 * )
 */
class ProductController extends Controller
{
    /**
     * @OA\Get(
     *     path="/api/products",
     *     summary="Listar Produtos",
     *     tags={"Catálogo de Produtos"},
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(name="search", in="query", @OA\Schema(type="string")),
     *     @OA\Response(response=200, description="Lista de produtos")
     * )
     */
    public function index(Request $request)
    {
        $query = Product::with('category');

        if ($request->filled('search')) {
            $query->where('name', 'like', '%' . $request->search . '%');
        }

        if ($request->filled('category_id')) {
            $query->where('category_id', $request->category_id);
        }

        return response()->json($query->paginate(20));
    }

    /**
     * @OA\Post(
     *     path="/api/products",
     *     summary="Criar Produto",
     *     tags={"Catálogo de Produtos"},
     *     security={{"bearerAuth":{}}},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"name", "unit"},
     *             @OA\Property(property="name", type="string"),
     *             @OA\Property(property="description", type="string"),
     *             @OA\Property(property="unit", type="string"),
     *             @OA\Property(property="category_id", type="integer")
     *         )
     *     ),
     *     @OA\Response(response=201, description="Produto criado")
     * )
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255|unique:products',
            'description' => 'nullable|string',
            'unit' => 'required|string|max:10',
            'category_id' => 'nullable|exists:categories,id'
        ]);

        $product = Product::create($validated);

        return response()->json($product, 201);
    }

    /**
     * @OA\Get(
     *     path="/api/products/{id}/analytics",
     *     summary="Análise de Preços do Produto",
     *     description="Retorna melhor preço, média e histórico de preços praticados.",
     *     tags={"Catálogo de Produtos"},
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")),
     *     @OA\Response(response=200, description="Dados de análise de preço")
     * )
     */
    public function priceAnalytics($id)
    {
        $product = Product::findOrFail($id);

        // Find all unit prices offered for this product via QuotationResponseItems
        // Linking: Product <- QuotationItem <- QuotationResponseItem
        $prices = \Illuminate\Support\Facades\DB::table('quotation_response_items')
            ->join('quotation_items', 'quotation_response_items.quotation_item_id', '=', 'quotation_items.id')
            ->join('quotation_responses', 'quotation_response_items.quotation_response_id', '=', 'quotation_responses.id')
            ->join('quotation_suppliers', 'quotation_responses.quotation_supplier_id', '=', 'quotation_suppliers.id')
            ->join('suppliers', 'quotation_suppliers.supplier_id', '=', 'suppliers.id')
            ->where('quotation_items.product_id', $id)
            ->whereIn('quotation_responses.status', ['approved', 'completed', 'submitted']) // Consider valid offers
            ->select(
                'quotation_response_items.unit_price',
                'quotation_responses.submitted_at',
                'suppliers.commercial_name as supplier_name'
            )
            ->orderBy('quotation_responses.submitted_at', 'desc')
            ->get();

        if ($prices->isEmpty()) {
             return response()->json([
                 'message' => 'Sem dados históricos para este produto.',
                 'best_price' => null,
                 'average_price' => null
             ]);
        }

        $minPrice = $prices->min('unit_price');
        $avgPrice = $prices->avg('unit_price');
        $maxPrice = $prices->max('unit_price');
        $lastPrice = $prices->first()->unit_price;

        $bestOffer = $prices->firstWhere('unit_price', $minPrice);

        return response()->json([
            'product' => $product,
            'best_price' => $minPrice,
            'best_supplier' => $bestOffer->supplier_name,
            'best_date' => $bestOffer->submitted_at,
            'average_price' => round($avgPrice, 2),
            'max_price' => $maxPrice,
            'last_price' => $lastPrice,
            'price_history' => $prices->take(10) // Last 10 prices
        ]);
    }
}
