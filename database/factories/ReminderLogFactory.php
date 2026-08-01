<?php

namespace Database\Factories;

use App\Models\ReminderLog;
use Illuminate\Database\Eloquent\Factories\Factory;

class ReminderLogFactory extends Factory
{
    protected $model = ReminderLog::class;

    public function definition(): array
    {
        return [
            'entity_type' => \App\Models\QuotationRequest::class,
            'entity_id' => fake()->numberBetween(1, 1000),
            'reminder_type' => fake()->randomElement(['t_minus_2', 't_minus_1', 'due_date', 'overdue']),
            'channel' => fake()->randomElement(['in_app', 'email']),
            'sent_date' => now()->toDateString(),
        ];
    }
}
