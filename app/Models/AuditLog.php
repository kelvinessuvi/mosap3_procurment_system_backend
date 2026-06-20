<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AuditLog extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id', 'event', 'description', 'details',
    ];

    protected $casts = [
        'details' => 'array',
    ];

    protected $appends = [
        'formatted_date',
    ];

    public function getFormattedDateAttribute()
    {
        return $this->created_at ? $this->created_at->format('d/m/Y H:i:s') : null;
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function scopeOfEvent($query, $event)
    {
        return $query->where('event', $event);
    }

    public function scopeForUser($query, $userId)
    {
        return $query->where('user_id', $userId);
    }

    public function scopeBetweenDates($query, $start, $end)
    {
        return $query->whereBetween('created_at', [$start, $end]);
    }

    public static function log($event, $description = null, $details = null, $user = null)
    {
        return static::create([
            'user_id' => $user ? (is_object($user) ? $user->id : $user) : null,
            'event' => $event,
            'description' => $description,
            'details' => $details,
        ]);
    }
}
