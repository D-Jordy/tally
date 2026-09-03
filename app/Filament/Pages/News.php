<?php

namespace App\Filament\Pages;

use App\Actions\ComputeNews;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;

class News extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedNewspaper;

    protected static ?int $navigationSort = 4;

    protected string $view = 'filament.pages.news';

    /** @var array{holdings: array, sectors: array, market: array}|null */
    private ?array $news = null;

    public static function getNavigationLabel(): string
    {
        return __('news.nav');
    }

    public function getTitle(): string
    {
        return __('news.title');
    }

    /** @return array{holdings: array, sectors: array, market: array} */
    public function news(): array
    {
        return $this->news ??= app(ComputeNews::class)->forUser(auth()->user());
    }

    public function hasNews(): bool
    {
        return collect($this->news())->flatten(1)->isNotEmpty();
    }
}
