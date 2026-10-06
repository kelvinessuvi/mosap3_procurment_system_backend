<?php

namespace App\Models;

use App\Contracts\VisibilityScoped;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

use App\Traits\Auditable;

class QuotationResponse extends Model implements VisibilityScoped
{
    use HasFactory, Auditable;

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
        return url('/api/quotation-responses/' . $this->id . '/document');
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

    /**
     * Visibilidade herdada do processo, por
     * quotation_supplier_id -> quotation_suppliers.quotation_request_id.
     *
     * quotation_responses.user_id é o revisor, não o criador do processo.
     */
    public function scopeVisibleTo(Builder $query, ?User $user): Builder
    {
        if ($user && $user->role === 'admin') {
            return $query;
        }

        if (! $user) {
            return $query->whereRaw('1 = 0');
        }

        return $query->whereHas('quotationSupplier.quotationRequest', function ($q) use ($user) {
            $q->withTrashed()->visibleTo($user);
        });
    }

    public function isVisibleTo(?User $user): bool
    {
        if (! $user) {
            return false;
        }

        if ($user->role === 'admin') {
            return true;
        }

        return (bool) $this->quotationSupplier?->quotationRequest?->isVisibleTo($user);
    }

    /**
     * Aquisição gerada a partir desta proposta (se existir).
     */
    public function acquisition()
    {
        return $this->hasOne(Acquisition::class);
    }
}
