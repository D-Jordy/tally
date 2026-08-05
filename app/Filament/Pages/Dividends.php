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
use Illuminate\Support\Collection;

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

    /** @var array<int, array{month: string, total_eur: float, rows: array<int, array<string, mixed>>}> */
    public array $timeline = [];

    /** @var array<int, array<string, mixed>> */
    public array $byInstrument = [];

    /** @var array<string, mixed> */
    public array $summary = [];

    public function mount(ComputeIncomingDividends $compute): void
    {
        [
            'confirmed' => $confirmed,
            'events' => $events,
            'by_instrument' => $byInstrument,
            'summary' => $summary,
        ] = $compute->forUser(auth()->user());

        $this->timeline = $this->buildTimeline([...$confirmed, ...$events]);
        $this->byInstrument = $byInstrument;
        $this->summary = $summary;
    }

    /**
     * Confirmed and projected payments on one chronological line, grouped per month.
     *
     * Sorted on the date the money lands where we know it: Yahoo fills pay_date only
     * on confirmed upcoming rows, so history and projections fall back to their
     * ex-date — the row says which one it is rather than pretending.
     *
     * @param  array<int, array<string, mixed>>  $events  confirmed + projected
     * @return array<int, array{month: string, total_eur: float, rows: array<int, array<string, mixed>>}>
     */
    private function buildTimeline(array $events): array
    {
        return collect($events)
            ->map(fn (array $event): array => [
                ...$event,
                'date' => $event['pay_date'] ?? $event['ex_date'],
                'is_pay_date' => $event['pay_date'] !== null,
            ])
            ->sortBy('date')
            ->groupBy(fn (array $event): string => substr($event['date'], 0, 7))
            ->map(fn (Collection $rows, string $month): array => [
                'month' => $month,
                'total_eur' => round((float) $rows->sum('expected_eur'), 2),
                'rows' => $rows->values()->all(),
            ])
            ->values()
            ->all();
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
            Section::make()->contained(false)->gridContainer()->columns(4)->schema([
                $this->stat(__('dividends.kpi.next_12m'), $this->eur($summary['next_12m_total_eur']), rule: 'ink'),
                $this->stat(__('dividends.kpi.trailing_12m'), $this->eur($summary['trailing_12m_received_eur']), rule: 'positive', color: 'var(--divio-positive,#2f7d52)'),
                $this->stat(__('dividends.kpi.yield_on_cost'), $this->pct($summary['yield_on_cost']) ?? '—', rule: 'positive', color: 'var(--divio-positive,#2f7d52)'),
                $this->stat(__('dividends.kpi.paying_positions'), $summary['instrument_count'], rule: 'neutral'),
            ]),
        ]);
    }
}
