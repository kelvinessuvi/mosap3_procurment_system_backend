<?php

namespace Database\Factories;

use App\Models\Supplier;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class SupplierFactory extends Factory
{
    protected $model = Supplier::class;

    public function definition()
    {
        return [
            'legal_name' => $this->faker->company,
            'commercial_name' => $this->faker->companySuffix,
            'email' => $this->faker->unique()->companyEmail,
            'phone' => $this->faker->phoneNumber,
            'nif' => $this->faker->unique()->numerify('#########'),
            'activity_type' => $this->faker->randomElement(['service', 'commerce']),
            'province' => $this->faker->state,
            'municipality' => $this->faker->city,
            'address' => $this->faker->address,
            'is_active' => true,
            'user_id' => User::factory(),
        ];
    }
}
