<?php

namespace App\Filament\Pages;

use App\Actions\ComputeProjections;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;

class Projections extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowTrendingUp;

    protected static ?string $navigationLabel = 'Projecties';

    protected static ?int $navigationSort = 3;

    protected string $view = 'filament.pages.projections';

    public int $horizon = 5;

    public float $annualContribution = 0;

    /** @var array<string, mixed> */
    public array $data = [];

    public function mount(): void
    {
        $this->annualContribution = (float) (auth()->user()->settings['annual_contribution_eur'] ?? 0);
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
        return 'Projecties';
    }

    private function recompute(): void
    {
        $this->data = app(ComputeProjections::class)->forUser(auth()->user(), $this->horizon);
    }
}
