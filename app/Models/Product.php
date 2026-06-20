<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

use App\Traits\Auditable;

class Product extends Model
{
    use HasFactory, Auditable;

    protected $fillable = [
        'name',
        'description',
        'unit',
        'category_id'
    ];

    public function category()
    {
        return $this->belongsTo(Category::class);
    }
}
