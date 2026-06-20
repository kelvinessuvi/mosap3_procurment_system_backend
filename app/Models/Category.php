<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

use App\Traits\Auditable;

class Category extends Model
{
    use HasFactory, Auditable;

    protected $fillable = ['name', 'slug', 'description'];

    public function suppliers()
    {
        return $this->belongsToMany(Supplier::class);
    }

    public function products()
    {
        return $this->hasMany(Product::class);
    }
}
