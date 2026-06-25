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
        $instrument = Instrument::factory()->create(['name' => 'ASML Holding']);
        Transaction::factory()->for($account)->for($instrument)->create([
            'type' => 'buy',
            'quantity' => 10,
        ]);

        Livewire::actingAs($user)
            ->test(Portfolio::class)
            ->assertSuccessful()
            ->assertSee('ASML Holding')
            ->assertDontSee(__('portfolio.empty.title'));
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
