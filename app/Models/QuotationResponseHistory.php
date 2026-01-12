<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class QuotationResponseHistory extends Model
{
    use HasFactory;

    protected $fillable = [
        'quotation_response_id', 'revision_number',
        'items_data', 'total_amount', 'action',
        'action_notes', 'user_id'
    ];

    protected $casts = [
        'items_data' => 'array',
    ];
}
