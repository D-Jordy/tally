<?php

namespace Database\Factories;

use App\Models\Instrument;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Instrument>
 */
class InstrumentFactory extends Factory
{
    public function definition(): array
    {
        return [
            'isin'           => strtoupper(fake()->bothify('??##########')),
            'name'           => fake()->company(),
            'symbol'         => strtoupper(fake()->bothify('????')),
            'yahoo_symbol'   => strtoupper(fake()->bothify('????')),
            'quote_currency' => 'USD',
            'sector'         => null,
            'country'        => 'US',
            'exchange'       => null,
        ];
    }
}
