<?php

namespace App\Filament\Pages;

use App\Actions\ComputePortfolio;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Panel;
use Filament\Support\Icons\Heroicon;

class Portfolio extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChartPie;

    protected static ?string $navigationLabel = 'Portfolio';

    protected static ?int $navigationSort = 1;

    protected string $view = 'filament.pages.portfolio';

    public static function getRoutePath(Panel $panel): string
    {
        return '/';
    }

    /** @var array<string, mixed> */
    public array $summary = [];

    /** @var array<int, array<string, mixed>> */
    public array $positions = [];

    public function mount(ComputePortfolio $compute): void
    {
        ['positions' => $positions, 'summary' => $summary] = $compute->forUser(auth()->user());

        $this->positions = $positions;
        $this->summary = $summary;
    }

    public function getTitle(): string
    {
        return 'Portfolio';
    }

    public function hasPositions(): bool
    {
        return $this->positions !== [];
    }
}
