<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class VerificationCode extends Model
{
    use HasFactory;

    public const TYPE_EMAIL_VERIFICATION = 'email_verification';
    public const TYPE_PASSWORD_RESET = 'password_reset';

    protected $fillable = [
        'email',
        'type',
        'code_hash',
        'attempts',
        'expires_at',
        'token',
        'token_expires_at',
        'consumed_at',
    ];

    protected $hidden = [
        'code_hash',
        'token',
    ];

    protected $casts = [
        'attempts' => 'integer',
        'expires_at' => 'datetime',
        'token_expires_at' => 'datetime',
        'consumed_at' => 'datetime',
    ];

    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }

    public function isConsumed(): bool
    {
        return ! is_null($this->consumed_at);
    }

    public function scopeActive($query)
    {
        return $query->whereNull('consumed_at');
    }
}
