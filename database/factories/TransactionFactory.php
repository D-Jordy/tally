<?php

namespace Database\Factories;

use App\Models\Account;
use App\Models\Instrument;
use App\Models\Transaction;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Transaction>
 */
class TransactionFactory extends Factory
{
    public function definition(): array
    {
        $qty   = fake()->randomFloat(2, 1, 200);
        $price = fake()->randomFloat(4, 5, 500);

        return [
            'account_id'     => Account::factory(),
            'instrument_id'  => Instrument::factory(),
            'executed_at'    => fake()->dateTimeBetween('-3 years', '-1 month'),
            'type'           => 'buy',
            'quantity'       => $qty,
            'price'          => $price,
            'price_currency' => 'USD',
            'fee'            => 0,
            'trade_currency' => 'USD',
            'fx_rate_to_eur' => 0.92,
            'local_value'    => $qty * $price,
            'value_eur'      => $qty * $price * 0.92,
            'total_eur'      => $qty * $price * 0.92,
        ];
    }
}
