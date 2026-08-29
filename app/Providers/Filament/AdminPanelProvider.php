<?php

namespace App\Providers\Filament;

use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Pages\Dashboard;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Assets\Css;
use Filament\Support\Colors\Color;
use Filament\Widgets\AccountWidget;
use Filament\Widgets\FilamentInfoWidget;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Vite;
use Illuminate\View\Middleware\ShareErrorsFromSession;

class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->path('admin')
            ->login()
            ->colors([
                'primary' => Color::Amber,
            ])
            // The same mark the site wears.
            ->brandName('GiftCoves')
            ->brandLogo(fn () => asset('icons/giftcoves.svg'))
            ->brandLogoHeight('2rem')
            ->favicon(asset('favicon.ico'))
            /*
             * Tailwind utilities for this panel's own Blade views.
             *
             * Filament ships a PREBUILT stylesheet with no utilities in it at
             * all — only its own `fi-*` component classes. Every layout class
             * written in a custom page was therefore inert: the markup rendered
             * and none of it was laid out, which looks exactly like a page
             * nobody styled.
             *
             * Added rather than swapped in. `viteTheme()` would REPLACE
             * Filament's stylesheet, which means importing it back from
             * `vendor/` at CSS-build time — and the Dockerfile's frontend stage
             * has no vendor directory, so the image would stop building. See
             * resources/css/filament/admin/theme.css.
             */
            ->assets([
                Css::make('panel-utilities', Vite::asset('resources/css/filament/admin/theme.css')),
            ])
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\Filament\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\Filament\Pages')
            ->pages([
                Dashboard::class,
            ])
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\Filament\Widgets')
            ->widgets([
                AccountWidget::class,
                FilamentInfoWidget::class,
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
