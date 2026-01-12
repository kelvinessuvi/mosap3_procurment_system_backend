<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class QuotationResponse extends Model
{
    use HasFactory;

    protected $fillable = [
        'quotation_supplier_id', 'user_id', 'observations',
        'delivery_date', 'delivery_days', 'payment_terms',
        'submitted_at', 'status', 'review_notes', 'revision_number'
    ];

    protected $casts = [
        'delivery_date' => 'date',
        'submitted_at' => 'datetime',
    ];

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
}
