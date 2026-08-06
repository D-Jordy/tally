<?php

namespace Tests\Feature;

use App\Jobs\ResolveInstrumentSymbolsJob;
use App\Models\Account;
use App\Models\Instrument;
use App\Models\Transaction;
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

    public function test_the_resolver_is_told_which_currency_the_trades_settled_in(): void
    {
        $instrument = Instrument::factory()->create([
            'isin' => 'LU0875160326',
            'exchange' => 'TDG',
            'yahoo_symbol' => null,
            'sector' => 'Diversified',
        ]);
        $account = Account::factory()->create();

        Transaction::factory()->for($account)->for($instrument)
            ->create(['executed_at' => now()->subYear(), 'trade_currency' => 'USD']);
        Transaction::factory()->for($account)->for($instrument)
            ->create(['executed_at' => now()->subMonth(), 'trade_currency' => 'EUR']);

        $this->mock(YahooFinanceAdapter::class, function ($mock) {
            $mock->shouldReceive('searchByIsin')
                ->with('LU0875160326', 'TDG', 'EUR')
                ->once()
                ->andReturn('RQFI.DE');
        });

        app(ResolveInstrumentSymbolsJob::class)->handle(app(YahooFinanceAdapter::class));

        $this->assertSame('RQFI.DE', $instrument->fresh()->yahoo_symbol);
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
