<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

use App\Traits\Auditable;

class QuotationRequest extends Model
{
    use HasFactory, Auditable;

    protected $fillable = [
        'reference_number', 'title', 'description', 'activity_description',
        'deadline', 'status', 'user_id', 'attachments'
    ];

    protected $casts = [
        'deadline' => 'datetime',
        'attachments' => 'array',
    ];

    public static function boot()
    {
        parent::boot();

        static::creating(function ($model) {
            if (!$model->reference_number) {
                // QT-YYYYMMDD-Random
                $model->reference_number = 'QT-' . date('Ymd') . '-' . strtoupper(\Illuminate\Support\Str::random(4));
            }
        });
    }

    public function suppliers()
    {
        return $this->belongsToMany(Supplier::class, 'quotation_suppliers')
                    ->using(QuotationSupplier::class)
                    ->withPivot(['id', 'token', 'status', 'sent_at', 'opened_at'])
                    ->withTimestamps();
    }

    public function quotationSuppliers()
    {
        return $this->hasMany(QuotationSupplier::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
