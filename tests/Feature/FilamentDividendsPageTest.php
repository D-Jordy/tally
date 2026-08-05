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
        $instrument = Instrument::factory()->create(['name' => 'Royal Dutch Shell', 'yahoo_symbol' => 'SHEL.AS']);
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
            ->assertSee('/instruments/SHEL.AS')
            ->assertSee('CONFIRMED');

        // Livewire::test skips the panel's real render path — the ApexChart widget
        // on this page only blows up there.
        $this->actingAs($user)
            ->get(Dividends::getUrl())
            ->assertSuccessful()
            ->assertSee(__('dividends.sections.calendar'));
    }

    public function test_the_calendar_groups_payments_per_month_and_prefers_the_pay_date(): void
    {
        $user = User::factory()->create();
        $account = Account::factory()->for($user)->create();
        $instrument = Instrument::factory()->create(['name' => 'Ahold Delhaize']);
        Transaction::factory()->for($account)->for($instrument)->create(['type' => 'buy', 'quantity' => 100]);

        // Ex-date in one month, pay date in the next: the payment belongs to the
        // month the money arrives in.
        $exDate = now()->addMonth()->startOfMonth()->addDays(20);
        Dividend::factory()->for($instrument)->create([
            'ex_date' => $exDate->toDateString(),
            'pay_date' => $exDate->copy()->addMonth()->toDateString(),
            'amount_per_share' => 0.50,
            'currency' => 'EUR',
            'confirmed' => true,
        ]);

        $page = Livewire::actingAs($user)
            ->test(Dividends::class)
            // Both dates are on the row; the month it sits under is the pay date's.
            ->assertSee($exDate->translatedFormat('d M'))
            ->assertSee($exDate->copy()->addMonth()->translatedFormat('d M'));

        $timeline = $page->get('timeline');

        $this->assertSame($exDate->copy()->addMonth()->format('Y-m'), $timeline[0]['month']);
        $this->assertSame(50.0, $timeline[0]['total_eur']);
        $this->assertTrue($timeline[0]['rows'][0]['is_pay_date']);
    }

    public function test_a_projection_without_a_pay_date_falls_back_to_its_ex_date(): void
    {
        $user = User::factory()->create();
        $account = Account::factory()->for($user)->create();
        $instrument = Instrument::factory()->create(['name' => 'NN Group']);
        Transaction::factory()->for($account)->for($instrument)->create(['type' => 'buy', 'quantity' => 10]);

        // Two past payments six months apart give the projector a cadence to run on.
        foreach ([12, 6] as $monthsAgo) {
            Dividend::factory()->for($instrument)->create([
                'ex_date' => now()->subMonths($monthsAgo)->toDateString(),
                'amount_per_share' => 1.00,
                'currency' => 'EUR',
                'confirmed' => false,
            ]);
        }

        $timeline = Livewire::actingAs($user)->test(Dividends::class)->get('timeline');

        $this->assertNotSame([], $timeline);

        $row = $timeline[0]['rows'][0];
        $this->assertFalse($row['is_pay_date']);
        $this->assertSame($row['ex_date'], $row['date']);
        $this->assertFalse($row['confirmed']);
    }
}
