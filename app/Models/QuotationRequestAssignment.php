<?php

namespace App\Models;

use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class QuotationRequestAssignment extends Model
{
    use HasFactory, Auditable;

    public const PENDING = 'pending';
    public const ACTIVE = 'active';
    public const REJECTED = 'rejected';
    public const REVOKED = 'revoked';

    /** Estados em que a linha está viva e ocupa a vaga única do par (processo, técnico). */
    public const OPEN_STATES = [self::PENDING, self::ACTIVE];

    protected $fillable = [
        'quotation_request_id',
        'user_id',
        'status',
        'requested_by',
        'reason',
        'reviewed_by',
        'reviewed_at',
        'rejection_reason',
        'revoked_by',
        'revoked_at',
    ];

    protected $casts = [
        'reviewed_at' => 'datetime',
        'revoked_at' => 'datetime',
    ];

    /**
     * `open_state` é derivado de `status`: nunca deve ser escrito à mão.
     *
     * É ele que dá ao índice único `qra_open_unique` o poder de impedir pedidos
     * pendentes duplicados — ao contrário do padrão do DeletionRequest, que
     * deteta duplicados com exists() e por isso tem uma race condition.
     */
    protected static function booted(): void
    {
        static::saving(function (self $assignment) {
            $assignment->open_state = in_array($assignment->status, self::OPEN_STATES, true)
                ? $assignment->status
                : null;
        });
    }

    public function quotationRequest()
    {
        return $this->belongsTo(QuotationRequest::class);
    }

    /** O técnico atribuído. */
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /** Quem pediu a atribuição (o criador do processo) ou quem a fez (admin). */
    public function requester()
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    /** O admin que aprovou ou rejeitou. */
    public function reviewer()
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function scopeActive($query)
    {
        return $query->where('status', self::ACTIVE);
    }

    public function scopePending($query)
    {
        return $query->where('status', self::PENDING);
    }

    public function isPending(): bool
    {
        return $this->status === self::PENDING;
    }

    public function isActive(): bool
    {
        return $this->status === self::ACTIVE;
    }
}
