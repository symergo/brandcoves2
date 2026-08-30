<?php

namespace App\Providers\Filament;

use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Pages\Dashboard;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\Support\Facades\FilamentView;
use Filament\View\PanelsRenderHook;
use Filament\Widgets\AccountWidget;
use Filament\Widgets\FilamentInfoWidget;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Vite;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

class AdminPanelProvider extends PanelProvider
{
    /**
     * Tailwind utilities for this panel's own Blade views.
     *
     * ## Why the panel needs this at all
     *
     * Filament ships a PREBUILT stylesheet with no utilities in it — only its
     * own `fi-*` component classes. Every layout class written in a custom page
     * is inert unless something else supplies them: the markup renders and none
     * of it is laid out, which looks exactly like a page nobody styled. Added
     * rather than swapped in, because `viteTheme()` would REPLACE Filament's
     * stylesheet, which means importing it back from `vendor/` at CSS-build
     * time — and the Dockerfile's frontend stage has no vendor directory. See
     * resources/css/filament/admin/theme.css.
     *
     * ## Why a render hook and not `->assets()`
     *
     * **Fixed 2026-08-30, after it shipped broken.** It was
     * `Css::make('panel-utilities', Vite::asset(...))`, which reads correctly
     * and is wrong in one specific way: `Vite::asset()` runs *once, at provider
     * boot*, and Filament keeps the string it returned.
     *
     * `asset()` builds its URL from the current request root, and at boot the
     * request has not been through `TrustProxies` yet — so behind a proxy that
     * terminates TLS and forwards plain HTTP with `X-Forwarded-Proto: https`,
     * the app still believes it is answering `http://`. The frozen string was
     * `http://giftcoves.com/build/...` on a page served over `https://`.
     * Browsers block mixed-content stylesheets outright, so production served
     * the link and no browser ever loaded it: every custom panel page rendered
     * with no utilities at all, which is precisely the failure this stylesheet
     * exists to prevent, reintroduced one layer up.
     *
     * Filament's own assets were unaffected because they call `asset()` when
     * they render, long after that middleware has run. That is why the panel
     * looked mostly right and only the custom pages collapsed.
     *
     * None of it was visible on a dev machine: localhost is `http://` end to
     * end, so the frozen scheme was the correct one.
     *
     * A render hook is evaluated per request, at render, so the URL is built
     * under the same conditions as every other link on the page.
     */
    public function boot(): void
    {
        // No parent::boot(): Filament's PanelProvider defines register() only,
        // and ServiceProvider has no boot() to call up to.
        FilamentView::registerRenderHook(
            // After Filament's own stylesheets: where the two disagree on an
            // element, the utility written in the page should be the one that
            // lands.
            PanelsRenderHook::STYLES_AFTER,
            fn (): string => app(Vite::class)(['resources/css/filament/admin/theme.css'])->toHtml(),
        );
    }

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
            /*
             * A closure, like brandLogo() beside it, and for the reason spelled
             * out in boot(): asset() called here runs at boot, before
             * TrustProxies, so behind a TLS-terminating proxy it freezes an
             * http:// URL into an https:// page. The logo was already right;
             * this was not.
             */
            ->favicon(fn (): string => asset('favicon.ico'))
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
