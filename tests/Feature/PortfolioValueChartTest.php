<?php

namespace Tests\Feature;

use App\Filament\Widgets\PortfolioValueChart;
use Illuminate\Support\Collection;
use ReflectionMethod;
use Tests\TestCase;

class PortfolioValueChartTest extends TestCase
{
    /** @param  array<int, array<string, mixed>>  $history */
    private function series(string $mode, array $history): array
    {
        $widget = new PortfolioValueChart;
        $widget->mode = $mode;

        $method = new ReflectionMethod($widget, 'derivedOptions');

        return $method->invoke($widget, new Collection($history))['series'][0]['data'];
    }

    public function test_pl_series_is_value_minus_invested(): void
    {
        $history = [
            ['date' => '2024-01-02', 'total_value_eur' => 1000, 'invested_eur' => 1000],
            ['date' => '2024-06-30', 'total_value_eur' => 1200, 'invested_eur' => 1000],
        ];

        $this->assertSame([0.0, 200.0], $this->series('pl', $history));
    }

    public function test_roi_series_is_percentage_and_guards_zero_invested(): void
    {
        $history = [
            ['date' => '2024-01-01', 'total_value_eur' => 0, 'invested_eur' => 0], // pre-deposit → no div-by-zero
            ['date' => '2024-06-30', 'total_value_eur' => 1200, 'invested_eur' => 1000],
        ];

        $this->assertSame([0.0, 20.0], $this->series('roi', $history));
    }
}
