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
     * Tally is self-hosted. A CDN @import would phone Google on every page load and
     * leave the panel unstyled on an offline install — the fonts ship in the bundle.
     */
    public function test_fonts_are_not_loaded_from_a_cdn(): void
    {
        $this->assertStringNotContainsString('fonts.googleapis.com', $this->theme());
        $this->assertStringContainsString("@import '@fontsource/inter", $this->theme());
    }

    /**
     * Both greys paint small text — the 10px uppercase KPI labels and the inactive
     * top-nav items — so WCAG AA wants 4.5:1 against the card they sit on.
     */
    public function test_muted_text_tokens_clear_wcag_aa(): void
    {
        $card = $this->token('divio-card');

        foreach (['divio-muted', 'divio-muted-nav'] as $token) {
            $this->assertGreaterThanOrEqual(
                4.5,
                $this->contrast($this->token($token), $card),
                "--{$token} is too light for small text on --divio-card.",
            );
        }
    }

    private function theme(): string
    {
        return File::get(resource_path('css/filament/app/theme.css'));
    }

    private function token(string $name): string
    {
        preg_match('/--'.$name.':\s*(#[0-9a-fA-F]{6})/', $this->theme(), $matches);

        $this->assertNotEmpty($matches, "--{$name} is not defined in theme.css.");

        return $matches[1];
    }

    /** WCAG 2.1 relative-luminance contrast ratio between two hex colours. */
    private function contrast(string $foreground, string $background): float
    {
        $lighter = max($this->luminance($foreground), $this->luminance($background));
        $darker = min($this->luminance($foreground), $this->luminance($background));

        return ($lighter + 0.05) / ($darker + 0.05);
    }

    private function luminance(string $hex): float
    {
        $channels = collect(str_split(ltrim($hex, '#'), 2))
            ->map(fn (string $pair): float => hexdec($pair) / 255)
            ->map(fn (float $value): float => $value <= 0.03928 ? $value / 12.92 : (($value + 0.055) / 1.055) ** 2.4)
            ->all();

        return 0.2126 * $channels[0] + 0.7152 * $channels[1] + 0.0722 * $channels[2];
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
