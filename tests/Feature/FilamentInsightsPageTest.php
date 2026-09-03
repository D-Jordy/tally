<?php

namespace Tests\Feature;

use App\Actions\ComputeProjections;
use App\Filament\Pages\Insights;
use App\Models\Account;
use App\Models\CashMovement;
use App\Models\Dividend;
use App\Models\Instrument;
use App\Models\PriceHistory;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Number;
use Livewire\Livewire;
use Tests\TestCase;

class FilamentInsightsPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_renders_with_default_horizon(): void
    {
        $user = User::factory()->create();
        $this->hold($user);

        Livewire::actingAs($user)
            ->test(Insights::class)
            ->assertSuccessful()
            ->assertSee(__('projections.kpi.expected', ['years' => 5]));
    }

    public function test_horizon_toggle_updates_the_kpi_label(): void
    {
        $user = User::factory()->create();
        $this->hold($user);

        Livewire::actingAs($user)
            ->test(Insights::class)
            ->set('horizon', 10)
            ->assertSee(__('projections.kpi.expected', ['years' => 10]));
    }

    /** Zeroed KPIs and a flat projection say less than nothing to a brand-new user. */
    public function test_shows_the_empty_state_without_positions(): void
    {
        $user = User::factory()->create();

        Livewire::actingAs($user)
            ->test(Insights::class)
            ->assertSuccessful()
            ->assertSee(__('insights.empty.title'))
            ->assertDontSee(__('projections.kpi.expected', ['years' => 5]));
    }

    public function test_monthly_contribution_persists_to_user_settings(): void
    {
        $user = User::factory()->create();
        $this->hold($user);

        Livewire::actingAs($user)
            ->test(Insights::class)
            ->set('monthlyContribution', 500)
            ->assertSuccessful();

        $this->assertSame(500.0, (float) $user->fresh()->settings['monthly_contribution_eur']);
    }

    public function test_the_contribution_field_starts_at_what_you_actually_deposit(): void
    {
        $user = User::factory()->create();
        $account = $this->hold($user);

        foreach ([2, 1] as $monthsAgo) {
            CashMovement::factory()->create([
                'account_id' => $account->id,
                'type' => 'deposit',
                'currency' => 'EUR',
                'amount' => 300,
                'occurred_at' => now()->subMonths($monthsAgo)->startOfMonth()->addDays(2),
            ]);
        }

        Livewire::actingAs($user)
            ->test(Insights::class)
            ->assertSet('monthlyContribution', 200.0)     // €600 over the three months since the first deposit
            ->assertSee(__('projections.contributions.heading'));

        // The chart widgets only blow up on the real route, never under Livewire::test.
        $this->actingAs($user)
            ->get(Insights::getUrl())
            ->assertSuccessful()
            ->assertSee(__('projections.contributions.heading'));
    }

    public function test_allocation_weights_positions_and_buckets_sectors(): void
    {
        $user = User::factory()->create();
        $account = Account::factory()->for($user)->create();

        $tech = Instrument::factory()->create(['name' => 'ASML', 'sector' => 'Technology']);
        $etf = Instrument::factory()->create(['name' => 'Vanguard All-World', 'sector' => null]);

        $this->buy($account, $tech, 10, 90);  // value 900 @ close 90
        $this->buy($account, $etf, 10, 30);   // value 300 @ close 30
        $this->price($tech, 90);
        $this->price($etf, 30);

        $allocation = Livewire::actingAs($user)->test(Insights::class)->get('allocation');

        $this->assertEqualsWithDelta(1200.0, $allocation['total_eur'], 0.01);

        // Sectors: named sector + null bucketed under the "other" label, ordered by value.
        $sectors = collect($allocation['sectors'])->keyBy('sector');
        $this->assertEqualsWithDelta(0.75, $sectors['Technology']['weight'], 0.0001);
        $this->assertEqualsWithDelta(0.25, $sectors[__('insights.allocation.other')]['weight'], 0.0001);

        // Positions sorted by value, weights sum to ~1.
        $this->assertSame('ASML', $allocation['positions'][0]['name']);
        $this->assertEqualsWithDelta(1.0, collect($allocation['positions'])->sum('weight'), 0.0001);
    }

    public function test_sector_allocation_lists_its_holdings(): void
    {
        $user = User::factory()->create();
        $account = Account::factory()->for($user)->create();

        $big = Instrument::factory()->create(['name' => 'ASML', 'sector' => 'Technology']);
        $small = Instrument::factory()->create(['name' => 'Adyen', 'sector' => 'Technology']);

        $this->buy($account, $big, 10, 90);
        $this->price($big, 90);
        $this->buy($account, $small, 10, 30);
        $this->price($small, 30);

        $allocation = Livewire::actingAs($user)->test(Insights::class)->get('allocation');
        $sectors = collect($allocation['sectors'])->keyBy('sector');

        // Ordered by value, so the tooltip reads biggest holding first.
        $this->assertSame(['ASML', 'Adyen'], $sectors['Technology']['holdings']);
    }

    public function test_reinvest_toggle_persists_and_lifts_the_projection(): void
    {
        $user = User::factory()->create();
        $account = Account::factory()->for($user)->create();
        $instrument = Instrument::factory()->create(['name' => 'ASML']);
        $this->buy($account, $instrument, 10, 90);
        $this->price($instrument, 90);

        // Without income there is nothing to reinvest and both projections are identical,
        // so the portfolio has to actually pay a dividend for this test to mean anything.
        Dividend::factory()->for($instrument)->create([
            'ex_date' => now()->addMonth()->toDateString(),
            'amount_per_share' => 2.50,
            'currency' => 'EUR',
            'confirmed' => true,
        ]);

        $projected = fn (bool $reinvest): string => Number::currency(
            (float) end(app(ComputeProjections::class)->forUser($user->fresh(), 10, 0.0, $reinvest)['value_series'])['projected_value_eur'],
            'EUR',
        );

        Livewire::actingAs($user)
            ->test(Insights::class)
            ->set('monthlyContribution', 0)
            ->set('horizon', 10)
            ->assertSee($projected(false))
            ->set('reinvestDividends', true)
            ->assertSee($projected(true))       // toggling must recompute, not lag a click behind
            ->assertDontSee($projected(false));

        $this->assertTrue((bool) $user->fresh()->settings['reinvest_dividends']);
    }

    public function test_horizon_toggle_recomputes_the_projected_value(): void
    {
        $user = User::factory()->create();
        $account = Account::factory()->for($user)->create();
        $instrument = Instrument::factory()->create(['name' => 'ASML']);
        $this->buy($account, $instrument, 10, 90);
        $this->price($instrument, 90);

        // The value the action independently says each horizon should project to.
        $expected = function (int $years) use ($user): string {
            $series = app(ComputeProjections::class)->forUser($user->fresh(), $years)['value_series'];

            return Number::currency((float) end($series)['projected_value_eur'], 'EUR');
        };

        $this->assertNotSame($expected(1), $expected(10));

        // Regression: the stat used to render one horizon behind, because Filament builds
        // the schema before the `updated` hook that refreshed the stored projection ran.
        Livewire::actingAs($user)
            ->test(Insights::class)
            ->set('horizon', 10)
            ->assertSee($expected(10))
            ->set('horizon', 1)
            ->assertSee($expected(1))
            ->assertDontSee($expected(10));
    }

    public function test_allocation_labels_positions_with_the_ticker(): void
    {
        $user = User::factory()->create();
        $account = Account::factory()->for($user)->create();

        // Mirrors a real DEGIRO import: `symbol` is never filled, only `yahoo_symbol`
        // gets resolved — and it carries an exchange suffix.
        $imported = Instrument::factory()->create([
            'name' => 'NN Group NV',
            'symbol' => null,
            'yahoo_symbol' => 'NN.AS',
        ]);
        $seeded = Instrument::factory()->create([
            'name' => 'ASML Holding',
            'symbol' => 'ASML',
            'yahoo_symbol' => 'ASML.AS',
        ]);
        $unresolved = Instrument::factory()->create([
            'name' => 'Mystery Fund',
            'symbol' => null,
            'yahoo_symbol' => null,
        ]);

        $this->buy($account, $imported, 10, 90);
        $this->price($imported, 90);
        $this->buy($account, $seeded, 10, 50);
        $this->price($seeded, 50);
        $this->buy($account, $unresolved, 10, 10);
        $this->price($unresolved, 10);

        $allocation = Livewire::actingAs($user)->test(Insights::class)->get('allocation');
        $symbols = collect($allocation['positions'])->pluck('symbol', 'name');

        $this->assertSame('NN', $symbols['NN Group NV']);        // suffix stripped
        $this->assertSame('ASML', $symbols['ASML Holding']);      // explicit symbol wins
        $this->assertSame('Mystery Fund', $symbols['Mystery Fund']); // falls back to the name
    }

    /** One priced position, so the page renders its charts instead of the empty state. */
    private function hold(User $user): Account
    {
        $account = Account::factory()->for($user)->create();
        $instrument = Instrument::factory()->create(['name' => 'ASML']);

        $this->buy($account, $instrument, 10, 90);
        $this->price($instrument, 90);

        return $account;
    }

    private function buy(Account $account, Instrument $instrument, float $qty, float $price): void
    {
        $local = $qty * $price;

        Transaction::create([
            'account_id' => $account->id,
            'instrument_id' => $instrument->id,
            'executed_at' => '2024-01-02 10:00:00',
            'type' => 'buy',
            'quantity' => $qty,
            'price' => $price,
            'price_currency' => 'EUR',
            'fee' => 0,
            'trade_currency' => 'EUR',
            'fx_rate_to_eur' => null,
            'local_value' => $local,
            'value_eur' => $local,
            'total_eur' => $local,
            'source' => 'import',
            'external_id' => 'buy-'.uniqid(),
        ]);
    }

    private function price(Instrument $instrument, float $close): void
    {
        PriceHistory::create([
            'instrument_id' => $instrument->id,
            'date' => '2024-12-31',
            'close' => $close,
            'currency' => 'EUR',
        ]);
    }
}
