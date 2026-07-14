<?php

namespace App\Filament\Pages;

use App\Actions\ComputeIncomingDividends;
use App\Filament\Concerns\BuildsStats;
use App\Filament\Concerns\RefreshesMarketData;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;

class Dividends extends Page
{
    use BuildsStats;
    use RefreshesMarketData;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBanknotes;

    protected static ?int $navigationSort = 2;

    public static function getNavigationLabel(): string
    {
        return __('dividends.nav');
    }

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
        return __('dividends.title');
    }

    /** @return array<int, Action> */
    protected function getHeaderActions(): array
    {
        return [$this->refreshMarketDataAction()];
    }

    public function summaryStats(Schema $schema): Schema
    {
        $summary = $this->summary;

        return $schema->components([
            Section::make()->contained(false)->gridContainer()->columns(3)->schema([
                $this->stat(__('dividends.kpi.next_12m'), $this->eur($summary['next_12m_total_eur']), rule: 'ink'),
                $this->stat(__('dividends.kpi.trailing_12m'), $this->eur($summary['trailing_12m_received_eur']), rule: 'positive', color: 'var(--divio-positive,#2f7d52)'),
                $this->stat(__('dividends.kpi.paying_positions'), $summary['instrument_count'], rule: 'neutral'),
            ]),
        ]);
    }
}
