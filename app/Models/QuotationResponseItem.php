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

    protected $appends = ['calculated_total'];

    public function quotationItem()
    {
        return $this->belongsTo(QuotationItem::class);
    }

    // Accessor to calculate total if not set in database
    public function getTotalPriceAttribute($value)
    {
        // If total_price is already set, use it
        if ($value !== null) {
            return $value;
        }
        
        // Otherwise calculate it on the fly
        if ($this->quotationItem && $this->unit_price) {
            return $this->unit_price * $this->quotationItem->quantity;
        }
        
        return null;
    }

    // Calculated total for JSON responses
    public function getCalculatedTotalAttribute()
    {
        if ($this->total_price !== null) {
            return $this->total_price;
        }
        
        if ($this->quotationItem && $this->unit_price) {
            return $this->unit_price * $this->quotationItem->quantity;
        }
        
        return null;
    }
}
