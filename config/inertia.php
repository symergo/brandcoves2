<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Server Side Rendering
    |--------------------------------------------------------------------------
    |
    | These options configures if and how Inertia uses Server Side Rendering
    | to pre-render the initial visits made to your application's pages.
    |
    | You can specify a custom SSR bundle path, or omit it to let Inertia
    | try and automatically detect it for you.
    |
    | Do note that enabling these options will NOT automatically make SSR work,
    | as a separate rendering service needs to be available. To learn more,
    | please visit https://inertiajs.com/server-side-rendering
    |
    */

    'ssr' => [

        'enabled' => (bool) env('INERTIA_SSR_ENABLED', true),

        'runtime' => env('INERTIA_SSR_RUNTIME', 'node'),

        'ensure_runtime_exists' => (bool) env('INERTIA_SSR_ENSURE_RUNTIME_EXISTS', false),

        'url' => env('INERTIA_SSR_URL', 'http://127.0.0.1:13714'),

        'hot_url' => env('INERTIA_SSR_HOT_URL'),

        /*
        |--------------------------------------------------------------------------
        | Bundle check — DEFAULTED OFF, and it must stay off
        |--------------------------------------------------------------------------
        |
        | Inertia's default is `true`: before dispatching to the SSR service it
        | checks that `bootstrap/ssr/ssr.js` exists on the *local* filesystem,
        | and returns null without a log line or an exception if it does not.
        |
        | That check assumes one container runs both PHP and Node. This
        | deployment splits them on purpose — the Dockerfile copies the bundle
        | into the `ssr` stage only, so the PHP image stays lean — so the `app`
        | container has no `bootstrap/ssr/` directory at all and the guard fired
        | on every request while the SSR service sat healthy and unused beside
        | it. Every page shipped as `<div id="app"></div>`: no `<title>`, no
        | `<h1>`, no body copy, for every crawler, on production and staging
        | alike. Nothing looks wrong in a browser, because the client hydrates.
        |
        | Defaulted here rather than set as an environment variable because the
        | split is a property of this repository, not of one deployment: a new
        | environment would otherwise inherit the same silent failure.
        |
        | The fallback is still safe when SSR is genuinely down — the HTTP call
        | fails, `handleSsrFailure()` catches it, and the page renders
        | client-side, which is the documented behaviour we actually want.
        */
        'ensure_bundle_exists' => (bool) env('INERTIA_SSR_ENSURE_BUNDLE_EXISTS', false),

        // 'bundle' => base_path('bootstrap/ssr/ssr.mjs'),

        /*
        |--------------------------------------------------------------------------
        | SSR Error Handling
        |--------------------------------------------------------------------------
        |
        | When SSR rendering fails, Inertia gracefully falls back to client-side
        | rendering. Set throw_on_error to true to throw an exception instead.
        | This is useful for E2E testing where you want SSR errors to fail loudly.
        |
        | You can also listen for the Inertia\Ssr\SsrRenderFailed event to handle
        | failures in your own way (e.g., logging, error tracking service).
        |
        */

        'throw_on_error' => (bool) env('INERTIA_SSR_THROW_ON_ERROR', false),

    ],

    /*
    |--------------------------------------------------------------------------
    | Pages
    |--------------------------------------------------------------------------
    |
    | Set `ensure_pages_exist` to true if you want to enforce that Inertia page
    | components exist on disk when rendering a page. This is useful for
    | catching missing or misnamed components.
    |
    | The `paths` and `extensions` options define where to look for page
    | components and which file extensions to consider.
    |
    */

    'pages' => [

        'ensure_pages_exist' => false,

        'paths' => [

            resource_path('js/pages'),

        ],

        'extensions' => [

            'js',
            'jsx',
            'svelte',
            'ts',
            'tsx',
            'vue',

        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Testing
    |--------------------------------------------------------------------------
    |
    | When using `assertInertia`, the assertion attempts to locate the
    | component as a file relative to the `pages.paths` AND with any of
    | the `pages.extensions` specified above.
    |
    | You can disable this behavior by setting `ensure_pages_exist`
    | to false.
    |
    */

    'testing' => [

        'ensure_pages_exist' => true,

    ],

    /*
    |--------------------------------------------------------------------------
    | Expose Shared Prop Keys
    |--------------------------------------------------------------------------
    |
    | When enabled, each page response includes a `sharedProps` metadata key
    | listing the top-level prop keys that were registered via `Inertia::share`.
    | The frontend can use this to carry shared props over during instant visits.
    |
    */

    'expose_shared_prop_keys' => true,

    /*
    |--------------------------------------------------------------------------
    | History
    |--------------------------------------------------------------------------
    |
    | Enable `encrypt` to encrypt page data before it is stored in the
    | browser's history state, preventing sensitive information from
    | being accessible after logout. Can also be enabled per-request
    | or via the `inertia.encrypt` middleware.
    |
    */

    'history' => [

        'encrypt' => (bool) env('INERTIA_ENCRYPT_HISTORY', false),

    ],

    /*
    |--------------------------------------------------------------------------
    | DevTools
    |--------------------------------------------------------------------------
    |
    | Records one entry per request to disk so the DevTools Chrome extension may
    | read it back over HTTP. Recording is limited to your local environment.
    | See https://inertiajs.com/docs/devtools for the gate and storage options.
    |
    */

    'devtools' => [

        'enabled' => env('INERTIA_DEVTOOLS_ENABLED'),

        'except' => ['telescope*', 'horizon*', '_inertia/devtools*'],

        'storage' => [

            'path' => storage_path('inertia-devtools'),

            'ttl' => (int) env('INERTIA_DEVTOOLS_TTL_HOURS', 24),

            'prune_interval' => (int) env('INERTIA_DEVTOOLS_PRUNE_INTERVAL_SECONDS', 300),

            'limit' => (int) env('INERTIA_DEVTOOLS_LIMIT', 100),

        ],

        'middleware' => ['web'],

        'gate' => env('INERTIA_DEVTOOLS_GATE'),

        'redact' => [

            'keys' => [
                'password',
                'password_confirmation',
                'current_password',
                'token',
                '_token',
                'access_token',
                'refresh_token',
                'secret',
                'client_secret',
                'api_key',
            ],

            'headers' => [
                'cookie',
                'set-cookie',
                'authorization',
                'proxy-authorization',
                'x-xsrf-token',
                'x-csrf-token',
            ],

        ],

    ],

];
