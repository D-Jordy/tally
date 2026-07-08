<?php

namespace App\Filament\Pages;

use App\Actions\ComputePortfolio;
use App\Actions\ComputeProjections;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Collection;

class Insights extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowTrendingUp;

    protected static ?int $navigationSort = 3;

    public static function getNavigationLabel(): string
    {
        return __('insights.nav');
    }

    protected string $view = 'filament.pages.insights';

    public int $horizon = 5;

    public float $annualContribution = 0;

    /** @var array<string, mixed> */
    public array $data = [];

    /** @var array<string, mixed> */
    public array $allocation = [];

    public function mount(): void
    {
        $this->annualContribution = (float) (auth()->user()->settings['annual_contribution_eur'] ?? 0);
        $this->allocation = $this->computeAllocation();
        $this->recompute();
    }

    public function updatedHorizon(): void
    {
        if (! in_array($this->horizon, [1, 3, 5, 10], true)) {
            $this->horizon = 5;
        }

        $this->recompute();
    }

    public function updatedAnnualContribution(): void
    {
        $user = auth()->user();
        $user->settings = [...$user->settings ?? [], 'annual_contribution_eur' => max(0, $this->annualContribution)];
        $user->save();

        $this->recompute();
    }

    public function getTitle(): string
    {
        return __('insights.title');
    }

    private function recompute(): void
    {
        $this->data = app(ComputeProjections::class)->forUser(auth()->user(), $this->horizon);
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
