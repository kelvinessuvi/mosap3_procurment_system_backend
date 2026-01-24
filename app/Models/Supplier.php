<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

use Illuminate\Database\Eloquent\SoftDeletes;

class Supplier extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'legal_name', 'commercial_name', 'email', 'phone', 'nif',
        'activity_type', 'province', 'municipality', 'address',
        'commercial_certificate', 'commercial_license', 'nif_proof',
        'is_active', 'user_id'
    ];

    protected $appends = [
        'commercial_certificate_url',
        'commercial_license_url',
        'nif_proof_url'
    ];

    public function getCommercialCertificateUrlAttribute()
    {
        return $this->commercial_certificate 
            ? url('storage/' . $this->commercial_certificate) 
            : null;
    }

    public function getCommercialLicenseUrlAttribute()
    {
        return $this->commercial_license 
            ? url('storage/' . $this->commercial_license) 
            : null;
    }

    public function getNifProofUrlAttribute()
    {
        return $this->nif_proof 
            ? url('storage/' . $this->nif_proof) 
            : null;
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function categories()
    {
        return $this->belongsToMany(Category::class);
    }
}
