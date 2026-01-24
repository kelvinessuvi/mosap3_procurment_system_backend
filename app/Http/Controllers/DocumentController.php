<?php

namespace App\Http\Controllers;

use App\Models\QuotationResponse;
use App\Models\Supplier;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class DocumentController extends Controller
{
    /**
     * Serve supplier documents (requires authentication)
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
     * Serve proposal document (requires authentication)
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
