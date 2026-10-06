<?php

namespace Database\Factories;

use App\Models\QuotationRequest;
use App\Models\QuotationResponse;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class AcquisitionFactory extends Factory
{
    public function definition(): array
    {
        return [
            'quotation_request_id' => QuotationRequest::factory(),
            'quotation_response_id' => QuotationResponse::factory(),
            'supplier_id' => Supplier::factory(),
            // Atenção: é quem APROVOU, não quem iniciou o processo.
            'user_id' => User::factory()->state(['role' => 'admin']),
            'reference_number' => 'ACQ-'.strtoupper(Str::random(8)),
            'total_amount' => $this->faker->randomFloat(2, 1000, 100000),
            'status' => 'pending',
            'expected_delivery_date' => now()->addDays(30),
        ];
    }
}
