<?php

namespace Tests\Feature;

use App\Filament\Resources\Instruments\InstrumentResource;
use App\Filament\Resources\Instruments\Pages\EditInstrument;
use App\Filament\Resources\Instruments\Pages\ListInstruments;
use App\Jobs\ResolveInstrumentSymbolsJob;
use App\Models\Account;
use App\Models\Dividend;
use App\Models\Instrument;
use App\Models\PriceHistory;
use App\Models\Transaction;
use App\Models\User;
use App\Services\MarketData\DividendSyncService;
use App\Services\MarketData\PriceSyncService;
use App\Services\MarketData\YahooFinanceAdapter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Mockery;
use Tests\TestCase;

class InstrumentResourceTest extends TestCase
{
    use RefreshDatabase;

    public function test_list_shows_only_instruments_the_user_has_traded(): void
    {
        $user = User::factory()->create();
        $mine = $this->heldBy($user);
        $theirs = $this->heldBy(User::factory()->create());
        $untraded = Instrument::factory()->create();

        Livewire::actingAs($user)
            ->test(ListInstruments::class)
            ->assertCanSeeTableRecords([$mine])
            ->assertCanNotSeeTableRecords([$theirs, $untraded]);
    }

    public function test_the_missing_symbol_and_sector_filters_narrow_the_list(): void
    {
        $user = User::factory()->create();
        $complete = $this->heldBy($user, ['yahoo_symbol' => 'ASML.AS', 'sector' => 'Technology']);
        $noSymbol = $this->heldBy($user, ['yahoo_symbol' => null, 'sector' => 'Technology']);
        $noSector = $this->heldBy($user, ['yahoo_symbol' => 'VWRL.AS', 'sector' => null]);

        Livewire::actingAs($user)
            ->test(ListInstruments::class)
            ->filterTable('missing_symbol')
            ->assertCanSeeTableRecords([$noSymbol])
            ->assertCanNotSeeTableRecords([$complete, $noSector])
            ->filterTable('missing_symbol', false)
            ->filterTable('missing_sector')
            ->assertCanSeeTableRecords([$noSector])
            ->assertCanNotSeeTableRecords([$complete, $noSymbol]);
    }

    public function test_a_corrected_symbol_survives_the_resolve_job_and_drops_the_stale_market_data(): void
    {
        $user = User::factory()->create();
        $instrument = $this->heldBy($user, ['yahoo_symbol' => 'WRONG.AS', 'sector' => 'Technology']);

        PriceHistory::create([
            'instrument_id' => $instrument->id,
            'date' => '2026-01-02',
            'close' => 123.45,
            'currency' => 'EUR',
        ]);
        Dividend::factory()->for($instrument)->create();

        // Instruments are shared, so the wiped history has to be refilled right away
        // rather than leaving every other holder with an empty chart until 02:00.
        $prices = Mockery::mock(PriceSyncService::class);
        $prices->shouldReceive('syncInstrument')->once()->andReturn(0);
        $dividends = Mockery::mock(DividendSyncService::class);
        $dividends->shouldReceive('syncInstrument')->once()->andReturn(0);
        $this->app->instance(PriceSyncService::class, $prices);
        $this->app->instance(DividendSyncService::class, $dividends);

        Livewire::actingAs($user)
            ->test(EditInstrument::class, ['record' => $instrument->getRouteKey()])
            ->set('data.yahoo_symbol', 'ASML.AS')
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('ASML.AS', $instrument->fresh()->yahoo_symbol);
        $this->assertSame(0, PriceHistory::where('instrument_id', $instrument->id)->count());
        $this->assertSame(0, Dividend::where('instrument_id', $instrument->id)->count());

        // The job only ever fills a null symbol, so the correction is not re-queried.
        $yahoo = Mockery::mock(YahooFinanceAdapter::class);
        $yahoo->shouldNotReceive('searchByIsin');
        $yahoo->shouldNotReceive('sector');
        $this->app->instance(YahooFinanceAdapter::class, $yahoo);

        dispatch_sync(new ResolveInstrumentSymbolsJob);

        $this->assertSame('ASML.AS', $instrument->fresh()->yahoo_symbol);
    }

    public function test_a_manually_set_sector_stops_the_job_re_querying_a_fund(): void
    {
        $user = User::factory()->create();
        $instrument = $this->heldBy($user, ['yahoo_symbol' => 'VWRL.AS', 'sector' => null]);

        Livewire::actingAs($user)
            ->test(EditInstrument::class, ['record' => $instrument->getRouteKey()])
            ->set('data.sector', 'Diversified')
            ->call('save')
            ->assertHasNoFormErrors();

        $yahoo = Mockery::mock(YahooFinanceAdapter::class);
        $yahoo->shouldNotReceive('sector');
        $this->app->instance(YahooFinanceAdapter::class, $yahoo);

        dispatch_sync(new ResolveInstrumentSymbolsJob);

        $this->assertSame('Diversified', $instrument->fresh()->sector);
    }

    public function test_a_failing_resync_does_not_lose_the_saved_symbol(): void
    {
        $user = User::factory()->create();
        $instrument = $this->heldBy($user, ['yahoo_symbol' => 'WRONG.AS']);

        $prices = Mockery::mock(PriceSyncService::class);
        $prices->shouldReceive('syncInstrument')->andThrow(new \RuntimeException('Yahoo down'));
        $this->app->instance(PriceSyncService::class, $prices);

        Livewire::actingAs($user)
            ->test(EditInstrument::class, ['record' => $instrument->getRouteKey()])
            ->set('data.yahoo_symbol', 'ASML.AS')
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('ASML.AS', $instrument->fresh()->yahoo_symbol);
    }

    public function test_the_detail_url_uses_the_ticker_and_falls_back_to_the_id(): void
    {
        $user = User::factory()->create();
        $resolved = $this->heldBy($user, ['yahoo_symbol' => 'ASML.AS']);
        $unresolved = $this->heldBy($user, ['yahoo_symbol' => null]);

        $this->assertStringEndsWith('/instruments/ASML.AS', InstrumentResource::getUrl('view', ['record' => $resolved]));
        $this->assertStringEndsWith("/instruments/{$unresolved->id}", InstrumentResource::getUrl('view', ['record' => $unresolved]));

        // Both have to resolve back — a ticker never matches the bigint id column,
        // which Postgres treats as an error rather than a miss.
        $this->actingAs($user)->get(InstrumentResource::getUrl('view', ['record' => $resolved]))->assertOk();
        $this->actingAs($user)->get(InstrumentResource::getUrl('view', ['record' => $unresolved]))->assertOk();
        $this->actingAs($user)->get(InstrumentResource::getUrl('edit', ['record' => $resolved]))->assertOk();
    }

    public function test_an_instrument_you_never_traded_is_not_reachable_by_ticker(): void
    {
        $user = User::factory()->create();
        $theirs = $this->heldBy(User::factory()->create(), ['yahoo_symbol' => 'SHEL.AS']);

        $this->actingAs($user)
            ->get(InstrumentResource::getUrl('view', ['record' => $theirs]))
            ->assertNotFound();
    }

    public function test_the_detail_page_renders_for_a_held_and_a_sold_instrument(): void
    {
        $user = User::factory()->create();
        $account = Account::factory()->for($user)->create();

        $held = Instrument::factory()->create();
        Transaction::factory()->for($account)->for($held)->create(['type' => 'buy', 'quantity' => 12.5]);

        $sold = Instrument::factory()->create();
        Transaction::factory()->for($account)->for($sold)->create(['type' => 'buy', 'quantity' => 10]);
        Transaction::factory()->for($account)->for($sold)->create(['type' => 'sell', 'quantity' => 10]);

        // Real HTTP, not Livewire::test — the price chart needs the ApexCharts plugin
        // registered on the panel or the route 500s.
        $this->actingAs($user)
            ->get(InstrumentResource::getUrl('view', ['record' => $held]))
            ->assertOk()
            // Euro notation, and no trailing zeros padding it out to 12,5000.
            ->assertSee('12,5');

        $this->actingAs($user)->get(InstrumentResource::getUrl('view', ['record' => $sold]))->assertOk();
        $this->actingAs($user)->get(InstrumentResource::getUrl('index'))->assertOk();
        $this->actingAs($user)->get(InstrumentResource::getUrl('edit', ['record' => $held]))->assertOk();
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function heldBy(User $user, array $attributes = []): Instrument
    {
        $instrument = Instrument::factory()->create($attributes);

        Transaction::factory()
            ->for(Account::factory()->for($user))
            ->for($instrument)
            ->create();

        return $instrument;
    }
}
