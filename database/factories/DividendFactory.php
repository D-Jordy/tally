<?php

namespace Database\Factories;

use App\Models\Dividend;
use App\Models\Instrument;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Dividend>
 */
class DividendFactory extends Factory
{
    public function definition(): array
    {
        return [
            'instrument_id'    => Instrument::factory(),
            'ex_date'          => fake()->dateTimeBetween('-2 years', 'now')->format('Y-m-d'),
            'pay_date'         => null,
            'amount_per_share' => fake()->randomFloat(4, 0.10, 2.00),
            'currency'         => 'USD',
            'confirmed'        => false,
        ];
    }
}
