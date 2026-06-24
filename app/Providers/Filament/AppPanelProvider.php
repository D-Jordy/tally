<?php

namespace App\Providers\Filament;

use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Panel;
use Filament\PanelProvider;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;
use Leandrocfe\FilamentApexCharts\FilamentApexChartsPlugin;

class AppPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('app')
            ->path('app')
            ->viteTheme('resources/css/filament/app/theme.css')
            ->brandName(config('app.name'))
            ->brandLogo(fn () => view('filament.brand'))
            ->topNavigation()
            ->login()
            ->registration()
            ->passwordReset()
            ->emailVerification()
            ->profile()
            ->colors([
                // Ink-weighted neutral scale: primary buttons/links read as #1a1a1a.
                'primary' => [
                    50 => '#f6f5f3',
                    100 => '#e6e3da',
                    200 => '#cdc8ba',
                    300 => '#a8a294',
                    400 => '#6e6a5f',
                    500 => '#3a3833',
                    600 => '#1a1a1a',
                    700 => '#161616',
                    800 => '#121212',
                    900 => '#0e0e0e',
                    950 => '#0a0a0a',
                ],
            ])
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\Filament\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\Filament\Pages')
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\Filament\Widgets')
            ->plugins([
                FilamentApexChartsPlugin::make(),
            ])
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                PreventRequestForgery::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->authMiddleware([
                Authenticate::class,
            ]);
    }
}
