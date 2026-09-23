<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

use Illuminate\Database\Eloquent\SoftDeletes;
use App\Traits\Auditable;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable, SoftDeletes, Auditable;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'role',
        'is_active',
        'email_verified_at',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var array<int, string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'email_verified_at' => 'datetime',
        'password' => 'hashed',
        'is_active' => 'boolean',
    ];

    /**
     * O email desta conta já foi confirmado?
     */
    public function hasVerifiedEmail(): bool
    {
        return ! is_null($this->email_verified_at);
    }

    /**
     * Marca o email como confirmado.
     */
    public function markEmailAsVerified(): void
    {
        $this->forceFill(['email_verified_at' => now()])->save();
    }

    /**
     * Get the user's notifications
     */
    public function notifications()
    {
        return $this->hasMany(Notification::class);
    }

    public function menuPermissions()
    {
        return $this->belongsToMany(Menu::class, 'user_menu_permissions')
            ->withPivot('level')
            ->withTimestamps();
    }

    public function canAccessMenu(string $menuSlug, string $permissionType = 'read'): bool
    {
        if ($this->role === 'admin') {
            return true;
        }

        $perm = $this->menuPermissions()
            ->where('menus.slug', $menuSlug)
            ->first();

        if (!$perm) {
            return false;
        }

        if ($permissionType === 'read') {
            return in_array($perm->pivot->level, ['read', 'write'], true);
        }

        return $perm->pivot->level === 'write';
    }
}
