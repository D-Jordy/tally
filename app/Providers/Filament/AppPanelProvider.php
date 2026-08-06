<?php

namespace App\Providers\Filament;

use App\Http\Middleware\SetLocale;
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
            ->path('')
            ->viteTheme('resources/css/filament/app/theme.css')
            ->brandName(config('app.name'))
            ->brandLogo(fn () => view('filament.brand'))
            ->darkMode(false)
            ->topNavigation()
            ->login()
            ->registration()
            ->passwordReset()
            ->emailVerification()
            ->profile()
            // Every semantic colour is remapped to the divio palette. Filament's stock
            // success/danger/warning are saturated Tailwind tones that shout next to the
            // washed KPI figures, and badges/buttons all over the tables use them.
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
                // Warm paper greys, same scale the theme maps Tailwind's gray-* to.
                'gray' => [
                    50 => '#f7f6f2',
                    100 => '#efece4',
                    200 => '#e6e3da',
                    300 => '#d8d2c4',
                    400 => '#c4bfb3',
                    500 => '#9a9488',
                    600 => '#8a8474',
                    700 => '#4a463d',
                    800 => '#2a2a2a',
                    900 => '#1a1a1a',
                    950 => '#141414',
                ],
                // 500 = --divio-positive.
                'success' => [
                    50 => '#f1f6f2',
                    100 => '#e6efe6',
                    200 => '#c9dccf',
                    300 => '#a3c2ad',
                    400 => '#7aa588',
                    500 => '#5a8f6d',
                    600 => '#4a7a5b',
                    700 => '#3c6249',
                    800 => '#2f4d39',
                    900 => '#26402f',
                    950 => '#16261c',
                ],
                // 500 = --divio-negative; 50/200 are the existing danger bg/border tokens.
                'danger' => [
                    50 => '#fbeceb',
                    100 => '#f6dcd9',
                    200 => '#ecc4bf',
                    300 => '#dfa197',
                    400 => '#c98476',
                    500 => '#b06a5f',
                    600 => '#9a584e',
                    700 => '#7e463e',
                    800 => '#663832',
                    900 => '#54302b',
                    950 => '#2e1815',
                ],
                // Warm ochre, taken from the donut palette rather than Tailwind amber.
                'warning' => [
                    50 => '#faf5e9',
                    100 => '#f3e9cf',
                    200 => '#e6dab2',
                    300 => '#d6c28c',
                    400 => '#c4a968',
                    500 => '#b3924f',
                    600 => '#997b41',
                    700 => '#7c6335',
                    800 => '#63502c',
                    900 => '#524226',
                    950 => '#2e2413',
                ],
                // Dusty slate, also from the donut palette — Filament's stock info is bright blue.
                'info' => [
                    50 => '#f2f5f8',
                    100 => '#e3eaf0',
                    200 => '#c3ccd6',
                    300 => '#9fadbd',
                    400 => '#7b8da1',
                    500 => '#5f7186',
                    600 => '#4d5c6d',
                    700 => '#3f4a58',
                    800 => '#343d48',
                    900 => '#2c333c',
                    950 => '#1a1f25',
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
                SetLocale::class,
            ])
            ->authMiddleware([
                Authenticate::class,
            ]);
    }
}
