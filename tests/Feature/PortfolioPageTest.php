<?php

namespace Tests\Feature;

use App\Actions\ComputePortfolio;
use App\Filament\Pages\Portfolio;
use App\Filament\Widgets\PositionsTable;
use App\Models\Account;
use App\Models\Instrument;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Number;
use Livewire\Livewire;
use Tests\TestCase;

class PortfolioPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_shows_the_empty_state_without_positions(): void
    {
        $user = User::factory()->create();

        Livewire::actingAs($user)
            ->test(Portfolio::class)
            ->assertSuccessful()
            ->assertSee(__('portfolio.empty.title'));
    }

    public function test_lists_open_positions_with_a_summary(): void
    {
        $user = User::factory()->create();
        $account = Account::factory()->for($user)->create();
        $instrument = Instrument::factory()->create(['name' => 'ASML Holding', 'yahoo_symbol' => 'ASML.AS']);
        Transaction::factory()->for($account)->for($instrument)->create([
            'type' => 'buy',
            'quantity' => 10,
        ]);

        Livewire::actingAs($user)
            ->test(Portfolio::class)
            ->assertSuccessful()
            ->assertSee('ASML Holding')
            ->assertSee('/instruments/ASML.AS')
            ->assertDontSee(__('portfolio.empty.title'));

        // The chart widget only blows up on the real route, never under Livewire::test.
        $this->actingAs($user)
            ->get(Portfolio::getUrl())
            ->assertSuccessful()
            ->assertSee('ASML Holding');
    }

    public function test_the_positions_table_sorts_on_a_computed_column(): void
    {
        $user = User::factory()->create();
        $account = Account::factory()->for($user)->create();

        foreach (['Small' => 1, 'Large' => 100] as $name => $quantity) {
            $instrument = Instrument::factory()->create(['name' => $name]);
            Transaction::factory()->for($account)->for($instrument)->create([
                'type' => 'buy',
                'quantity' => $quantity,
                'price' => 10,
            ]);
        }

        $positions = app(ComputePortfolio::class)->forUser($user)['positions'];

        // Sorting is ours to do — these rows never saw an ORDER BY.
        Livewire::actingAs($user)
            ->test(PositionsTable::class, ['rows' => $positions])
            ->sortTable('quantity', 'desc')
            ->assertSeeInOrder(['Large', 'Small'])
            ->sortTable('quantity')
            ->assertSeeInOrder(['Small', 'Large']);
    }

    public function test_amounts_use_currency_symbols_and_keep_the_percentage_on_one_line(): void
    {
        $user = User::factory()->create();
        $instrument = Instrument::factory()->create(['name' => 'Apple', 'yahoo_symbol' => 'AAPL']);

        // Numbers are euro-notation whatever the UI language is; Filament's own
        // money() reads config('app.locale'), so English is the case that breaks.
        $this->app->setLocale('en');

        Livewire::actingAs($user)
            ->test(PositionsTable::class, ['rows' => [[
                'instrument_id' => $instrument->id,
                'yahoo_symbol' => 'AAPL',
                'name' => 'Apple',
                'quantity' => 10.0,
                'avg_cost_per_share' => 120.5,
                'price_currency' => 'USD',
                'latest_price' => 150.25,
                'latest_price_currency' => 'USD',
                'current_value_eur' => 1380.0,
                'unrealized_gain_eur' => 274.0,
                'unrealized_gain_pct' => 0.226,
                'dividend_eur' => 12.0,
            ]]])
            ->assertSuccessful()
            // Prices are money: two decimals, never trimmed to "$ 120,5".
            ->assertSee('$ 120,50')
            ->assertSee('(+22,6%)')
            ->assertSee(Number::currency(1380, 'EUR'))
            ->assertDontSee('1,380.00')
            ->assertDontSee('USD');
    }

    public function test_renders_the_summary_kpi_stats(): void
    {
        $user = User::factory()->create();

        Livewire::actingAs($user)
            ->test(Portfolio::class)
            ->assertSuccessful()
            ->assertSee(__('portfolio.kpi.market_value'))
            ->assertSee(__('portfolio.kpi.realized'));
    }

    public function test_mode_and_range_controls_drive_the_chart(): void
    {
        $user = User::factory()->create();

        Livewire::actingAs($user)
            ->test(Portfolio::class)
            ->set('mode', 'pl')
            ->set('range', '6M')
            ->assertSuccessful()
            ->assertSet('mode', 'pl')
            ->assertSet('range', '6M');
    }

    /** The one-year button used to be a hardcoded Dutch "1J", in both languages. */
    public function test_range_labels_follow_the_ui_language(): void
    {
        $user = User::factory()->create();

        Livewire::actingAs($user)
            ->test(Portfolio::class)
            ->assertSee('1Y')
            ->assertDontSee('1J');

        app()->setLocale('nl');

        Livewire::actingAs($user)
            ->test(Portfolio::class)
            ->assertSee('1J')
            ->assertSee('ALLES');
    }

    public function test_lists_closed_positions_below_the_open_ones(): void
    {
        $user = User::factory()->create();
        $account = Account::factory()->for($user)->create();
        $instrument = Instrument::factory()->create(['name' => 'Banco de Sabadell', 'yahoo_symbol' => 'SAB.MC']);

        foreach (['buy', 'sell'] as $type) {
            Transaction::factory()->for($account)->for($instrument)->create([
                'type' => $type,
                'quantity' => 100,
                'price_currency' => 'EUR',
                'trade_currency' => 'EUR',
                'fx_rate_to_eur' => null,
                'local_value' => 100,
                'value_eur' => 100,
                'total_eur' => $type === 'buy' ? 100 : 150,
                'executed_at' => $type === 'buy' ? '2024-01-02 10:00:00' : '2024-06-02 10:00:00',
            ]);
        }

        Livewire::actingAs($user)
            ->test(Portfolio::class)
            ->assertSuccessful()
            ->assertSee(__('portfolio.closed.title'))
            ->assertSee('Banco de Sabadell')
            ->assertSee('/instruments/SAB.MC');
    }

    public function test_shows_an_empty_closed_section_when_nothing_was_ever_sold(): void
    {
        $user = User::factory()->create();
        $account = Account::factory()->for($user)->create();
        $instrument = Instrument::factory()->create(['name' => 'ASML Holding']);
        Transaction::factory()->for($account)->for($instrument)->create(['type' => 'buy', 'quantity' => 10]);

        Livewire::actingAs($user)
            ->test(Portfolio::class)
            ->assertSuccessful()
            ->assertSee(__('portfolio.closed.empty'));
    }

    public function test_does_not_leak_another_users_positions(): void
    {
        $user = User::factory()->create();
        $other = Account::factory()->create();
        $instrument = Instrument::factory()->create(['name' => 'Vreemde Positie']);
        Transaction::factory()->for($other)->for($instrument)->create(['type' => 'buy', 'quantity' => 5]);

        Livewire::actingAs($user)
            ->test(Portfolio::class)
            ->assertSuccessful()
            ->assertDontSee('Vreemde Positie')
            ->assertSee(__('portfolio.empty.title'));
    }
}
