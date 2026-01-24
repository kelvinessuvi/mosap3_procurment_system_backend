<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SupplierEvaluation extends Model
{
    use HasFactory;

    protected $fillable = [
        'supplier_id', 'total_quotations', 'total_responses',
        'total_approved', 'total_rejected', 'total_acquisitions',
        'response_rate', 'success_rate', 'acquisition_rate',
        'avg_response_time_hours', 'total_revisions_requested', 'overall_score'
    ];

    public function supplier()
    {
        return $this->belongsTo(Supplier::class);
    }
}
