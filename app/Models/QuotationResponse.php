<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class QuotationResponse extends Model
{
    use HasFactory;

    protected $fillable = [
        'quotation_supplier_id', 'user_id', 'observations',
        'delivery_date', 'delivery_days', 'payment_terms',
        'submitted_at', 'status', 'review_notes', 'revision_number',
        'proposal_document', 'proposal_document_original_name'
    ];

    protected $casts = [
        'delivery_date' => 'date',
        'submitted_at' => 'datetime',
    ];

    protected $appends = ['proposal_document_url'];

    public function getProposalDocumentUrlAttribute()
    {
        if (!$this->proposal_document) {
            return null;
        }
        return url('storage/' . $this->proposal_document);
    }

    public function quotationSupplier()
    {
        return $this->belongsTo(QuotationSupplier::class);
    }

    public function items()
    {
        return $this->hasMany(QuotationResponseItem::class);
    }

    public function history()
    {
        return $this->hasMany(QuotationResponseHistory::class);
    }
}
