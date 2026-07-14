<?php

namespace App\Filament\Pages;

use App\Actions\ComputePortfolio;
use App\Actions\ComputeProjections;
use App\Filament\Concerns\BuildsStats;
use BackedEnum;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Components\ToggleButtons;
use Filament\Pages\Page;
use Filament\Schemas\Components\Flex;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class Insights extends Page
{
    use BuildsStats;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowTrendingUp;

    protected static ?int $navigationSort = 3;

    public static function getNavigationLabel(): string
    {
        return __('insights.nav');
    }

    protected string $view = 'filament.pages.insights';

    public int $horizon = 5;

    public float $annualContribution = 0;

    public bool $reinvestDividends = false;

    /** @var array<string, mixed> */
    public array $allocation = [];

    /**
     * Derived, never stored on the component. Filament builds the stat schema during
     * the update phase — before `updated*` hooks run — so a projection refreshed by a
     * hook would render one horizon behind. Deriving it on read keeps them in step.
     *
     * @var array<string, mixed>|null
     */
    private ?array $projections = null;

    private ?string $projectionsKey = null;

    public function mount(): void
    {
        $settings = auth()->user()->settings ?? [];

        $this->annualContribution = (float) ($settings['annual_contribution_eur'] ?? 0);
        $this->reinvestDividends = (bool) ($settings['reinvest_dividends'] ?? false);
        $this->allocation = $this->computeAllocation();
    }

    public function updatedHorizon(): void
    {
        if (! in_array($this->horizon, [1, 3, 5, 10], true)) {
            $this->horizon = 5;
        }
    }

    public function updatedAnnualContribution(): void
    {
        $user = auth()->user();
        $user->settings = [...$user->settings ?? [], 'annual_contribution_eur' => max(0, $this->annualContribution)];
        $user->save();
    }

    public function updatedReinvestDividends(): void
    {
        $user = auth()->user();
        $user->settings = [...$user->settings ?? [], 'reinvest_dividends' => $this->reinvestDividends];
        $user->save();
    }

    /** @return array<int, array<string, mixed>> */
    public function valueSeries(): array
    {
        return $this->projections()['value_series'] ?? [];
    }

    /** @return array<string, mixed> */
    private function projections(): array
    {
        $key = $this->horizon.'|'.$this->annualContribution.'|'.(int) $this->reinvestDividends;

        if ($this->projectionsKey !== $key) {
            $this->projections = app(ComputeProjections::class)
                ->forUser(auth()->user(), $this->horizon, $this->annualContribution, $this->reinvestDividends);
            $this->projectionsKey = $key;
        }

        return $this->projections;
    }

    public function getTitle(): string
    {
        return __('insights.title');
    }

    public function controls(Schema $schema): Schema
    {
        $suffix = __('projections.year_suffix');

        return $schema->components([
            Flex::make([
                ToggleButtons::make('horizon')
                    ->label(__('projections.horizon'))
                    ->options(collect([1, 3, 5, 10])->mapWithKeys(fn (int $years): array => [$years => $years.$suffix])->all())
                    ->grouped()
                    ->live()
                    ->grow(false)
                    ->extraAttributes(['class' => 'divio-segmented']),
                Toggle::make('reinvestDividends')
                    ->label(__('projections.reinvest_dividends'))
                    ->live()
                    ->grow(false),
                TextInput::make('annualContribution')
                    ->label(__('projections.annual_contribution'))
                    ->numeric()
                    ->minValue(0)
                    ->step(100)
                    ->prefix('€')
                    ->live(debounce: '600ms')
                    ->grow(false)
                    ->extraAttributes(['class' => 'divio-euro']),
            ])->extraAttributes(['class' => 'divio-controls']),
        ]);
    }

    public function projectionStats(Schema $schema): Schema
    {
        $projections = $this->projections();
        $valueSeries = $projections['value_series'] ?? [];
        $dividendSeries = $projections['dividend_series'] ?? [];
        $projectedValue = $valueSeries === [] ? 0 : end($valueSeries)['projected_value_eur'];
        $projectedDividends = $dividendSeries === [] ? 0 : end($dividendSeries)['projected_dividends_eur'];

        return $schema->components([
            Section::make()->contained(false)->gridContainer()->columns(4)->schema([
                $this->stat(__('projections.kpi.current'), $this->eur($projections['starting_value_eur'] ?? 0), rule: 'neutral'),
                $this->stat(__('projections.kpi.expected', ['years' => $this->horizon]), $this->eur($projectedValue), rule: 'ink'),
                $this->stat(__('projections.kpi.growth_rate'), $this->pct($projections['growth_rate'] ?? 0), rule: 'positive', color: 'var(--divio-positive,#2f7d52)'),
                $this->stat(__('projections.kpi.dividends', ['years' => $this->horizon]), $this->eur($projectedDividends), rule: 'neutral'),
            ]),
        ]);
    }

    /**
     * Short label for a donut slice. Real DEGIRO imports never fill `symbol` —
     * ResolveInstrumentSymbolsJob only ever resolves `yahoo_symbol`, which carries an
     * exchange suffix (NN.AS, VOW3.DE, RIO.L). Strip it; fall back to the full name.
     *
     * @param  array<string, mixed>  $position
     */
    private function ticker(array $position): string
    {
        $ticker = $position['symbol'] ?: Str::before((string) ($position['yahoo_symbol'] ?? ''), '.');

        return $ticker !== '' ? $ticker : $position['name'];
    }

    /**
     * Current-composition allocation from the open positions: weight per position
     * and summed weight per sector. Positions without a live price are excluded.
     *
     * @return array{total_eur: float, positions: array<int, array>, sectors: array<int, array>}
     */
    private function computeAllocation(): array
    {
        $valued = collect(app(ComputePortfolio::class)->forUser(auth()->user())['positions'])
            ->filter(fn (array $position): bool => (float) ($position['current_value_eur'] ?? 0) > 0);

        $total = (float) $valued->sum('current_value_eur');

        if ($total <= 0) {
            return ['total_eur' => 0.0, 'positions' => [], 'sectors' => []];
        }

        $positions = $valued
            ->sortByDesc('current_value_eur')
            ->map(fn (array $position): array => [
                'name' => $position['name'],
                // Ticker labels the donut slice; the full name only shows on hover.
                'symbol' => $this->ticker($position),
                'value_eur' => round((float) $position['current_value_eur'], 2),
                'weight' => round((float) $position['current_value_eur'] / $total, 4),
            ])
            ->values()
            ->all();

        $sectors = $valued
            ->groupBy(fn (array $position): string => $position['sector'] ?: __('insights.allocation.other'))
            ->map(fn (Collection $group): float => (float) $group->sum('current_value_eur'))
            ->sortDesc()
            ->map(fn (float $value, string $sector): array => [
                'sector' => $sector,
                'value_eur' => round($value, 2),
                'weight' => round($value / $total, 4),
            ])
            ->values()
            ->all();

        return ['total_eur' => round($total, 2), 'positions' => $positions, 'sectors' => $sectors];
    }
}
