<?php

namespace Tests\Feature;

use App\Jobs\ResolveInstrumentSymbolsJob;
use App\Models\Instrument;
use App\Services\MarketData\YahooFinanceAdapter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ResolveSymbolsSectorTest extends TestCase
{
    use RefreshDatabase;

    public function test_backfills_sector_for_resolved_instrument(): void
    {
        $instrument = Instrument::factory()->create(['yahoo_symbol' => 'ASML.AS', 'sector' => null]);

        $this->mock(YahooFinanceAdapter::class, function ($mock) {
            $mock->shouldReceive('sector')->with('ASML.AS')->once()->andReturn('Technology');
        });

        app(ResolveInstrumentSymbolsJob::class)->handle(app(YahooFinanceAdapter::class));

        $this->assertSame('Technology', $instrument->fresh()->sector);
    }

    public function test_etf_without_sector_stays_null(): void
    {
        $instrument = Instrument::factory()->create(['yahoo_symbol' => 'VWRL.AS', 'sector' => null]);

        $this->mock(YahooFinanceAdapter::class, function ($mock) {
            $mock->shouldReceive('sector')->andReturn(null);
        });

        app(ResolveInstrumentSymbolsJob::class)->handle(app(YahooFinanceAdapter::class));

        $this->assertNull($instrument->fresh()->sector);
    }
}
