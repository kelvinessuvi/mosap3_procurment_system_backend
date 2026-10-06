<?php

namespace Database\Factories;

use App\Models\QuotationSupplier;
use Illuminate\Database\Eloquent\Factories\Factory;

class QuotationResponseFactory extends Factory
{
    public function definition(): array
    {
        return [
            'quotation_supplier_id' => QuotationSupplier::factory(),
            'status' => 'pending_review',
            'revision_number' => 0,
            'submitted_at' => now(),
            'delivery_date' => now()->addDays(15),
            'delivery_days' => 15,
            'payment_terms' => null,
        ];
    }

    public function approved(): static
    {
        return $this->state(fn () => ['status' => 'approved']);
    }
}
