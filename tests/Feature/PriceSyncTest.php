<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Instrument;
use App\Models\Transaction;
use App\Services\MarketData\PriceSyncService;
use App\Services\MarketData\YahooFinanceAdapter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PriceSyncTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_sync_records_the_currency_the_listing_is_quoted_in(): void
    {
        $instrument = Instrument::factory()->create(['yahoo_symbol' => 'ASHR.L', 'quote_currency' => null]);

        Transaction::factory()->for(Account::factory())->for($instrument)
            ->create(['executed_at' => '2026-01-02', 'trade_currency' => 'EUR']);

        $this->mock(YahooFinanceAdapter::class, function ($mock) {
            $mock->shouldReceive('history')->once()->andReturn([
                ['date' => '2026-01-02', 'close' => 1290.0, 'currency' => 'GBp'],
                ['date' => '2026-01-05', 'close' => 1305.5, 'currency' => 'GBp'],
            ]);
        });

        $rows = app(PriceSyncService::class)->syncInstrument($instrument);

        // Pence normalised to pounds, so the currency the mismatch filter compares against
        // is the one the prices are actually stored in.
        $this->assertSame(2, $rows);
        $this->assertSame('GBP', $instrument->fresh()->quote_currency);
        $this->assertDatabaseHas('price_history', [
            'instrument_id' => $instrument->id,
            'date' => '2026-01-05',
            'close' => '13.05500000',
            'currency' => 'GBP',
        ]);
    }
}
