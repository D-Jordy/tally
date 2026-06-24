<?php

namespace App\Filament\Pages;

use App\Actions\ComputeIncomingDividends;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;

class Dividends extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBanknotes;

    protected static ?string $navigationLabel = 'Dividenden';

    protected static ?int $navigationSort = 2;

    protected string $view = 'filament.pages.dividends';

    /** @var array<int, array<string, mixed>> */
    public array $confirmed = [];

    /** @var array<int, array<string, mixed>> */
    public array $projected = [];

    /** @var array<string, mixed> */
    public array $summary = [];

    public function mount(ComputeIncomingDividends $compute): void
    {
        ['confirmed' => $confirmed, 'events' => $events, 'summary' => $summary] = $compute->forUser(auth()->user());

        $this->confirmed = $confirmed;
        $this->projected = $events;
        $this->summary = $summary;
    }

    public function getTitle(): string
    {
        return 'Inkomende dividenden';
    }
}
