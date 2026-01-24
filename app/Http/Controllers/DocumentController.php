<?php

namespace App\Http\Controllers;

use App\Models\QuotationResponse;
use App\Models\Supplier;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/**
 * @OA\Tag(
 *     name="Documentos",
 *     description="Visualização de Documentos de Fornecedores e Propostas"
 * )
 */
class DocumentController extends Controller
{
    /**
     * @OA\Get(
     *     path="/api/suppliers/{id}/documents/{documentType}",
     *     summary="Visualizar Documento do Fornecedor",
     *     description="Retorna o documento do fornecedor (PDF ou imagem). Tipos: commercial_certificate, commercial_license, nif_proof",
     *     tags={"Documentos"},
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(
     *         name="id",
     *         in="path",
     *         required=true,
     *         description="ID do fornecedor",
     *         @OA\Schema(type="integer")
     *     ),
     *     @OA\Parameter(
     *         name="documentType",
     *         in="path",
     *         required=true,
     *         description="Tipo de documento",
     *         @OA\Schema(
     *             type="string",
     *             enum={"commercial_certificate", "commercial_license", "nif_proof"}
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Documento retornado com sucesso",
     *         @OA\MediaType(
     *             mediaType="application/pdf"
     *         ),
     *         @OA\MediaType(
     *             mediaType="image/jpeg"
     *         ),
     *         @OA\MediaType(
     *             mediaType="image/png"
     *         )
     *     ),
     *     @OA\Response(response=404, description="Documento não encontrado"),
     *     @OA\Response(response=401, description="Não autenticado")
     * )
     */
    public function supplierDocument(Supplier $supplier, string $documentType)
    {
        // Validate document type
        if (!in_array($documentType, ['commercial_certificate', 'commercial_license', 'nif_proof'])) {
            abort(404);
        }

        $filePath = $supplier->$documentType;

        if (!$filePath || !Storage::disk('public')->exists($filePath)) {
            abort(404);
        }

        return response()->file(
            Storage::disk('public')->path($filePath),
            [
                'Content-Type' => Storage::disk('public')->mimeType($filePath),
                'Content-Disposition' => 'inline; filename="' . basename($filePath) . '"'
            ]
        );
    }

    /**
     * @OA\Get(
     *     path="/api/quotation-responses/{id}/document",
     *     summary="Visualizar Documento da Proposta",
     *     description="Retorna o documento da proposta enviado pelo fornecedor (PDF ou DOC)",
     *     tags={"Documentos"},
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(
     *         name="id",
     *         in="path",
     *         required=true,
     *         description="ID da resposta de cotação",
     *         @OA\Schema(type="integer")
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Documento retornado com sucesso",
     *         @OA\MediaType(
     *             mediaType="application/pdf"
     *         ),
     *         @OA\MediaType(
     *             mediaType="application/msword"
     *         ),
     *         @OA\MediaType(
     *             mediaType="application/vnd.openxmlformats-officedocument.wordprocessingml.document"
     *         )
     *     ),
     *     @OA\Response(response=404, description="Documento não encontrado"),
     *     @OA\Response(response=401, description="Não autenticado")
     * )
     */
    public function proposalDocument(QuotationResponse $quotationResponse)
    {
        $filePath = $quotationResponse->proposal_document;

        if (!$filePath || !Storage::disk('public')->exists($filePath)) {
            abort(404);
        }

        return response()->file(
            Storage::disk('public')->path($filePath),
            [
                'Content-Type' => Storage::disk('public')->mimeType($filePath),
                'Content-Disposition' => 'inline; filename="' . ($quotationResponse->proposal_document_original_name ?? basename($filePath)) . '"'
            ]
        );
    }
}
