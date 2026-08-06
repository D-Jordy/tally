<?php

namespace Tests\Feature;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * The charts, tables and Blade fragments all used to carry their own copy of the
 * palette, which is how the KPI rules, chart legends and badges drifted apart.
 * Colours and fonts now come from one place each — this keeps it that way.
 */
class StyleTokensTest extends TestCase
{
    /**
     * ChartTheme holds the literal chart palette (Apex cannot read CSS vars); the donut
     * carries its own slice tints; the panel provider holds Filament's colour scales.
     */
    private const HEX_ALLOWED = [
        'app/Support/ChartTheme.php',
        'app/Filament/Widgets/AllocationDonutChart.php',
        'app/Providers/Filament/AppPanelProvider.php',
    ];

    public function test_no_raw_hex_colours_outside_the_palette_files(): void
    {
        $offenders = $this->sources()
            ->reject(fn (string $path): bool => in_array($this->relative($path), self::HEX_ALLOWED))
            ->filter(fn (string $path): bool => (bool) preg_match('/#[0-9a-fA-F]{6}\b/', $this->code($path)))
            ->map(fn (string $path): string => $this->relative($path));

        $this->assertSame([], $offenders->values()->all(), 'Use a --divio-* var (Blade/CSS) or a ChartTheme constant (Apex) instead of a raw hex.');
    }

    public function test_no_hardcoded_font_stacks(): void
    {
        $offenders = $this->sources()
            ->reject(fn (string $path): bool => $this->relative($path) === 'app/Support/ChartTheme.php')
            ->filter(fn (string $path): bool => (bool) preg_match('/(IBM Plex Mono|Inter,|Spectral)/', $this->code($path)))
            ->map(fn (string $path): string => $this->relative($path));

        $this->assertSame([], $offenders->values()->all(), 'Use var(--font-mono|sans|serif) in Blade or a ChartTheme constant in Apex options.');
    }

    /**
     * @return Collection<int, string>
     */
    private function sources(): Collection
    {
        return collect([...File::allFiles(app_path('Filament')), ...File::allFiles(resource_path('views'))])
            ->map(fn ($file): string => $file->getRealPath());
    }

    /** Comments explain the tokens by name, so they must not count as usages. */
    private function code(string $path): string
    {
        return preg_replace('#(//|\*|\{\{--).*$#m', '', File::get($path));
    }

    private function relative(string $path): string
    {
        return str_replace(base_path().'/', '', $path);
    }
}
