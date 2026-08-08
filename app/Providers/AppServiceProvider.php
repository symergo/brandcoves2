<?php

declare(strict_types=1);

namespace App\Providers;

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
use App\Services\Seo\PageMeta;
use Illuminate\Database\Eloquent\Model;
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
    }
}
