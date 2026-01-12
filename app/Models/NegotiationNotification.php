<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class NegotiationNotification extends Model
{
    use HasFactory;

    protected $fillable = [
        'quotation_supplier_id', 'reason',
        'message', 'is_read', 'read_at', 'sent_at'
    ];

    protected $casts = [
        'is_read' => 'boolean',
        'read_at' => 'datetime',
        'sent_at' => 'datetime',
    ];
}
