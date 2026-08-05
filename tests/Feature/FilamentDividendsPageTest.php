<?php

namespace Tests\Feature;

use App\Actions\ProjectDividends;
use App\Filament\Pages\Dividends;
use App\Filament\Widgets\DividendCalendarTable;
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

    public function test_the_calendar_shows_a_confirmed_dividend_with_both_dates(): void
    {
        $user = User::factory()->create();
        $account = Account::factory()->for($user)->create();
        $instrument = Instrument::factory()->create(['name' => 'Royal Dutch Shell', 'yahoo_symbol' => 'SHEL.AS']);
        Transaction::factory()->for($account)->for($instrument)->create(['type' => 'buy', 'quantity' => 100]);

        $exDate = now()->addMonth();
        Dividend::factory()->for($instrument)->create([
            'ex_date' => $exDate->toDateString(),
            'pay_date' => $exDate->copy()->addWeeks(2)->toDateString(),
            'amount_per_share' => 0.50,
            'currency' => 'EUR',
            'confirmed' => true,
        ]);

        // The whole page: the widgets only render on the real route, not under
        // Livewire::test of the page itself.
        $this->actingAs($user)
            ->get(Dividends::getUrl())
            ->assertSuccessful()
            ->assertSee('Royal Dutch Shell')
            ->assertSee(__('dividends.sections.calendar'))
            ->assertSee(__('dividends.badge.confirmed'))
            ->assertSee($exDate->format('d-m-Y'))
            ->assertSee($exDate->copy()->addWeeks(2)->format('d-m-Y'));
    }

    public function test_the_calendar_lists_projections_next_to_confirmed_rows(): void
    {
        $user = User::factory()->create();
        $account = Account::factory()->for($user)->create();
        $instrument = Instrument::factory()->create(['name' => 'NN Group']);
        Transaction::factory()->for($account)->for($instrument)->create(['type' => 'buy', 'quantity' => 10]);

        // Two past payments give the projector a cadence to run on.
        foreach ([12, 6] as $monthsAgo) {
            Dividend::factory()->for($instrument)->create([
                'ex_date' => now()->subMonths($monthsAgo)->toDateString(),
                'amount_per_share' => 1.00,
                'currency' => 'EUR',
            ]);
        }

        app(ProjectDividends::class)->forInstrument($instrument);

        $expected = Livewire::actingAs($user)->test(Dividends::class)->get('expectedEur');
        $projections = Dividend::where('projected', true)->get();

        $this->assertNotEmpty($projections);
        // Every projected row is priced for this holding: 10 × € 1,00.
        $this->assertSame([10.0], $projections->map(fn (Dividend $row): ?float => $expected[$row->id])->unique()->all());

        Livewire::actingAs($user)
            ->test(DividendCalendarTable::class, ['expectedEur' => $expected])
            ->assertSuccessful()
            ->assertCanSeeTableRecords($projections)
            ->assertSee(__('dividends.badge.estimate'));
    }

    public function test_the_calendar_orders_on_the_date_the_money_arrives(): void
    {
        $user = User::factory()->create();
        $account = Account::factory()->for($user)->create();
        $instrument = Instrument::factory()->create();
        Transaction::factory()->for($account)->for($instrument)->create(['type' => 'buy', 'quantity' => 100]);

        // Goes ex first but pays last: the ex-date order would put it on top.
        $late = Dividend::factory()->for($instrument)->create([
            'ex_date' => now()->addDays(10)->toDateString(),
            'pay_date' => now()->addDays(90)->toDateString(),
            'amount_per_share' => 0.50,
            'currency' => 'EUR',
            'confirmed' => true,
        ]);

        $early = Dividend::factory()->for($instrument)->create([
            'ex_date' => now()->addDays(20)->toDateString(),
            'pay_date' => now()->addDays(30)->toDateString(),
            'amount_per_share' => 0.50,
            'currency' => 'EUR',
            'confirmed' => true,
        ]);

        $expected = Livewire::actingAs($user)->test(Dividends::class)->get('expectedEur');

        Livewire::actingAs($user)
            ->test(DividendCalendarTable::class, ['expectedEur' => $expected])
            ->assertSuccessful()
            ->assertCanSeeTableRecords([$early, $late], inOrder: true);
    }
}
