<?php

namespace Database\Factories;

use App\Models\Account;
use App\Models\CashMovement;
use App\Models\Instrument;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CashMovement>
 */
class CashMovementFactory extends Factory
{
    public function definition(): array
    {
        $amount = fake()->randomFloat(2, 10, 500);

        return [
            'account_id'          => Account::factory(),
            'instrument_id'       => Instrument::factory(),
            'occurred_at'         => fake()->dateTimeBetween('-2 years', 'now'),
            'type'                => 'dividend',
            'amount'              => $amount,
            'currency'            => 'USD',
            'fx_rate'             => 0.92,
            'balance_eur'         => null,
            'description'         => 'Dividend',
            'excluded_from_returns' => false,
            'dedupe_hash'         => fake()->unique()->sha256(),
        ];
    }
}
