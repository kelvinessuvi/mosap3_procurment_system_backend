<?php

namespace App\Models;

use App\Contracts\VisibilityScoped;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

use App\Traits\Auditable;
use Illuminate\Database\Eloquent\SoftDeletes;

class Acquisition extends Model implements VisibilityScoped
{
    use HasFactory, Auditable, SoftDeletes;

    protected $fillable = [
        'quotation_request_id', 'quotation_response_id',
        'supplier_id', 'user_id', 'reference_number',
        'total_amount', 'justification', 'status',
        'expected_delivery_date', 'actual_delivery_date'
    ];

    protected $casts = [
        'total_amount' => 'decimal:2',
        'expected_delivery_date' => 'date',
        'actual_delivery_date' => 'date',
    ];

    public static function boot()
    {
        parent::boot();
        
        static::creating(function ($model) {
            if (!$model->reference_number) {
                 $model->reference_number = 'ACQ-' . strtoupper(\Illuminate\Support\Str::random(8));
            }
        });

        static::deleting(function ($model) {
            $model->maskSensitiveData();
        });
    }

    public function maskSensitiveData(): void
    {
        $this->forceFill([
            'total_amount' => 0,
            'justification' => null,
        ])->save();
    }

    public function quotationRequest()
    {
        return $this->belongsTo(QuotationRequest::class);
    }

    public function quotationResponse()
    {
        return $this->belongsTo(QuotationResponse::class);
    }

    public function supplier()
    {
        return $this->belongsTo(Supplier::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function deletionRequests()
    {
        return $this->morphMany(DeletionRequest::class, 'requestable');
    }

    /**
     * Visibilidade herdada do processo que deu origem à aquisição.
     *
     * ATENÇÃO: acquisitions.user_id é quem APROVOU (ver o comentário na migração),
     * não quem iniciou o processo. Filtrar por essa coluna daria o resultado
     * errado — o caminho certo é sempre via quotationRequest.
     *
     * withTrashed() é intencional: sem ele o whereHas herda o SoftDeletes de
     * QuotationRequest e uma aquisição cujo processo foi eliminado desapareceria
     * para o técnico mas não para o admin.
     */
    public function scopeVisibleTo(Builder $query, ?User $user): Builder
    {
        if ($user && $user->role === 'admin') {
            return $query;
        }

        if (! $user) {
            return $query->whereRaw('1 = 0');
        }

        return $query->whereHas('quotationRequest', function ($q) use ($user) {
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

        return (bool) $this->quotationRequest?->isVisibleTo($user);
    }
}
