<?php

namespace Tests\Feature;

use App\Models\Dividend;
use App\Models\Instrument;
use App\Services\MarketData\DividendSyncService;
use App\Services\MarketData\YahooFinanceAdapter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DividendSyncTest extends TestCase
{
    use RefreshDatabase;

    public function test_sync_inserts_dividend_rows_for_instrument(): void
    {
        $instrument = Instrument::factory()->create(['yahoo_symbol' => 'AAPL']);

        $this->mock(YahooFinanceAdapter::class, function ($mock) {
            $mock->shouldReceive('dividends')
                ->once()
                ->andReturn([
                    ['ex_date' => '2023-02-10', 'amount' => 0.23, 'currency' => 'USD'],
                    ['ex_date' => '2023-05-12', 'amount' => 0.24, 'currency' => 'USD'],
                    ['ex_date' => '2023-08-11', 'amount' => 0.24, 'currency' => 'USD'],
                ]);
            $mock->shouldReceive('upcomingDividend')->andReturn(null);
        });

        $rows = app(DividendSyncService::class)->syncInstrument($instrument);

        $this->assertSame(3, $rows);
        // Projections share the table now, so count the real rows only.
        $this->assertSame(3, Dividend::where('projected', false)->count());
        $this->assertDatabaseHas('dividends', [
            'instrument_id' => $instrument->id,
            'ex_date' => '2023-02-10',
            'amount_per_share' => '0.23000000',
            'currency' => 'USD',
        ]);
    }

    public function test_gbp_pence_amounts_are_divided_by_100(): void
    {
        $instrument = Instrument::factory()->create(['yahoo_symbol' => 'SHEL.L']);

        $this->mock(YahooFinanceAdapter::class, function ($mock) {
            $mock->shouldReceive('dividends')
                ->once()
                ->andReturn([
                    ['ex_date' => '2023-03-09', 'amount' => 28.8, 'currency' => 'GBp'],
                ]);
            $mock->shouldReceive('upcomingDividend')->andReturn(null);
        });

        app(DividendSyncService::class)->syncInstrument($instrument);

        $this->assertDatabaseHas('dividends', [
            'instrument_id' => $instrument->id,
            'amount_per_share' => '0.28800000',
            'currency' => 'GBP',
        ]);
    }

    public function test_sync_is_idempotent(): void
    {
        $instrument = Instrument::factory()->create(['yahoo_symbol' => 'MSFT']);

        $rows = [
            ['ex_date' => '2023-08-16', 'amount' => 0.68, 'currency' => 'USD'],
        ];

        $this->mock(YahooFinanceAdapter::class, function ($mock) use ($rows) {
            $mock->shouldReceive('dividends')
                ->twice()
                ->andReturn($rows);
            $mock->shouldReceive('upcomingDividend')->andReturn(null);
        });

        $service = app(DividendSyncService::class);
        $service->syncInstrument($instrument);
        $service->syncInstrument($instrument);

        // Every run re-fetches the same window, so the upsert must not duplicate.
        $this->assertDatabaseCount('dividends', 1);
    }

    public function test_a_confirmed_future_row_does_not_freeze_the_history_fetch(): void
    {
        // Regression: the from-date used to resume after max(ex_date) over *all* rows,
        // including the confirmed future one it had written itself. Yahoo then got
        // period1 > period2, answered 400, and dividend sync died for good.
        $instrument = Instrument::factory()->create(['yahoo_symbol' => 'AAPL']);

        Dividend::factory()->create([
            'instrument_id' => $instrument->id,
            'ex_date' => now()->addDays(20)->toDateString(),
            'amount_per_share' => 0.24,
            'currency' => 'USD',
            'confirmed' => true,
        ]);

        $this->mock(YahooFinanceAdapter::class, function ($mock) {
            $mock->shouldReceive('dividends')
                ->once()
                ->with('AAPL', \Mockery::on(fn (string $fromDate): bool => $fromDate < now()->toDateString()))
                ->andReturn([
                    ['ex_date' => '2024-08-09', 'amount' => 0.25, 'currency' => 'USD'],
                ]);
            $mock->shouldReceive('upcomingDividend')->andReturn(null);
        });

        app(DividendSyncService::class)->syncInstrument($instrument);

        $this->assertDatabaseHas('dividends', [
            'instrument_id' => $instrument->id,
            'ex_date' => '2024-08-09',
        ]);
    }

    public function test_a_lapsed_confirmed_row_is_replaced_by_the_real_payment(): void
    {
        $instrument = Instrument::factory()->create(['yahoo_symbol' => 'AAPL']);

        // Estimated at 0.24 for the 10th; Yahoo now reports 0.26 on the 11th.
        Dividend::factory()->create([
            'instrument_id' => $instrument->id,
            'ex_date' => now()->subDays(10)->toDateString(),
            'amount_per_share' => 0.24,
            'currency' => 'USD',
            'confirmed' => true,
        ]);

        $actualExDate = now()->subDays(9)->toDateString();

        $this->mock(YahooFinanceAdapter::class, function ($mock) use ($actualExDate) {
            $mock->shouldReceive('dividends')
                ->andReturn([
                    ['ex_date' => $actualExDate, 'amount' => 0.26, 'currency' => 'USD'],
                ]);
            $mock->shouldReceive('upcomingDividend')->andReturn(null);
        });

        app(DividendSyncService::class)->syncInstrument($instrument);

        // One row, not the estimate plus a phantom payment a day later.
        $this->assertDatabaseCount('dividends', 1);
        $this->assertDatabaseHas('dividends', [
            'ex_date' => $actualExDate,
            'amount_per_share' => '0.26000000',
            'confirmed' => false,
        ]);
    }

    public function test_a_failed_fetch_does_not_delete_the_lapsed_confirmed_row(): void
    {
        $instrument = Instrument::factory()->create(['yahoo_symbol' => 'AAPL']);

        $lapsedExDate = now()->subDays(10)->toDateString();

        Dividend::factory()->create([
            'instrument_id' => $instrument->id,
            'ex_date' => $lapsedExDate,
            'amount_per_share' => 0.24,
            'currency' => 'USD',
            'confirmed' => true,
        ]);

        $this->mock(YahooFinanceAdapter::class, function ($mock) {
            $mock->shouldReceive('dividends')->andThrow(new \RuntimeException('Yahoo Finance HTTP 503'));
        });

        try {
            app(DividendSyncService::class)->syncInstrument($instrument);
            $this->fail('Expected the Yahoo failure to bubble up to the caller.');
        } catch (\RuntimeException) {
            // The job/command logs it and moves on to the next instrument.
        }

        $this->assertDatabaseHas('dividends', ['ex_date' => $lapsedExDate, 'confirmed' => true]);
    }

    public function test_the_upcoming_ex_date_still_syncs_when_history_comes_back_empty(): void
    {
        $instrument = Instrument::factory()->create(['yahoo_symbol' => 'AAPL']);

        Dividend::factory()->create([
            'instrument_id' => $instrument->id,
            'ex_date' => now()->subDays(200)->toDateString(),
            'amount_per_share' => 0.30,
            'currency' => 'USD',
        ]);

        $futureExDate = now()->addDays(15)->toDateString();

        $this->mock(YahooFinanceAdapter::class, function ($mock) use ($futureExDate) {
            $mock->shouldReceive('dividends')->andReturn([]);
            $mock->shouldReceive('upcomingDividend')
                ->once()
                ->andReturn(['ex_date' => $futureExDate, 'pay_date' => null]);
        });

        app(DividendSyncService::class)->syncInstrument($instrument);

        $this->assertDatabaseHas('dividends', [
            'ex_date' => $futureExDate,
            'confirmed' => true,
        ]);
    }

    public function test_instrument_without_yahoo_symbol_is_skipped(): void
    {
        $instrument = Instrument::factory()->create(['yahoo_symbol' => null]);

        $this->mock(YahooFinanceAdapter::class, function ($mock) {
            $mock->shouldNotReceive('dividends');
            $mock->shouldNotReceive('upcomingDividend');
        });

        $rows = app(DividendSyncService::class)->syncInstrument($instrument);

        $this->assertSame(0, $rows);
        $this->assertDatabaseCount('dividends', 0);
    }

    public function test_confirmed_upcoming_row_is_upserted_with_median_historical_amount(): void
    {
        $instrument = Instrument::factory()->create(['yahoo_symbol' => 'AAPL']);

        $futureExDate = now()->addDays(20)->toDateString();
        $futurePayDate = now()->addDays(27)->toDateString();

        $this->mock(YahooFinanceAdapter::class, function ($mock) use ($futureExDate, $futurePayDate) {
            $mock->shouldReceive('dividends')
                ->andReturn([
                    ['ex_date' => '2023-08-11', 'amount' => 0.24, 'currency' => 'USD'],
                    ['ex_date' => '2023-11-10', 'amount' => 0.25, 'currency' => 'USD'],
                ]);
            $mock->shouldReceive('upcomingDividend')
                ->andReturn(['ex_date' => $futureExDate, 'pay_date' => $futurePayDate]);
        });

        app(DividendSyncService::class)->syncInstrument($instrument);

        // Should have 2 historical + 1 confirmed row, projections aside.
        $this->assertSame(3, Dividend::where('projected', false)->count());

        $this->assertDatabaseHas('dividends', [
            'instrument_id' => $instrument->id,
            'ex_date' => $futureExDate,
            'pay_date' => $futurePayDate,
            'amount_per_share' => '0.24500000', // median of 0.24 and 0.25
            'currency' => 'USD',
            'confirmed' => true,
        ]);
    }

    public function test_confirmed_upcoming_ignores_a_one_off_special_dividend(): void
    {
        $instrument = Instrument::factory()->create(['yahoo_symbol' => 'SAB.MC']);

        $futureExDate = now()->addDays(20)->toDateString();
        $futurePayDate = now()->addDays(27)->toDateString();

        // Four regular 0.03 dividends and one large special as the latest row.
        $this->mock(YahooFinanceAdapter::class, function ($mock) use ($futureExDate, $futurePayDate) {
            $mock->shouldReceive('dividends')
                ->andReturn([
                    ['ex_date' => '2023-05-10', 'amount' => 0.03, 'currency' => 'EUR'],
                    ['ex_date' => '2023-11-10', 'amount' => 0.03, 'currency' => 'EUR'],
                    ['ex_date' => '2024-05-10', 'amount' => 0.03, 'currency' => 'EUR'],
                    ['ex_date' => '2024-11-10', 'amount' => 0.03, 'currency' => 'EUR'],
                    ['ex_date' => '2025-01-15', 'amount' => 0.50, 'currency' => 'EUR'], // special
                ]);
            $mock->shouldReceive('upcomingDividend')
                ->andReturn(['ex_date' => $futureExDate, 'pay_date' => $futurePayDate]);
        });

        app(DividendSyncService::class)->syncInstrument($instrument);

        // Median of {0.03,0.03,0.03,0.03,0.50} = 0.03 — the special is ignored.
        $this->assertDatabaseHas('dividends', [
            'instrument_id' => $instrument->id,
            'ex_date' => $futureExDate,
            'amount_per_share' => '0.03000000',
            'confirmed' => true,
        ]);
    }

    public function test_confirmed_row_is_not_created_when_upcoming_dividend_returns_null(): void
    {
        $instrument = Instrument::factory()->create(['yahoo_symbol' => 'AAPL']);

        $this->mock(YahooFinanceAdapter::class, function ($mock) {
            $mock->shouldReceive('dividends')
                ->andReturn([
                    ['ex_date' => '2023-08-11', 'amount' => 0.24, 'currency' => 'USD'],
                ]);
            $mock->shouldReceive('upcomingDividend')->andReturn(null);
        });

        app(DividendSyncService::class)->syncInstrument($instrument);

        $this->assertDatabaseCount('dividends', 1);
        $this->assertDatabaseMissing('dividends', ['confirmed' => true]);
    }

    public function test_confirmed_upsert_is_idempotent(): void
    {
        $instrument = Instrument::factory()->create(['yahoo_symbol' => 'AAPL']);

        $futureExDate = now()->addDays(20)->toDateString();

        $this->mock(YahooFinanceAdapter::class, function ($mock) use ($futureExDate) {
            $mock->shouldReceive('dividends')
                ->andReturn([
                    ['ex_date' => '2023-08-11', 'amount' => 0.24, 'currency' => 'USD'],
                ]);
            $mock->shouldReceive('upcomingDividend')
                ->andReturn(['ex_date' => $futureExDate, 'pay_date' => null]);
        });

        $service = app(DividendSyncService::class);
        $service->syncInstrument($instrument);
        $service->syncInstrument($instrument);

        $confirmed = Dividend::where('confirmed', true)->get();
        $this->assertCount(1, $confirmed);
    }
}
