<?php

namespace Tests\Feature;

use App\Actions\ComputePortfolioHistory;
use App\Models\Account;
use App\Models\CashMovement;
use App\Models\FxRate;
use App\Models\Instrument;
use App\Models\PriceHistory;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ComputePortfolioHistoryTest extends TestCase
{
    use RefreshDatabase;

    private function history(User $user): array
    {
        return app(ComputePortfolioHistory::class)->forUser($user);
    }

    private function buy(Account $account, Instrument $instrument, float $qty, string $date, string $currency = 'EUR'): void
    {
        Transaction::create([
            'account_id' => $account->id,
            'instrument_id' => $instrument->id,
            'executed_at' => $date.' 10:00:00',
            'type' => 'buy',
            'quantity' => $qty,
            'price' => 100,
            'price_currency' => $currency,
            'fee' => 0,
            'trade_currency' => $currency,
            'fx_rate_to_eur' => $currency === 'EUR' ? null : 0.9,
            'local_value' => $qty * 100,
            'value_eur' => $qty * 100,
            'total_eur' => $qty * 100,
            'source' => 'import',
            'external_id' => 'buy-'.uniqid(),
        ]);
    }

    private function sell(Account $account, Instrument $instrument, float $qty, string $date, string $currency = 'EUR'): void
    {
        Transaction::create([
            'account_id' => $account->id,
            'instrument_id' => $instrument->id,
            'executed_at' => $date.' 10:00:00',
            'type' => 'sell',
            'quantity' => $qty,
            'price' => 100,
            'price_currency' => $currency,
            'fee' => 0,
            'trade_currency' => $currency,
            'fx_rate_to_eur' => $currency === 'EUR' ? null : 0.9,
            'local_value' => $qty * 100,
            'value_eur' => $qty * 100,
            'total_eur' => $qty * 100,
            'source' => 'import',
            'external_id' => 'sell-'.uniqid(),
        ]);
    }

    private function price(Instrument $instrument, float $close, string $date, string $currency = 'EUR'): void
    {
        PriceHistory::create(['instrument_id' => $instrument->id, 'date' => $date, 'close' => $close, 'currency' => $currency]);
    }

    public function test_returns_empty_with_fewer_than_two_price_dates(): void
    {
        $user = User::factory()->create();
        $account = Account::factory()->for($user)->create();
        $instrument = Instrument::factory()->create();
        $this->buy($account, $instrument, 10, '2024-01-02');
        $this->price($instrument, 100, '2024-01-02');

        $this->assertSame([], $this->history($user));
    }

    public function test_builds_a_value_series_and_net_gain_against_deposits(): void
    {
        $user = User::factory()->create();
        $account = Account::factory()->for($user)->create();
        $instrument = Instrument::factory()->create();

        $this->buy($account, $instrument, 10, '2024-01-02');
        $this->price($instrument, 100, '2024-01-02');
        $this->price($instrument, 120, '2024-06-30');
        CashMovement::create(['account_id' => $account->id, 'occurred_at' => '2024-01-01', 'type' => 'deposit', 'amount' => 1000, 'currency' => 'EUR', 'source' => 'import']);

        $series = $this->history($user);

        $this->assertCount(2, $series);
        $this->assertSame('2024-01-02', $series[0]['date']);
        $this->assertSame(1000.0, (float) $series[0]['total_value_eur']);
        $this->assertSame(0.0, (float) $series[0]['net_gain_eur']);

        $last = end($series);
        $this->assertSame(1200.0, (float) $last['total_value_eur']);
        $this->assertSame(200.0, (float) $last['net_gain_eur']); // 1200 - 1000 deposited
    }

    public function test_foreign_prices_are_converted_with_carried_forward_fx(): void
    {
        $user = User::factory()->create();
        $account = Account::factory()->for($user)->create();
        $instrument = Instrument::factory()->create();

        $this->buy($account, $instrument, 10, '2024-01-02', 'USD');
        $this->price($instrument, 100, '2024-01-02', 'USD');
        $this->price($instrument, 100, '2024-06-30', 'USD');
        FxRate::create(['date' => '2024-01-01', 'currency' => 'USD', 'rate_to_eur' => 0.90]);

        $series = $this->history($user);
        $last = end($series);

        // 10 * 100 * 0.90 = 900, FX carried forward from 2024-01-01.
        $this->assertSame(900.0, (float) $last['total_value_eur']);
    }

    public function test_overselling_then_rebuying_does_not_leave_a_phantom_share(): void
    {
        $user = User::factory()->create();
        $account = Account::factory()->for($user)->create();
        $instrument = Instrument::factory()->create();

        // Buy 2, sell 3 (briefly short), buy 1 back → net 0, no phantom share.
        $this->buy($account, $instrument, 2, '2024-01-02');
        $this->sell($account, $instrument, 3, '2024-02-01');
        $this->buy($account, $instrument, 1, '2024-03-01');
        $this->price($instrument, 100, '2024-01-02');
        $this->price($instrument, 100, '2024-06-30');

        $series = $this->history($user);
        $last = end($series);

        $this->assertSame(0.0, (float) $last['total_value_eur']);
    }

    public function test_dividends_and_fees_accumulate_over_time(): void
    {
        $user = User::factory()->create();
        $account = Account::factory()->for($user)->create();
        $instrument = Instrument::factory()->create();

        $this->buy($account, $instrument, 10, '2024-01-02');
        $this->price($instrument, 100, '2024-01-02');
        $this->price($instrument, 100, '2024-06-30');
        CashMovement::create(['account_id' => $account->id, 'instrument_id' => $instrument->id, 'occurred_at' => '2024-03-01', 'type' => 'dividend', 'amount' => 30, 'currency' => 'EUR', 'source' => 'import']);
        CashMovement::create(['account_id' => $account->id, 'occurred_at' => '2024-03-01', 'type' => 'fee', 'amount' => -5, 'currency' => 'EUR', 'source' => 'import']);

        $series = $this->history($user);
        $last = end($series);

        $this->assertSame(30.0, (float) $last['cumulative_dividends_eur']);
        $this->assertSame(-5.0, (float) $last['cumulative_fees_eur']);
    }
}
