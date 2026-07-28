<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

use App\Traits\Auditable;

class DeletionRequest extends Model
{
    use HasFactory, Auditable;

    protected $fillable = [
        'requestable_type', 'requestable_id', 'requested_by',
        'reason', 'status', 'reviewed_by', 'reviewed_at', 'rejection_reason'
    ];

    protected $casts = [
        'reviewed_at' => 'datetime',
    ];

    public function requestable()
    {
        return $this->morphTo();
    }

    public function requester()
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function reviewer()
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }
}
