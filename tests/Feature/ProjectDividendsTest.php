<?php

namespace Tests\Feature;

use App\Actions\ProjectDividends;
use App\Models\Dividend;
use App\Models\Instrument;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProjectDividendsTest extends TestCase
{
    use RefreshDatabase;

    private function seedQuarterly(Instrument $instrument, float $amount = 0.50): void
    {
        for ($quarter = 4; $quarter >= 1; $quarter--) {
            Dividend::factory()->create([
                'instrument_id' => $instrument->id,
                'ex_date' => now()->subDays($quarter * 90)->toDateString(),
                'amount_per_share' => $amount,
                'currency' => 'EUR',
            ]);
        }
    }

    public function test_it_writes_projected_rows_for_the_next_12_months(): void
    {
        $instrument = Instrument::factory()->create();
        $this->seedQuarterly($instrument);

        $written = app(ProjectDividends::class)->forInstrument($instrument);

        $projections = Dividend::where('projected', true)->get();

        $this->assertSame($written, $projections->count());
        $this->assertGreaterThanOrEqual(4, $projections->count());
        $this->assertLessThanOrEqual(5, $projections->count());

        foreach ($projections as $projection) {
            $this->assertFalse($projection->confirmed);
            $this->assertNull($projection->pay_date);
            $this->assertTrue($projection->ex_date->isFuture());
            $this->assertSame('0.50000000', $projection->amount_per_share);
        }
    }

    public function test_rerunning_replaces_the_previous_projections(): void
    {
        $instrument = Instrument::factory()->create();
        $this->seedQuarterly($instrument);

        $projector = app(ProjectDividends::class);
        $projector->forInstrument($instrument);
        $first = Dividend::where('projected', true)->pluck('id');

        $projector->forInstrument($instrument);
        $second = Dividend::where('projected', true)->pluck('id');

        $this->assertSame($first->count(), $second->count());
        $this->assertEmpty($first->intersect($second), 'Stale projections should be deleted, not kept.');
    }

    public function test_projections_are_never_projected_from_projections(): void
    {
        $instrument = Instrument::factory()->create();
        $this->seedQuarterly($instrument, 0.50);

        $projector = app(ProjectDividends::class);
        $projector->forInstrument($instrument);

        // A projection dated in the past would otherwise feed the next run's median.
        Dividend::where('projected', true)->first()->update([
            'ex_date' => now()->subDays(10)->toDateString(),
            'amount_per_share' => 99,
        ]);

        $projector->forInstrument($instrument);

        $this->assertSame(
            ['0.50000000'],
            Dividend::where('projected', true)->pluck('amount_per_share')->unique()->values()->all()
        );
    }

    public function test_a_confirmed_row_suppresses_the_projection_around_it(): void
    {
        $instrument = Instrument::factory()->create();
        $this->seedQuarterly($instrument);

        // Roughly where the first projection would land.
        $confirmedDate = now()->addDays(5)->toDateString();
        Dividend::factory()->create([
            'instrument_id' => $instrument->id,
            'ex_date' => $confirmedDate,
            'amount_per_share' => 0.50,
            'currency' => 'EUR',
            'confirmed' => true,
        ]);

        app(ProjectDividends::class)->forInstrument($instrument);

        $clashes = Dividend::where('projected', true)
            ->whereBetween('ex_date', [now()->subDays(15)->toDateString(), now()->addDays(25)->toDateString()])
            ->count();

        $this->assertSame(0, $clashes);
    }

    public function test_an_instrument_with_one_payment_gets_no_projection(): void
    {
        $instrument = Instrument::factory()->create();
        Dividend::factory()->create([
            'instrument_id' => $instrument->id,
            'ex_date' => now()->subMonths(3)->toDateString(),
            'currency' => 'EUR',
        ]);

        $this->assertSame(0, app(ProjectDividends::class)->forInstrument($instrument));
        $this->assertSame(0, Dividend::where('projected', true)->count());
    }

    public function test_a_payer_that_missed_its_cadence_is_not_projected_forward(): void
    {
        $instrument = Instrument::factory()->create();

        // Quarterly, but the last payment was well over a year ago: stopped, suspended
        // or history we no longer trust — not ours to invent twelve months for.
        foreach ([24, 21, 18, 15] as $monthsAgo) {
            Dividend::factory()->create([
                'instrument_id' => $instrument->id,
                'ex_date' => now()->subMonths($monthsAgo)->toDateString(),
                'amount_per_share' => 0.50,
                'currency' => 'EUR',
            ]);
        }

        $this->assertSame(0, app(ProjectDividends::class)->forInstrument($instrument));
        $this->assertSame(0, Dividend::where('projected', true)->count());
    }

    public function test_a_special_dividend_does_not_inflate_the_projected_amount(): void
    {
        $instrument = Instrument::factory()->create();
        $this->seedQuarterly($instrument, 0.50);

        Dividend::factory()->create([
            'instrument_id' => $instrument->id,
            'ex_date' => now()->subDays(45)->toDateString(),
            'amount_per_share' => 5.00,
            'currency' => 'EUR',
        ]);

        app(ProjectDividends::class)->forInstrument($instrument);

        $this->assertSame('0.50000000', Dividend::where('projected', true)->first()->amount_per_share);
    }
}
