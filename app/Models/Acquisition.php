<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Acquisition extends Model
{
    use HasFactory;

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
}
