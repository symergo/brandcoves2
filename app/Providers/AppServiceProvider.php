<?php

declare(strict_types=1);

namespace App\Providers;

use App\Http\Middleware\AuthenticateApiToken;
use App\Services\Connectors\Awin\AwinConnector;
use App\Services\Connectors\Bol\BolConnector;
use App\Services\Connectors\ConnectorRegistry;
use App\Services\Discover\ModeEngine;
use App\Services\Discover\ModeRegistry;
use App\Services\Discover\Ranker;
use App\Services\Discover\Retrievers\CuratedRetriever;
use App\Services\Discover\Retrievers\FreshRetriever;
use App\Services\Discover\Retrievers\KeywordRetriever;
use App\Services\Discover\Retrievers\OutlierRetriever;
use App\Services\Discover\Retrievers\SlotsRetriever;
use App\Services\Discover\Retrievers\SpectrumRetriever;
use App\Services\Discover\Retrievers\TwoTowerRetriever;
use App\Services\Discover\Retrievers\ValueRetriever;
use App\Services\Seo\BrandLinker;
use App\Services\Seo\CopyBank;
use App\Services\Seo\PageMeta;
use App\Services\Settings\AiSettingsStore;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // scoped(), not singleton(): under FrankenPHP and Octane the container
        // persists between requests, and a singleton here would leak one page's
        // SEO metadata and structured data into the next visitor's page.
        $this->app->scoped(PageMeta::class);

        // Also scoped, and for a subtler reason than PageMeta: the cache here is
        // "which brands have a page", which is true for the length of a request
        // and changes when the nightly refresh runs. A singleton under Octane
        // would serve yesterday's answer until the process restarted, quietly
        // linking to brand pages that no longer exist.
        $this->app->scoped(BrandLinker::class);

        /*
         * Scoped for the same reason, and it matters more here: PageNarrative
         * asks for around thirty slots on one render, each through app(). Without
         * a shared instance every one of those is a fresh object with an empty
         * memo, so the two-minute cache is hit thirty times per page instead of
         * once. Scoped rather than singleton so an editor's save is visible on
         * the next request rather than after an Octane worker restarts.
         */
        $this->app->scoped(CopyBank::class);

        // The single place that knows which connectors exist. Adding a source
        // is a registration here plus a config entry — the ingestion pipeline
        // and search service only ever see the interfaces.
        $this->app->singleton(ConnectorRegistry::class, function (): ConnectorRegistry {
            $registry = new ConnectorRegistry;

            $registry->registerFeed(new AwinConnector);
            $registry->registerLive(new BolConnector);

            // Amazon is written but stays config-disabled until its credentials
            // are verified (Phase 8). Registering it here is what makes that a
            // config change rather than a refactor.

            return $registry;
        });

        /*
         * The discovery pipeline.
         *
         * The retriever list is the only place that knows which retrievers
         * exist. A mode profile names them by key; adding one is a class plus a
         * line here, and nothing in ModeEngine changes — which is the property
         * that makes "a mode is config" true rather than aspirational.
         *
         * `semantic` and `image` are absent deliberately — both need an
         * embedding index (Phase 8). The engine renormalises weights over what
         * is actually registered and available, so a profile naming a missing
         * retriever degrades onto its others instead of returning an empty
         * page. See config/discovery.php.
         */
        $this->app->singleton(ModeRegistry::class);

        $this->app->singleton(ModeEngine::class, fn ($app) => new ModeEngine(
            $app->make(ModeRegistry::class),
            $app->make(Ranker::class),
            [
                $app->make(KeywordRetriever::class),
                $app->make(OutlierRetriever::class),
                $app->make(CuratedRetriever::class),
                $app->make(FreshRetriever::class),
                $app->make(ValueRetriever::class),
                $app->make(SpectrumRetriever::class),
                $app->make(SlotsRetriever::class),
                $app->make(TwoTowerRetriever::class),
            ],
        ));
    }

    public function boot(): void
    {
        // Catch a missing eager-load in development rather than in production
        // as an N+1 across a page of search results.
        Model::preventLazyLoading(! $this->app->isProduction());

        // Behind Traefik the app receives plain HTTP. Without this, anything
        // that builds an absolute URL outside a request context — sitemap
        // generation, queued mail — emits http://.
        if ($this->app->isProduction()) {
            URL::forceScheme('https');
        }

        /*
         * Admin-editable AI settings, written over the config.
         *
         * Here rather than behind a new API because AiClient, AiUsage and the
         * usage table all read config('brandcoves.ai.*') already. Overlaying
         * means every existing caller keeps working and there is only ever one
         * way to ask whether AI is on — a second way is a way to get a stale
         * answer.
         *
         * Database wins over environment, which is the only order that makes the
         * settings screen mean anything. Untouched settings have no row and the
         * env value stands.
         */
        app(AiSettingsStore::class)->apply();

        $this->editorialApiLimits();
    }

    /**
     * Rate limits for the editorial API, keyed by token rather than by IP.
     *
     * By IP is wrong in both directions here. Two keys behind one CI runner
     * would share a budget and throttle each other; one key used from a
     * rotating address would never be limited at all. The token is the actor,
     * so the token is the key — and an unauthenticated caller has no token, so
     * it falls back to the address, which is the only thing it has.
     */
    private function editorialApiLimits(): void
    {
        $key = fn (Request $request): string => (string) (
            AuthenticateApiToken::from($request)?->id ?? $request->ip()
        );

        // Reads. Generous: a writer researching a Cove looks at a lot of
        // products, and making that expensive pushes it toward guessing instead.
        RateLimiter::for('editorial', fn (Request $request) => Limit::perMinute(
            (int) config('brandcoves.editorial_api.reads_per_minute')
        )->by($key($request)));

        // Writes. Tighter, because a writer stuck in a loop is the realistic
        // failure mode and each call rewrites rows.
        RateLimiter::for('editorial-writes', fn (Request $request) => Limit::perMinute(
            (int) config('brandcoves.editorial_api.writes_per_minute')
        )->by($key($request)));
    }
}
