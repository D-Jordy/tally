<?php

namespace Tests\Feature;

use App\Actions\ComputeIncomingDividends;
use App\Actions\ComputePortfolio;
use App\Actions\ComputePortfolioHistory;
use App\Actions\ComputeProjections;
use App\Models\Instrument;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ComputeProjectionsTest extends TestCase
{
    use RefreshDatabase;

    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    /**
     * Build a ComputeProjections with all three dependencies mocked.
     *
     * @param  array  $positions  ComputePortfolio positions list
     * @param  float  $totalValueEur  ComputePortfolio summary total_value_eur
     * @param  array  $history  ComputePortfolioHistory daily records
     * @param  float  $next12mDivEur  ComputeIncomingDividends summary.next_12m_total_eur
     */
    private function makeAction(
        array $positions,
        float $totalValueEur,
        array $history,
        float $next12mDivEur = 0.0,
    ): ComputeProjections {
        $this->mock(ComputePortfolio::class)
            ->shouldReceive('forUser')
            ->andReturn([
                'positions' => $positions,
                'summary' => ['total_value_eur' => $totalValueEur],
            ]);

        $this->mock(ComputePortfolioHistory::class)
            ->shouldReceive('forUser')
            ->andReturn($history);

        $this->mock(ComputeIncomingDividends::class)
            ->shouldReceive('forUser')
            ->andReturn([
                'confirmed' => [],
                'events' => [],
                'monthly' => [],
                'summary' => ['next_12m_total_eur' => $next12mDivEur],
            ]);

        return app(ComputeProjections::class);
    }

    /** Build a minimal daily history with a deposit on the first day. */
    private function oneDepositHistory(float $deposit, float $currentValue, string $startDate = '2023-01-01'): array
    {
        return [
            // Day of deposit: net_gain = totalValue - cumDeposits => 0 - deposit... but actually
            // cumDeposits = deposit, net_gain = total_value - deposit = 0 initially (buy on day 1).
            ['date' => $startDate, 'total_value_eur' => $deposit, 'net_gain_eur' => 0.0,
                'cumulative_dividends_eur' => 0.0, 'cumulative_fees_eur' => 0.0],
            // Current day: portfolio has grown.
            ['date' => now()->toDateString(), 'total_value_eur' => $currentValue,
                'net_gain_eur' => $currentValue - $deposit,
                'cumulative_dividends_eur' => 0.0, 'cumulative_fees_eur' => 0.0],
        ];
    }

    // -----------------------------------------------------------------------
    // Tests
    // -----------------------------------------------------------------------

    public function test_value_series_has_horizon_plus_one_points(): void
    {
        $user = User::factory()->create();
        $action = $this->makeAction([], 10000, $this->oneDepositHistory(10000, 10000));

        $result = $action->forUser($user, 5);

        $this->assertCount(6, $result['value_series']); // year 0..5
        $this->assertSame(0, $result['value_series'][0]['year']);
        $this->assertSame(5, $result['value_series'][5]['year']);
    }

    public function test_value_series_compounds_with_contribution(): void
    {
        $user = User::factory()->create();

        // Final value must differ from the deposit, otherwise XIRR is 0% and this test
        // cannot tell any contribution model apart from another.
        $history = $this->oneDepositHistory(10000, 12000);
        $action = $this->makeAction([], 12000, $history);

        $user->settings = ['annual_contribution_eur' => 1000];
        $user->save();

        $result = $action->forUser($user, 1);

        $g = $result['growth_rate'];
        $this->assertGreaterThan(0.01, $g);

        // Contributions land through the year, so they earn about half a year of growth —
        // not zero (added on 31 Dec) and not a full year (added on 1 Jan).
        $midYear = 12000 * (1 + $g) + 1000 * (1 + $g / 2);
        $endOfYear = 12000 * (1 + $g) + 1000;

        $this->assertEqualsWithDelta($midYear, $result['value_series'][1]['projected_value_eur'], 1.0);
        $this->assertNotEqualsWithDelta($endOfYear, $result['value_series'][1]['projected_value_eur'], 1.0);
    }

    public function test_dividends_scale_with_the_capital_contributed(): void
    {
        $user = User::factory()->create();
        $history = $this->oneDepositHistory(10000, 12000);

        // €600 of income on a €12k portfolio → a 5% yield that must hold as the pot grows.
        $withoutContribution = $this->makeAction([], 12000, $history, 600.0)->forUser($user, 10, 0.0);
        $withContribution = $this->makeAction([], 12000, $history, 600.0)->forUser($user, 10, 5000.0);

        $endValue = fn (array $result): float => (float) collect($result['value_series'])->last()['projected_value_eur'];
        $endDividend = fn (array $result): float => (float) collect($result['dividend_series'])->last()['projected_dividends_eur'];

        // Regression: dividends used to compound off the starting figure alone, so depositing
        // €5k a year grew the portfolio but threw off exactly as much income as depositing €0.
        $this->assertGreaterThan($endValue($withoutContribution), $endValue($withContribution));
        $this->assertGreaterThan($endDividend($withoutContribution), $endDividend($withContribution));

        // Income tracks the capital: same yield on a bigger pot.
        $this->assertEqualsWithDelta(
            $endDividend($withContribution) / $endValue($withContribution),
            $endDividend($withoutContribution) / $endValue($withoutContribution),
            0.0001,
        );
    }

    public function test_dividend_series_grows_at_same_rate(): void
    {
        $user = User::factory()->create();
        $action = $this->makeAction([], 10000, $this->oneDepositHistory(10000, 12000), 500.0);

        $result = $action->forUser($user, 3);

        $g = $result['growth_rate'];
        $this->assertEqualsWithDelta(500.0, $result['dividend_series'][0]['projected_dividends_eur'], 0.1);
        $this->assertEqualsWithDelta(500 * (1 + $g), $result['dividend_series'][1]['projected_dividends_eur'], 1.0);
    }

    public function test_no_analyst_data_means_growth_equals_prior_rate(): void
    {
        $user = User::factory()->create();
        $instrument = Instrument::factory()->create(['analyst_target_price' => null]);

        $positions = [[
            'instrument_id' => $instrument->id,
            'latest_price' => 100.0,
            'current_value_eur' => 5000.0,
        ]];

        $action = $this->makeAction($positions, 5000, $this->oneDepositHistory(5000, 6000));
        $result = $action->forUser($user, 5);

        // With no analyst data, analyst_rate should equal prior_rate and growth == prior_rate.
        $this->assertEqualsWithDelta($result['prior_rate'], $result['analyst_rate'], 0.001);
        $this->assertEqualsWithDelta($result['prior_rate'], $result['growth_rate'], 0.001);
    }

    public function test_analyst_weight_decays_towards_the_prior_rate(): void
    {
        $user = User::factory()->create();
        $instrument = Instrument::factory()->create(['analyst_target_price' => 150.0]);

        $positions = [[
            'instrument_id' => $instrument->id,
            'latest_price' => 100.0,   // 50% upside → analyst implied = 0.50
            'current_value_eur' => 10000.0,
        ]];

        $action = $this->makeAction($positions, 10000, $this->oneDepositHistory(10000, 11000));
        $result = $action->forUser($user, 10);

        $prior = $result['prior_rate'];
        $series = collect($result['value_series'])->pluck('projected_value_eur', 'year');

        // Year-on-year growth must shrink each year as the analyst share halves...
        $growth = fn (int $year): float => $series[$year] / $series[$year - 1] - 1;

        $this->assertGreaterThan($growth(2), $growth(1));
        $this->assertGreaterThan($growth(3), $growth(2));

        // ...and by year 10 the analyst share is ~0.1%, so growth is essentially the XIRR.
        $this->assertEqualsWithDelta($prior, $growth(10), 0.005);

        // A 12-month target must not compound as a decade-long rate: the headline figure is
        // the effective CAGR, so it sits well under the year-1 blend of prior/analyst.
        $yearOneBlend = 0.5 * $prior + 0.5 * $result['analyst_rate'];
        $this->assertLessThan($yearOneBlend, $result['growth_rate']);
    }

    public function test_growth_rate_is_the_cagr_that_reproduces_the_projection(): void
    {
        $user = User::factory()->create();
        $instrument = Instrument::factory()->create(['analyst_target_price' => 150.0]);

        $positions = [[
            'instrument_id' => $instrument->id,
            'latest_price' => 100.0,
            'current_value_eur' => 10000.0,
        ]];

        // No contribution, so the series is pure compounding and the CAGR must land on it.
        $action = $this->makeAction($positions, 10000, $this->oneDepositHistory(10000, 11000));
        $result = $action->forUser($user, 5, 0.0);

        $start = (float) $result['starting_value_eur'];
        $end = (float) collect($result['value_series'])->last()['projected_value_eur'];

        $this->assertEqualsWithDelta($end, $start * (1 + $result['growth_rate']) ** 5, 1.0);
    }

    public function test_analyst_target_influences_blended_rate(): void
    {
        $user = User::factory()->create();
        $instrument = Instrument::factory()->create(['analyst_target_price' => 150.0]);

        $positions = [[
            'instrument_id' => $instrument->id,
            'latest_price' => 100.0,   // 50% upside → analyst implied = 0.50
            'current_value_eur' => 10000.0,
        ]];

        $action = $this->makeAction($positions, 10000, $this->oneDepositHistory(10000, 11000));
        $result = $action->forUser($user, 5);

        // analyst_rate should reflect the 50% implied return.
        $this->assertEqualsWithDelta(0.50, $result['analyst_rate'], 0.01);
        // growth_rate is 50/50 blend — should be between prior_rate and analyst_rate.
        $this->assertGreaterThanOrEqual($result['prior_rate'], $result['growth_rate']);
        $this->assertLessThanOrEqual($result['analyst_rate'], $result['growth_rate']);
    }

    public function test_null_xirr_falls_back_to_default_rate(): void
    {
        $user = User::factory()->create();
        // Empty history → XIRR cannot be computed.
        $action = $this->makeAction([], 0, []);

        $result = $action->forUser($user, 5);

        // prior_rate defaults to 7% when XIRR unavailable.
        $this->assertEqualsWithDelta(0.07, $result['prior_rate'], 0.001);
    }

    public function test_growth_rate_is_clamped_to_sane_bounds(): void
    {
        $user = User::factory()->create();
        $instrument = Instrument::factory()->create(['analyst_target_price' => 10000.0]); // absurd upside

        $positions = [[
            'instrument_id' => $instrument->id,
            'latest_price' => 1.0,
            'current_value_eur' => 5000.0,
        ]];

        $action = $this->makeAction($positions, 5000, $this->oneDepositHistory(5000, 6000));
        $result = $action->forUser($user, 5);

        $this->assertLessThanOrEqual(0.50, $result['growth_rate']);
        $this->assertGreaterThanOrEqual(-0.50, $result['growth_rate']);
    }

    public function test_starting_value_and_horizon_years_are_returned(): void
    {
        $user = User::factory()->create();
        $action = $this->makeAction([], 25000, $this->oneDepositHistory(25000, 25000));

        $result = $action->forUser($user, 3);

        $this->assertSame(3, $result['horizon_years']);
        $this->assertEqualsWithDelta(25000, $result['starting_value_eur'], 1.0);
        $this->assertSame(25000.0, $result['value_series'][0]['projected_value_eur']);
    }
}
