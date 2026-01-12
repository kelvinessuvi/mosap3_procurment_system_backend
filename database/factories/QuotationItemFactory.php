<?php

namespace Database\Factories;

use App\Models\QuotationItem;
use App\Models\QuotationRequest;
use Illuminate\Database\Eloquent\Factories\Factory;

class QuotationItemFactory extends Factory
{
    protected $model = QuotationItem::class;

    public function definition()
    {
        return [
            'quotation_request_id' => QuotationRequest::factory(),
            'name' => $this->faker->word,
            'quantity' => $this->faker->numberBetween(1, 100),
            'unit' => 'un',
            'specifications' => $this->faker->sentence,
        ];
    }
}
