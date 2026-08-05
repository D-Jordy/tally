<?php

namespace Tests\Feature;

use App\Filament\Pages\Portfolio;
use App\Models\Account;
use App\Models\Instrument;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
