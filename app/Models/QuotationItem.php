<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class QuotationItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'quotation_request_id', 'name', 'description', 
        'quantity', 'unit', 'specifications', 'product_id'
    ];

    public function quotationRequest()
    {
        return $this->belongsTo(QuotationRequest::class);
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }
}
