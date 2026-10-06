<?php

namespace App\Models;

use App\Contracts\VisibilityScoped;
use App\Enums\ProcurementCategory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

use App\Traits\Auditable;
use Illuminate\Database\Eloquent\SoftDeletes;

class QuotationRequest extends Model implements VisibilityScoped
{
    use HasFactory, Auditable, SoftDeletes;

    protected $fillable = [
        'reference_number', 'title', 'description', 'activity_description',
        'deadline', 'status', 'user_id', 'attachments',
        'procurement_category', 'execution_start_date', 'execution_end_date', 'work_location',
    ];

    protected $casts = [
        'deadline' => 'datetime',
        'attachments' => 'array',
        'procurement_category' => ProcurementCategory::class,
        'execution_start_date' => 'date',
        'execution_end_date' => 'date',
    ];

    protected $appends = ['procurement_category_label'];

    public function getProcurementCategoryLabelAttribute(): ?string
    {
        return $this->procurement_category?->label();
    }

    public static function boot()
    {
        parent::boot();

        static::creating(function ($model) {
            if (!$model->reference_number) {
                // QT-YYYYMMDD-Random
                $model->reference_number = 'QT-' . date('Ymd') . '-' . strtoupper(\Illuminate\Support\Str::random(4));
            }
        });

        static::deleting(function ($model) {
            $model->maskSensitiveData();
        });
    }

    public function maskSensitiveData(): void
    {
        $this->forceFill([
            'title' => 'Eliminado',
            'description' => null,
            'activity_description' => null,
        ])->save();
    }

    public function suppliers()
    {
        // `token` fica deliberadamente FORA do withPivot: é o segredo que
        // autentica o fornecedor em /api/quotation/{token}/submit, e expô-lo na
        // serialização do processo permitia a qualquer utilizador com acesso
        // submeter ou declinar propostas em nome dos fornecedores.
        // O attach() continua a poder escrevê-lo — withPivot só afecta a leitura.
        return $this->belongsToMany(Supplier::class, 'quotation_suppliers')
                    ->using(QuotationSupplier::class)
                    ->withPivot(['id', 'status', 'sent_at', 'opened_at'])
                    ->withTimestamps();
    }

    public function quotationSuppliers()
    {
        return $this->hasMany(QuotationSupplier::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function deletionRequests()
    {
        return $this->morphMany(DeletionRequest::class, 'requestable');
    }

    public function acquisitions()
    {
        return $this->hasMany(Acquisition::class);
    }

    /** Todas as linhas de atribuição, em qualquer estado (incluindo histórico). */
    public function assignmentRecords()
    {
        return $this->hasMany(QuotationRequestAssignment::class);
    }

    /** Apenas os técnicos com atribuição em vigor. */
    public function assignees()
    {
        return $this->belongsToMany(User::class, 'quotation_request_assignments')
            ->withPivot(['status'])
            ->wherePivot('status', QuotationRequestAssignment::ACTIVE)
            ->withTimestamps();
    }

    /**
     * Restringe a query aos processos visíveis para este utilizador.
     *
     * Três detalhes são deliberados:
     *  - qualifyColumn(): sem isto, `user_id` fica ambíguo quando este scope é
     *    reutilizado dentro de whereHas() a partir de Acquisition, que tem a sua
     *    própria coluna user_id (e que é o APROVADOR, não o criador).
     *  - o OR vem embrulhado em where(function(){...}): sem os parênteses,
     *    combinar com um filtro como ?status=draft produziria
     *    "status = ? AND user_id = ? OR EXISTS(...)" e vazava tudo.
     *  - utilizador nulo devolve conjunto vazio (fail-closed), nunca o universo.
     */
    public function scopeVisibleTo(Builder $query, ?User $user): Builder
    {
        if ($user && $user->role === 'admin') {
            return $query;
        }

        if (! $user) {
            return $query->whereRaw('1 = 0');
        }

        return $query->where(function (Builder $q) use ($user) {
            $q->where($this->qualifyColumn('user_id'), $user->id)
                ->orWhereExists(function ($sub) use ($user) {
                    $sub->selectRaw('1')
                        ->from('quotation_request_assignments as qra')
                        ->whereColumn('qra.quotation_request_id', $this->qualifyColumn('id'))
                        ->where('qra.user_id', $user->id)
                        ->where('qra.status', QuotationRequestAssignment::ACTIVE);
                });
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

        if ((int) $this->user_id === (int) $user->id) {
            return true;
        }

        return $this->assignmentRecords()
            ->where('user_id', $user->id)
            ->where('status', QuotationRequestAssignment::ACTIVE)
            ->exists();
    }
}
