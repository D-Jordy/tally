<?php

namespace Tests\Feature;

use App\Filament\Pages\Dividends;
use App\Models\Account;
use App\Models\Dividend;
use App\Models\Instrument;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class FilamentDividendsPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_renders_without_holdings(): void
    {
        $user = User::factory()->create();

        Livewire::actingAs($user)
            ->test(Dividends::class)
            ->assertSuccessful();
    }

    public function test_shows_a_confirmed_dividend(): void
    {
        $user = User::factory()->create();
        $account = Account::factory()->for($user)->create();
        $instrument = Instrument::factory()->create(['name' => 'Royal Dutch Shell']);
        Transaction::factory()->for($account)->for($instrument)->create(['type' => 'buy', 'quantity' => 100]);
        Dividend::factory()->for($instrument)->create([
            'ex_date' => now()->addMonth()->toDateString(),
            'amount_per_share' => 0.50,
            'currency' => 'EUR',
            'confirmed' => true,
        ]);

        Livewire::actingAs($user)
            ->test(Dividends::class)
            ->assertSuccessful()
            ->assertSee('Royal Dutch Shell')
            ->assertSee('CONFIRMED');
    }
}
