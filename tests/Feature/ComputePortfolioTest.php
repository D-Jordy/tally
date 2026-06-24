<?php

namespace Tests\Feature;

use App\Actions\ComputePortfolio;
use App\Models\Account;
use App\Models\CashMovement;
use App\Models\FxRate;
use App\Models\Instrument;
use App\Models\PriceHistory;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ComputePortfolioTest extends TestCase
{
    use RefreshDatabase;

    private function compute(User $user): array
    {
        return app(ComputePortfolio::class)->forUser($user);
    }

    private function buy(Account $account, Instrument $instrument, float $qty, float $price, string $currency = 'EUR', float $fee = 0, float $fxRate = 1.0, string $date = '2024-01-02'): void
    {
        $local = $qty * $price;

        Transaction::create([
            'account_id' => $account->id,
            'instrument_id' => $instrument->id,
            'executed_at' => $date.' 10:00:00',
            'type' => 'buy',
            'quantity' => $qty,
            'price' => $price,
            'price_currency' => $currency,
            'fee' => $fee,
            'trade_currency' => $currency,
            'fx_rate_to_eur' => $currency === 'EUR' ? null : $fxRate,
            'local_value' => $local,
            'value_eur' => round($local * $fxRate, 2),
            'total_eur' => round($local * $fxRate + $fee, 2),
            'source' => 'import',
            'external_id' => 'buy-'.uniqid(),
        ]);
    }

    private function sell(Account $account, Instrument $instrument, float $qty, float $price, string $currency = 'EUR', float $fee = 0, float $fxRate = 1.0, string $date = '2024-06-02'): void
    {
        $local = $qty * $price;

        Transaction::create([
            'account_id' => $account->id,
            'instrument_id' => $instrument->id,
            'executed_at' => $date.' 10:00:00',
            'type' => 'sell',
            'quantity' => $qty,
            'price' => $price,
            'price_currency' => $currency,
            'fee' => $fee,
            'trade_currency' => $currency,
            'fx_rate_to_eur' => $currency === 'EUR' ? null : $fxRate,
            'local_value' => $local,
            'value_eur' => round($local * $fxRate, 2),
            'total_eur' => round($local * $fxRate - $fee, 2),
            'source' => 'import',
            'external_id' => 'sell-'.uniqid(),
        ]);
    }

    private function price(Instrument $instrument, float $close, string $currency = 'EUR', string $date = '2024-12-31'): void
    {
        PriceHistory::create(['instrument_id' => $instrument->id, 'date' => $date, 'close' => $close, 'currency' => $currency]);
    }

    private function deposit(Account $account, float $amount, string $date = '2024-01-01'): void
    {
        CashMovement::create(['account_id' => $account->id, 'occurred_at' => $date, 'type' => 'deposit', 'amount' => $amount, 'currency' => 'EUR', 'source' => 'import']);
    }

    public function test_open_eur_position_reports_value_avg_cost_and_unrealised(): void
    {
        $user = User::factory()->create();
        $account = Account::factory()->for($user)->create();
        $instrument = Instrument::factory()->create(['name' => 'ASML']);

        $this->buy($account, $instrument, 10, 100, 'EUR', fee: 2);
        $this->price($instrument, 120, 'EUR');

        $position = $this->compute($user)['positions'][0];

        $this->assertSame(10.0, (float) $position['quantity']);
        $this->assertSame(100.0, (float) $position['avg_cost_per_share']); // fee-free local
        $this->assertSame(1002.0, (float) $position['cost_basis_eur']);     // includes fee
        $this->assertSame(1200.0, (float) $position['current_value_eur']);
        $this->assertSame(200.0, (float) $position['unrealized_gain_eur']); // fee-free basis
        $this->assertEqualsWithDelta(0.20, (float) $position['unrealized_gain_pct'], 0.0001);
    }

    public function test_foreign_currency_position_uses_fx_for_current_value(): void
    {
        $user = User::factory()->create();
        $account = Account::factory()->for($user)->create();
        $instrument = Instrument::factory()->create();

        $this->buy($account, $instrument, 10, 100, 'USD', fee: 1, fxRate: 0.90);
        $this->price($instrument, 110, 'USD');
        FxRate::create(['date' => '2024-12-31', 'currency' => 'USD', 'rate_to_eur' => 0.90]);

        $position = $this->compute($user)['positions'][0];

        // value = 10 * 110 * 0.90 = 990 ; fee-free basis = 100 * 10 * 0.90 = 900
        $this->assertSame(990.0, (float) $position['current_value_eur']);
        $this->assertSame(90.0, (float) $position['unrealized_gain_eur']);
    }

    public function test_partial_sell_books_realised_gain_and_keeps_remainder(): void
    {
        $user = User::factory()->create();
        $account = Account::factory()->for($user)->create();
        $instrument = Instrument::factory()->create();

        $this->buy($account, $instrument, 10, 100, 'EUR');
        $this->sell($account, $instrument, 4, 130, 'EUR');
        $this->price($instrument, 100, 'EUR');

        $position = $this->compute($user)['positions'][0];

        // sold 4: proceeds 520 - cost fraction (0.4 * 1000 = 400) = 120 realised.
        $this->assertSame(6.0, (float) $position['quantity']);
        $this->assertSame(120.0, (float) $position['realized_gain_eur']);
        $this->assertSame(100.0, (float) $position['avg_cost_per_share']); // WAC unchanged
    }

    public function test_fully_closed_position_is_excluded_but_realised_counts(): void
    {
        $user = User::factory()->create();
        $account = Account::factory()->for($user)->create();
        $instrument = Instrument::factory()->create();

        $this->buy($account, $instrument, 10, 100, 'EUR');
        $this->sell($account, $instrument, 10, 150, 'EUR');
        $this->price($instrument, 150, 'EUR');

        $result = $this->compute($user);

        $this->assertSame([], $result['positions']); // not an open position
        $this->assertSame(500.0, (float) $result['summary']['total_realized_gain_eur']);
    }

    public function test_dividends_are_netted_per_instrument(): void
    {
        $user = User::factory()->create();
        $account = Account::factory()->for($user)->create();
        $instrument = Instrument::factory()->create();

        $this->buy($account, $instrument, 10, 100, 'EUR');
        $this->price($instrument, 100, 'EUR');
        CashMovement::create(['account_id' => $account->id, 'instrument_id' => $instrument->id, 'occurred_at' => '2024-03-01', 'type' => 'dividend', 'amount' => 50, 'currency' => 'EUR', 'source' => 'import']);
        CashMovement::create(['account_id' => $account->id, 'instrument_id' => $instrument->id, 'occurred_at' => '2024-03-01', 'type' => 'withholding_tax', 'amount' => -7, 'currency' => 'EUR', 'source' => 'import']);

        $result = $this->compute($user);

        $this->assertSame(43.0, (float) $result['positions'][0]['dividend_eur']);
        $this->assertSame(43.0, (float) $result['summary']['total_dividend_eur']);
    }

    public function test_summary_nets_fees_and_derives_net_gain_from_deposits(): void
    {
        $user = User::factory()->create();
        $account = Account::factory()->for($user)->create();
        $instrument = Instrument::factory()->create();

        $this->buy($account, $instrument, 10, 100, 'EUR');
        $this->price($instrument, 120, 'EUR');
        $this->deposit($account, 1000);
        CashMovement::create(['account_id' => $account->id, 'occurred_at' => '2024-02-01', 'type' => 'fee', 'amount' => -3, 'currency' => 'EUR', 'source' => 'import']);
        CashMovement::create(['account_id' => $account->id, 'occurred_at' => '2024-02-01', 'type' => 'promo', 'amount' => 1, 'currency' => 'EUR', 'source' => 'import']);

        $summary = $this->compute($user)['summary'];

        $this->assertSame(1200.0, (float) $summary['total_value_eur']);
        $this->assertSame(1000.0, (float) $summary['deposited_eur']);
        $this->assertSame(200.0, (float) $summary['net_gain_eur']); // value - deposited
        $this->assertSame(-2.0, (float) $summary['total_fees_eur']); // fee net of promo
    }

    public function test_empty_user_returns_zeroed_summary(): void
    {
        $user = User::factory()->create();

        $result = $this->compute($user);

        $this->assertSame([], $result['positions']);
        $this->assertSame(0, $result['summary']['total_value_eur']);
        $this->assertNull($result['summary']['net_gain_pct']);
    }
}
