<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;
use App\Traits\Auditable;

class Supplier extends Model
{
    use HasFactory, SoftDeletes, Auditable;

    protected $fillable = [
        'company_name', 'email', 'phone', 'alt_phone', 'nif',
        'activity_type', 'province', 'municipality', 'address',
        'commercial_certificate', 'commercial_license', 'nif_proof',
        'pacto_social', 'non_debtor_certificate_agt', 'non_debtor_certificate_inss', 'product_list',
        'is_active', 'user_id',
        'registration_token', 'registration_status', 'registered_at'
    ];

    protected $casts = [
        'registered_at' => 'datetime',
    ];

    public static function boot()
    {
        parent::boot();

        static::creating(function ($model) {
            if (!$model->registration_token) {
                $model->registration_token = Str::random(64);
            }
        });
    }

    protected $appends = [
        'commercial_certificate_url',
        'commercial_license_url',
        'nif_proof_url',
        'pacto_social_url',
        'non_debtor_certificate_agt_url',
        'non_debtor_certificate_inss_url',
        'product_list_url'
    ];

    public function getCommercialCertificateUrlAttribute()
    {
        return $this->commercial_certificate
            ? url('/api/suppliers/' . $this->id . '/documents/commercial_certificate')
            : null;
    }

    public function getCommercialLicenseUrlAttribute()
    {
        return $this->commercial_license
            ? url('/api/suppliers/' . $this->id . '/documents/commercial_license')
            : null;
    }

    public function getNifProofUrlAttribute()
    {
        return $this->nif_proof
            ? url('/api/suppliers/' . $this->id . '/documents/nif_proof')
            : null;
    }

    public function getPactoSocialUrlAttribute()
    {
        return $this->pacto_social
            ? url('/api/suppliers/' . $this->id . '/documents/pacto_social')
            : null;
    }

    public function getNonDebtorCertificateAgtUrlAttribute()
    {
        return $this->non_debtor_certificate_agt
            ? url('/api/suppliers/' . $this->id . '/documents/non_debtor_certificate_agt')
            : null;
    }

    public function getNonDebtorCertificateInssUrlAttribute()
    {
        return $this->non_debtor_certificate_inss
            ? url('/api/suppliers/' . $this->id . '/documents/non_debtor_certificate_inss')
            : null;
    }

    public function getProductListUrlAttribute()
    {
        return $this->product_list
            ? url('/api/suppliers/' . $this->id . '/documents/product_list')
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

    public function deletionRequests()
    {
        return $this->morphMany(DeletionRequest::class, 'requestable');
    }

    public function evaluation()
    {
        return $this->hasOne(SupplierEvaluation::class);
    }
}
