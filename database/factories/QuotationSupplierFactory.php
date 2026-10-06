<?php

namespace Database\Factories;

use App\Models\QuotationRequest;
use App\Models\Supplier;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class QuotationSupplierFactory extends Factory
{
    public function definition(): array
    {
        return [
            'quotation_request_id' => QuotationRequest::factory(),
            'supplier_id' => Supplier::factory(),
            'token' => Str::random(64),
            'status' => 'sent',
            'sent_at' => now(),
        ];
    }
}
