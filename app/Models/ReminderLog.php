<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ReminderLog extends Model
{
    protected $fillable = [
        'entity_type',
        'entity_id',
        'reminder_type',
        'channel',
        'sent_date',
    ];

    protected function casts(): array
    {
        return [
            'sent_date' => 'date',
        ];
    }
}
