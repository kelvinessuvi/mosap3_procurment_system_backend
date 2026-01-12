<?php

namespace Database\Factories;

use App\Models\QuotationRequest;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class QuotationRequestFactory extends Factory
{
    protected $model = QuotationRequest::class;

    public function definition()
    {
        return [
            'reference_number' => 'QT-' . $this->faker->unique()->numerify('########') . '-' . strtoupper($this->faker->lexify('????')),
            'title' => $this->faker->sentence,
            'description' => $this->faker->paragraph,
            'deadline' => $this->faker->dateTimeBetween('+1 week', '+1 month'),
            'status' => 'draft',
            'user_id' => User::factory(),
        ];
    }
}
