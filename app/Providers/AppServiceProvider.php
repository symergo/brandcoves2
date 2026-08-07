<?php

declare(strict_types=1);

namespace App\Providers;

use App\Services\Connectors\Awin\AwinConnector;
use App\Services\Connectors\ConnectorRegistry;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // The single place that knows which connectors exist. Adding a source
        // is a registration here plus a config entry — the ingestion pipeline
        // and search service only ever see the interfaces.
        $this->app->singleton(ConnectorRegistry::class, function (): ConnectorRegistry {
            $registry = new ConnectorRegistry;

            $registry->registerFeed(new AwinConnector);

            // bol and Amazon land here in the next increments. Amazon stays
            // config-disabled until its credentials are verified.

            return $registry;
        });
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
