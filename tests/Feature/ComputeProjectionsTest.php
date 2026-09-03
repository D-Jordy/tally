<?php

namespace Tests\Feature;

use App\Actions\ComputeIncomingDividends;
use App\Actions\ComputePortfolio;
use App\Actions\ComputePortfolioHistory;
use App\Actions\ComputeProjections;
use App\Models\Account;
use App\Models\CashMovement;
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

    /**
     * Build a minimal daily history with a deposit on the first day. Four years back by
     * default, so the prior rate is not shrunk towards the long-run default.
     */
    private function oneDepositHistory(float $deposit, float $currentValue, ?string $startDate = null): array
    {
        $startDate ??= now()->subYears(4)->toDateString();

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

    public function test_value_series_has_a_point_per_month(): void
    {
        $user = User::factory()->create();
        $action = $this->makeAction([], 10000, $this->oneDepositHistory(10000, 10000));

        $result = $action->forUser($user, 5);

        $this->assertCount(61, $result['value_series']); // month 0..60
        $this->assertSame(0, $result['value_series'][0]['month']);
        $this->assertSame(60, $result['value_series'][60]['month']);
        $this->assertSame(now()->startOfMonth()->toDateString(), $result['value_series'][0]['date']);
    }

    public function test_value_series_compounds_with_contribution(): void
    {
        $user = User::factory()->create();

        // Final value must differ from the deposit, otherwise XIRR is 0% and this test
        // cannot tell any contribution model apart from another.
        $history = $this->oneDepositHistory(10000, 12000);
        $action = $this->makeAction([], 12000, $history);

        $user->settings = ['monthly_contribution_eur' => 1000];
        $user->save();

        $result = $action->forUser($user, 1);

        $g = $result['growth_rate'];
        $this->assertGreaterThan(0.01, $g);

        // Every contribution is credited the month it lands and compounds from there —
        // no lump sum on 1 Jan, no lump sum on 31 Dec.
        $monthlyRate = (1 + $g) ** (1 / 12) - 1;
        $expected = 12000.0;

        for ($month = 0; $month < 12; $month++) {
            $expected = $expected * (1 + $monthlyRate) + 1000;
        }

        $this->assertEqualsWithDelta($expected, $result['value_series'][12]['projected_value_eur'], 1.0);
        $this->assertNotEqualsWithDelta(12000 * (1 + $g) + 12000, $result['value_series'][12]['projected_value_eur'], 1.0);
    }

    public function test_contributed_series_tracks_deposits_plus_future_contributions(): void
    {
        $user = User::factory()->create();
        $action = $this->makeAction([], 12000, $this->oneDepositHistory(10000, 12000));

        $result = $action->forUser($user, 1, 250.0);

        // Starts at what you actually put in (not the market value), then one step per month.
        $this->assertEqualsWithDelta(10000, $result['value_series'][0]['contributed_eur'], 0.01);
        $this->assertEqualsWithDelta(10000 + 12 * 250, $result['value_series'][12]['contributed_eur'], 0.01);
        $this->assertEqualsWithDelta(10000, $result['invested_eur'], 0.01);
    }

    public function test_a_short_track_record_is_shrunk_towards_the_default_rate(): void
    {
        $user = User::factory()->create();

        // Six months, +40% — annualised that is ~96%, which is a lucky streak, not a forecast.
        $history = $this->oneDepositHistory(10000, 14000, now()->subMonths(6)->toDateString());

        $result = $this->makeAction([], 14000, $history)->forUser($user, 5);

        // 6 months of 3 years' confidence → one sixth of the (capped) XIRR, five sixths default.
        $this->assertEqualsWithDelta(0.07 + (0.20 - 0.07) / 6, $result['prior_rate'], 0.005);
    }

    public function test_prior_rate_is_capped_well_below_a_runaway_xirr(): void
    {
        $user = User::factory()->create();

        // Four years, tripled — a real 31% XIRR, still not something to compound for a decade.
        $history = $this->oneDepositHistory(10000, 30000);

        $result = $this->makeAction([], 30000, $history)->forUser($user, 5);

        $this->assertEqualsWithDelta(0.20, $result['prior_rate'], 0.001);
    }

    public function test_contribution_is_estimated_from_recent_deposits(): void
    {
        $user = User::factory()->create();
        $account = Account::factory()->create(['user_id' => $user->id]);

        // Started depositing four months ago: €400 a month, one month skipped.
        foreach ([4, 3, 1] as $monthsAgo) {
            CashMovement::factory()->create([
                'account_id' => $account->id,
                'type' => 'deposit',
                'currency' => 'EUR',
                'amount' => 400,
                'occurred_at' => now()->startOfMonth()->subMonths($monthsAgo)->addDays(3),
            ]);
        }

        $result = $this->makeAction([], 10000, $this->oneDepositHistory(10000, 10000))->forUser($user, 5);

        // Averaged over the five months since the first deposit, not over twelve.
        $this->assertEqualsWithDelta(1200 / 5, $result['estimated_monthly_contribution_eur'], 0.01);
        $this->assertEqualsWithDelta(1200 / 5, $result['monthly_contribution_eur'], 0.01);

        $this->assertCount(24, $result['deposit_history']);
        $this->assertSame(now()->format('Y-m'), collect($result['deposit_history'])->last()['month']);
        $this->assertEqualsWithDelta(400, collect($result['deposit_history'])->firstWhere('month', now()->subMonths(3)->format('Y-m'))['deposited_eur'], 0.01);
        $this->assertEqualsWithDelta(0, collect($result['deposit_history'])->firstWhere('month', now()->subMonths(2)->format('Y-m'))['deposited_eur'], 0.01);
    }

    public function test_deposit_months_do_not_collapse_at_month_end(): void
    {
        // Regression: stepping months off the 31st overflows (31 Mar -1 month is 3 Mar), so
        // two buckets collapsed into one and February vanished from the chart.
        $this->travelTo('2026-03-31');

        $user = User::factory()->create();
        $result = $this->makeAction([], 10000, $this->oneDepositHistory(10000, 10000))->forUser($user, 5);

        $months = collect($result['deposit_history'])->pluck('month');

        $this->assertCount(24, $months->unique());
        $this->assertSame($months->sort()->values()->all(), $months->all());
        $this->assertSame('2026-02', $months[22]);
        $this->assertSame('2026-03', $months[23]);

        // Same overflow hit the projected dates: every month must be present, in order.
        $dates = collect($result['value_series'])->pluck('date');
        $this->assertCount(61, $dates->unique());
        $this->assertSame('2026-04-01', $dates[1]);
    }

    public function test_a_stored_annual_contribution_still_means_the_same_money(): void
    {
        $user = User::factory()->create();
        $user->settings = ['annual_contribution_eur' => 6000];
        $user->save();

        $result = $this->makeAction([], 10000, $this->oneDepositHistory(10000, 10000))->forUser($user, 5);

        $this->assertEqualsWithDelta(500.0, $result['monthly_contribution_eur'], 0.01);
    }

    public function test_a_small_position_with_a_huge_target_barely_moves_the_rate(): void
    {
        $user = User::factory()->create();

        $moonshot = Instrument::factory()->create(['analyst_target_price' => 200.0]); // +100%
        $ballast = Instrument::factory()->create(['analyst_target_price' => 100.0]);  // flat

        $positions = [
            [   // 2% of the portfolio, analysts see it doubling
                'instrument_id' => $moonshot->id,
                'latest_price' => 100.0,
                'current_value_eur' => 200.0,
            ],
            [   // 98% of the portfolio, analysts see no upside
                'instrument_id' => $ballast->id,
                'latest_price' => 100.0,
                'current_value_eur' => 9800.0,
            ],
        ];

        $result = $this->makeAction($positions, 10000, $this->oneDepositHistory(10000, 11000))
            ->forUser($user, 5);

        // Two brakes, in order: the +100% target is first clipped to the +50% per-holding
        // ceiling, then weighted by the position's 2% share — 2% * 50% + 98% * 0% = 1%.
        // A fat target on a rounding-error position cannot drag the whole portfolio up.
        $this->assertEqualsWithDelta(0.01, $result['analyst_rate'], 0.001);
    }

    public function test_reinvesting_dividends_compounds_them_into_the_value(): void
    {
        $user = User::factory()->create();
        $history = $this->oneDepositHistory(10000, 12000);

        $payOut = $this->makeAction([], 12000, $history, 600.0)->forUser($user, 10, 0.0, false);
        $reinvest = $this->makeAction([], 12000, $history, 600.0)->forUser($user, 10, 0.0, true);

        $endValue = fn (array $r): float => (float) collect($r['value_series'])->last()['projected_value_eur'];
        $endDividend = fn (array $r): float => (float) collect($r['dividend_series'])->last()['projected_dividends_eur'];

        // Reinvested income buys more capital, which then throws off more income.
        $this->assertGreaterThan($endValue($payOut), $endValue($reinvest));
        $this->assertGreaterThan($endDividend($payOut), $endDividend($reinvest));

        // It has to compound, not just add up: ten years of ~5% income reinvested must beat
        // simply banking that income at year 10 without ever putting it to work.
        // /12 because each point is the annualised run-rate, not that month's payout.
        $bankedIncome = collect($payOut['dividend_series'])->skip(1)->sum('projected_dividends_eur') / 12;
        $this->assertGreaterThan($endValue($payOut) + $bankedIncome, $endValue($reinvest));

        // Off by default, so the payout projection is unchanged for anyone who never toggles it.
        $this->assertSame($payOut['value_series'], $this->makeAction([], 12000, $history, 600.0)->forUser($user, 10, 0.0)['value_series']);
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
        // The series reports the annualised run-rate at each month, so it compounds monthly.
        $this->assertEqualsWithDelta(500.0, $result['dividend_series'][0]['projected_dividends_eur'], 0.1);
        $this->assertEqualsWithDelta(500 * (1 + $g) ** (1 / 12), $result['dividend_series'][1]['projected_dividends_eur'], 1.0);
        $this->assertEqualsWithDelta(500 * (1 + $g), $result['dividend_series'][12]['projected_dividends_eur'], 1.0);
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
        $series = collect($result['value_series'])->pluck('projected_value_eur', 'month');

        // Year-on-year growth must shrink each year as the analyst share halves...
        $growth = fn (int $year): float => $series[$year * 12] / $series[($year - 1) * 12] - 1;

        $this->assertGreaterThan($growth(2), $growth(1));
        $this->assertGreaterThan($growth(3), $growth(2));

        // ...and by year 10 the analyst share is ~0.05%, so growth is essentially the XIRR.
        $this->assertEqualsWithDelta($prior, $growth(10), 0.005);

        // A 12-month target must not compound as a decade-long rate: the headline figure is
        // the effective CAGR, so it sits well under the month-1 blend of prior/analyst.
        $monthOneBlend = 0.75 * $prior + 0.25 * $result['analyst_rate'];
        $this->assertLessThan($monthOneBlend, $result['growth_rate']);
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
        // Checked with income reinvested too: capital then compounds at (1 + rate)(1 + yield),
        // so a headline carrying only the price rate would understate its own figure.
        foreach ([false, true] as $reinvest) {
            $result = $this->makeAction($positions, 10000, $this->oneDepositHistory(10000, 11000), 500.0)
                ->forUser($user, 5, 0.0, $reinvest);

            $start = (float) $result['starting_value_eur'];
            $end = (float) collect($result['value_series'])->last()['projected_value_eur'];

            // Delta absorbs the reported rate being rounded to 4dp before we compound it back.
            $this->assertEqualsWithDelta(
                $end,
                $start * (1 + $result['growth_rate']) ** 5,
                $end * 0.0005,
                'growth_rate must reproduce the projection with reinvest '.var_export($reinvest, true),
            );
        }

        // And reinvesting has to actually show up in the headline, not just in the figure.
        $payOut = $this->makeAction($positions, 10000, $this->oneDepositHistory(10000, 11000), 500.0)->forUser($user, 5, 0.0, false);
        $reinvested = $this->makeAction($positions, 10000, $this->oneDepositHistory(10000, 11000), 500.0)->forUser($user, 5, 0.0, true);

        $this->assertGreaterThan($payOut['growth_rate'], $reinvested['growth_rate']);
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
        // growth_rate is a decaying blend — should be between prior_rate and analyst_rate.
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
        $this->assertCount(37, $result['value_series']);
    }
}
