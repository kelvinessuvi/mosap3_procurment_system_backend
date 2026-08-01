<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

use App\Traits\Auditable;
use Illuminate\Database\Eloquent\SoftDeletes;

class Acquisition extends Model
{
    use HasFactory, Auditable, SoftDeletes;

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

        static::deleting(function ($model) {
            $model->maskSensitiveData();
        });
    }

    public function maskSensitiveData(): void
    {
        $this->forceFill([
            'total_amount' => 0,
            'justification' => null,
        ])->save();
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

    public function deletionRequests()
    {
        return $this->morphMany(DeletionRequest::class, 'requestable');
    }
}
