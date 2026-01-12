<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\Pivot;

class QuotationSupplier extends Pivot
{
    use HasFactory;

    public $incrementing = true;

    protected $table = 'quotation_suppliers';

    protected $fillable = [
        'quotation_request_id', 'supplier_id', 
        'token', 'status', 'sent_at', 'opened_at'
    ];

    protected $casts = [
        'sent_at' => 'datetime',
        'opened_at' => 'datetime',
    ];

    public static function boot()
    {
        parent::boot();

        static::creating(function ($model) {
            if (!$model->token) {
                $model->token = \Illuminate\Support\Str::random(64);
            }
        });
    }

    public function quotationRequest()
    {
        return $this->belongsTo(QuotationRequest::class);
    }

    public function supplier()
    {
        return $this->belongsTo(Supplier::class);
    }
}
