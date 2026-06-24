<?php

namespace Tests\Feature;

use App\Actions\ComputeIncomingDividends;
use App\Actions\ComputePortfolio;
use App\Models\Account;
use App\Models\CashMovement;
use App\Models\Dividend;
use App\Models\FxRate;
use App\Models\Instrument;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ComputeIncomingDividendsTest extends TestCase
{
    use RefreshDatabase;

    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    /**
     * Build a ComputeIncomingDividends instance with ComputePortfolio mocked
     * to return the given positions directly (avoids Postgres-only DISTINCT ON).
     */
    private function makeAction(array $mockPositions): ComputeIncomingDividends
    {
        $portfolioMock = $this->mock(ComputePortfolio::class);
        $portfolioMock->shouldReceive('forUser')->andReturn([
            'positions' => $mockPositions,
            'summary'   => [],
        ]);

        return app(ComputeIncomingDividends::class);
    }

    /** Create 4 quarterly ex-dates spaced ~90 days apart going backward from today. */
    private function seedQuarterlyDividends(int $instrumentId, float $amount, string $currency): void
    {
        for ($i = 4; $i >= 1; $i--) {
            Dividend::factory()->create([
                'instrument_id'    => $instrumentId,
                'ex_date'          => now()->subDays($i * 90)->toDateString(),
                'amount_per_share' => $amount,
                'currency'         => $currency,
            ]);
        }
    }

    private function seedFxRate(string $currency, float $rate): void
    {
        FxRate::create([
            'date'        => now()->toDateString(),
            'currency'    => $currency,
            'rate_to_eur' => $rate,
        ]);
    }

    // -----------------------------------------------------------------------
    // Tests
    // -----------------------------------------------------------------------

    public function test_projects_four_quarterly_events_for_next_12_months(): void
    {
        $instrument = Instrument::factory()->create(['yahoo_symbol' => 'TEST', 'quote_currency' => 'USD']);
        $this->seedQuarterlyDividends($instrument->id, 0.50, 'USD');
        $this->seedFxRate('USD', 0.92);

        $user   = User::factory()->create();
        $action = $this->makeAction([
            ['instrument_id' => $instrument->id, 'quantity' => 100],
        ]);

        $result = $action->forUser($user);

        // With a ~91-day interval from ~90 days ago, we expect 4 or 5 events within 12 months
        // depending on exact timing (91×5 = 455 days ≈ 12 months).
        $this->assertGreaterThanOrEqual(4, count($result['events']));
        $this->assertLessThanOrEqual(5, count($result['events']));
        $this->assertGreaterThanOrEqual(1, $result['summary']['instrument_count']);

        // expected_eur ≈ 0.50 × 100 × 0.92 = 46.00 per event
        foreach ($result['events'] as $event) {
            $this->assertEqualsWithDelta(46.00, $event['expected_eur'], 0.5);
            $this->assertTrue($event['projected']);
        }

        // next_12m_total ≈ 4–5 × 46.00
        $this->assertGreaterThanOrEqual(184.00, $result['summary']['next_12m_total_eur']);
        $this->assertLessThanOrEqual(230.00, $result['summary']['next_12m_total_eur']);
    }

    public function test_expected_eur_calculation(): void
    {
        $instrument = Instrument::factory()->create(['yahoo_symbol' => 'TEST', 'quote_currency' => 'USD']);
        $this->seedQuarterlyDividends($instrument->id, 0.25, 'USD');
        $this->seedFxRate('USD', 0.90);

        $user   = User::factory()->create();
        $action = $this->makeAction([
            ['instrument_id' => $instrument->id, 'quantity' => 200],
        ]);

        $result = $action->forUser($user);

        // 0.25 × 200 × 0.90 = 45.00
        foreach ($result['events'] as $event) {
            $this->assertEqualsWithDelta(45.00, $event['expected_eur'], 0.5);
        }
    }

    public function test_instrument_with_no_dividend_history_is_absent(): void
    {
        $instrument = Instrument::factory()->create();
        // No Dividend rows — instrument should not appear.

        $user   = User::factory()->create();
        $action = $this->makeAction([
            ['instrument_id' => $instrument->id, 'quantity' => 50],
        ]);

        $result = $action->forUser($user);

        $this->assertCount(0, $result['events']);
        $this->assertSame(0, $result['summary']['instrument_count']);
    }

    public function test_instrument_with_only_one_dividend_is_skipped(): void
    {
        $instrument = Instrument::factory()->create();

        Dividend::factory()->create([
            'instrument_id'    => $instrument->id,
            'ex_date'          => now()->subMonths(3)->toDateString(),
            'amount_per_share' => 0.50,
            'currency'         => 'USD',
        ]);

        $user   = User::factory()->create();
        $action = $this->makeAction([
            ['instrument_id' => $instrument->id, 'quantity' => 100],
        ]);

        $result = $action->forUser($user);

        $this->assertCount(0, $result['events']);
    }

    public function test_gbp_dividend_is_not_double_divided(): void
    {
        // Amounts already stored as GBP (pence division happened at ingest time).
        // The action must not divide again — just multiply qty × amount.
        $instrument = Instrument::factory()->create(['quote_currency' => 'GBP']);
        $this->seedQuarterlyDividends($instrument->id, 0.30, 'GBP'); // already £0.30
        $this->seedFxRate('GBP', 1.17);

        $user   = User::factory()->create();
        $action = $this->makeAction([
            ['instrument_id' => $instrument->id, 'quantity' => 100],
        ]);

        $result = $action->forUser($user);

        // 0.30 × 100 × 1.17 = 35.10 per event — NOT 0.003 × 100 × 1.17
        foreach ($result['events'] as $event) {
            $this->assertGreaterThan(30.0, $event['expected_eur']);
        }
    }

    public function test_monthly_buckets_cover_exactly_12_months(): void
    {
        $user   = User::factory()->create();
        $action = $this->makeAction([]);  // no positions → empty result still has 12 buckets

        $result = $action->forUser($user);

        $this->assertCount(12, $result['monthly']);
        $this->assertArrayHasKey('month', $result['monthly'][0]);
        $this->assertArrayHasKey('expected_eur', $result['monthly'][0]);
    }

    public function test_trailing_12m_received_sums_cash_movements(): void
    {
        $user       = User::factory()->create();
        $instrument = Instrument::factory()->create();
        $account    = Account::factory()->create(['user_id' => $user->id]);
        $this->seedFxRate('USD', 0.92);

        // 200 USD dividend received 6 months ago.
        CashMovement::factory()->create([
            'account_id'    => $account->id,
            'instrument_id' => $instrument->id,
            'type'          => 'dividend',
            'amount'        => 200.00,
            'currency'      => 'USD',
            'occurred_at'   => now()->subMonths(6),
        ]);

        // 30 USD withholding_tax (negative — reduces net).
        CashMovement::factory()->create([
            'account_id'    => $account->id,
            'instrument_id' => $instrument->id,
            'type'          => 'withholding_tax',
            'amount'        => -30.00,
            'currency'      => 'USD',
            'occurred_at'   => now()->subMonths(6),
        ]);

        $action = $this->makeAction([]);
        $result = $action->forUser($user);

        // (200 - 30) × 0.92 = 156.40
        $this->assertEqualsWithDelta(156.40, $result['summary']['trailing_12m_received_eur'], 0.5);
    }

    public function test_empty_result_for_user_with_no_positions(): void
    {
        $user   = User::factory()->create();
        $action = $this->makeAction([]);

        $result = $action->forUser($user);

        $this->assertCount(0, $result['confirmed']);
        $this->assertCount(0, $result['events']);
        $this->assertCount(12, $result['monthly']);
        $this->assertSame(0.0, $result['summary']['next_12m_total_eur']);
        $this->assertSame(0, $result['summary']['confirmed_count']);
    }

    public function test_confirmed_row_appears_in_confirmed_list_not_events(): void
    {
        $instrument = Instrument::factory()->create(['yahoo_symbol' => 'TEST', 'quote_currency' => 'USD']);
        $this->seedQuarterlyDividends($instrument->id, 0.50, 'USD');
        $this->seedFxRate('USD', 0.92);

        // Seed one confirmed upcoming row.
        Dividend::factory()->create([
            'instrument_id'    => $instrument->id,
            'ex_date'          => now()->addDays(14)->toDateString(),
            'amount_per_share' => 0.50,
            'currency'         => 'USD',
            'confirmed'        => true,
        ]);

        $user   = User::factory()->create();
        $action = $this->makeAction([
            ['instrument_id' => $instrument->id, 'quantity' => 100],
        ]);

        $result = $action->forUser($user);

        $this->assertCount(1, $result['confirmed']);
        $this->assertSame(1, $result['summary']['confirmed_count']);
        $this->assertTrue($result['confirmed'][0]['confirmed']);
        $this->assertFalse($result['confirmed'][0]['projected']);
    }

    public function test_projected_event_is_skipped_when_confirmed_nearby(): void
    {
        $instrument = Instrument::factory()->create(['yahoo_symbol' => 'TEST', 'quote_currency' => 'USD']);
        $this->seedQuarterlyDividends($instrument->id, 0.50, 'USD');
        $this->seedFxRate('USD', 0.92);

        // Confirmed event very close to where the cadence projection would land.
        Dividend::factory()->create([
            'instrument_id'    => $instrument->id,
            'ex_date'          => now()->addDays(10)->toDateString(),
            'amount_per_share' => 0.50,
            'currency'         => 'USD',
            'confirmed'        => true,
        ]);

        $user   = User::factory()->create();
        $action = $this->makeAction([
            ['instrument_id' => $instrument->id, 'quantity' => 100],
        ]);

        $result = $action->forUser($user);

        // No projected event should overlap with the confirmed one.
        foreach ($result['events'] as $event) {
            $diff = abs(
                \Carbon\Carbon::parse($event['ex_date'])
                    ->diffInDays(\Carbon\Carbon::parse($result['confirmed'][0]['ex_date']))
            );
            $this->assertGreaterThan(20, $diff, "Projected event too close to confirmed event");
        }
    }
}
