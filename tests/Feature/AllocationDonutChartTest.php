<?php

namespace Tests\Feature;

use App\Filament\Widgets\SectorAllocationChart;
use ReflectionMethod;
use Tests\TestCase;

class AllocationDonutChartTest extends TestCase
{
    private function js(array $slices): string
    {
        $widget = new SectorAllocationChart;
        $widget->slices = $slices;

        return (string) (new ReflectionMethod($widget, 'extraJsOptions'))->invoke($widget);
    }

    /** Regression: this JS is inlined in a double-quoted x-data attribute — one `"` kills the chart. */
    public function test_generated_js_never_contains_a_double_quote(): void
    {
        $js = $this->js([
            ['label' => 'Technology', 'title' => 'Technology', 'value' => 1200.0, 'holdings' => ['ASML "the" one', "O'Reilly"]],
        ]);

        $this->assertStringNotContainsString('"', $js);
        $this->assertStringContainsString('ASML &quot;the&quot; one', $js);
        $this->assertStringContainsString('ASML &quot;the&quot; one<br>O&#039;Reilly', $js);
    }

    public function test_slices_without_holdings_render_an_empty_list(): void
    {
        $js = $this->js([['label' => 'ASML', 'title' => 'ASML Holding', 'value' => 900.0]]);

        $this->assertStringContainsString("var holdings = [''];", $js);
    }
}
