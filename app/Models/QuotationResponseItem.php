<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class QuotationResponseItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'quotation_response_id', 'quotation_item_id',
        'unit_price', 'total_price', 'notes'
    ];

    public function quotationItem()
    {
        return $this->belongsTo(QuotationItem::class);
    }
}
