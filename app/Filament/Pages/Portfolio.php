<?php

namespace App\Filament\Pages;

use App\Actions\ComputePortfolio;
use App\Filament\Concerns\RefreshesMarketData;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Pages\Page;
use Filament\Panel;
use Filament\Support\Icons\Heroicon;

class Portfolio extends Page
{
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
}
