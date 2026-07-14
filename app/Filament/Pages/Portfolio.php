<?php

namespace App\Filament\Pages;

use App\Actions\ComputePortfolio;
use App\Filament\Concerns\BuildsStats;
use App\Filament\Concerns\RefreshesMarketData;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\ToggleButtons;
use Filament\Pages\Page;
use Filament\Panel;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;

class Portfolio extends Page
{
    use BuildsStats;
    use RefreshesMarketData;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChartPie;

    protected static ?int $navigationSort = 1;

    public static function getNavigationLabel(): string
    {
        return __('portfolio.nav');
    }

    protected string $view = 'filament.pages.portfolio';

    public static function getRoutePath(Panel $panel): string
    {
        return '/';
    }

    /** @var array<string, mixed> */
    public array $summary = [];

    /** @var array<int, array<string, mixed>> */
    public array $positions = [];

    public string $range = '1Y';

    public string $mode = 'value';

    public function mount(ComputePortfolio $compute): void
    {
        ['positions' => $positions, 'summary' => $summary] = $compute->forUser(auth()->user());

        $this->positions = $positions;
        $this->summary = $summary;
    }

    public function getTitle(): string
    {
        return __('portfolio.title');
    }

    /** @return array<int, Action> */
    protected function getHeaderActions(): array
    {
        return [$this->refreshMarketDataAction()];
    }

    public function hasPositions(): bool
    {
        return $this->positions !== [];
    }

    public function summaryStats(Schema $schema): Schema
    {
        $summary = $this->summary;

        return $schema->components([
            Section::make()->contained(false)->gridContainer()->columns(4)->schema([
                $this->stat(__('portfolio.kpi.market_value'), $this->eur($summary['total_value_eur']), rule: 'ink'),
                $this->stat(__('portfolio.kpi.deposited'), $this->eur($summary['deposited_eur']), rule: 'neutral'),
                $this->stat(__('portfolio.kpi.net_gain'), $this->eur($summary['net_gain_eur']), rule: 'positive', sub: $this->pct($summary['net_gain_pct']), color: $this->signColor($summary['net_gain_eur'])),
                $this->stat(__('portfolio.kpi.unrealized'), $this->eur($summary['total_unrealized_gain_eur']), rule: 'positive', sub: $this->pct($summary['total_unrealized_gain_pct']), color: $this->signColor($summary['total_unrealized_gain_eur'])),
            ]),
        ]);
    }

    public function modeControl(Schema $schema): Schema
    {
        return $schema->components([
            ToggleButtons::make('mode')
                ->hiddenLabel()
                ->options(collect(['value', 'pl', 'roi'])->mapWithKeys(fn (string $mode): array => [$mode => __('portfolio.chart.mode.'.$mode)])->all())
                ->grouped()
                ->live()
                ->extraAttributes(['class' => 'divio-segmented']),
        ]);
    }

    public function rangeControl(Schema $schema): Schema
    {
        return $schema->components([
            ToggleButtons::make('range')
                ->hiddenLabel()
                ->options(['1M' => '1M', '6M' => '6M', '1Y' => '1J', 'ALL' => 'ALL'])
                ->grouped()
                ->live()
                ->extraAttributes(['class' => 'divio-segmented']),
        ]);
    }

    public function returnsStats(Schema $schema): Schema
    {
        $summary = $this->summary;

        return $schema->components([
            Section::make()->contained(false)->gridContainer()->columns(3)->schema([
                $this->stat(__('portfolio.kpi.realized'), $this->eur($summary['total_realized_gain_eur']), color: $this->signColor($summary['total_realized_gain_eur'])),
                $this->stat(__('portfolio.kpi.dividends'), $this->eur($summary['total_dividend_eur']), color: 'var(--divio-positive,#2f7d52)'),
                $this->stat(__('portfolio.kpi.fees'), $this->eur($summary['total_fees_eur']), color: 'var(--divio-negative,#c0392b)'),
            ]),
        ]);
    }
}
