<?php

declare(strict_types=1);

/**
 * Application configuration.
 *
 * Anything a human might want to change without a code change belongs here or
 * in the admin panel. Anything that changes behaviour subtly belongs here with
 * a comment explaining why the default is what it is.
 */
return [

    'commit_sha' => env('GIT_COMMIT_SHA', 'dev'),

    // Staging must never be indexed. Production sets ROBOTS_ALLOW=true.
    'robots_allow' => (bool) env('ROBOTS_ALLOW', false),

    /*
    |--------------------------------------------------------------------------
    | Search
    |--------------------------------------------------------------------------
    */
    'search' => [
        'per_page' => 24,

        // Per-merchant cap in the "by store" view. Without it, one recently
        // ingested merchant with a huge feed monopolises every result slot and
        // the store lanes show a single shop.
        'store_lane_cap' => 8,

        // Query the trigram index with the word_similarity operator `<%`, not
        // `%`. Measured on a real title, "blutooth koptelefon" vs "Draadloze
        // Bluetooth Koptelefoon met ruisonderdrukking" scores 0.298 on
        // similarity() — below the 0.3 default, so the typo finds nothing —
        // and 0.696 on word_similarity().
        //
        // 0.45 is a starting point: below Postgres' 0.6 default so single
        // misspelled words still match, but not so low that unrelated products
        // leak in. Must be re-tuned against a real catalogue in Phase 2.
        'trigram_threshold' => 0.45,

        // Live connector results are cached this long. Long enough to absorb a
        // burst of identical searches, short enough that a price on the results
        // page is not embarrassingly stale.
        'live_cache_ttl' => 900,
    ],

    /*
    |--------------------------------------------------------------------------
    | Product identity and grouping
    |--------------------------------------------------------------------------
    */
    'identity' => [
        // Rows with a shorter title are left ungrouped. A wrong merge shows two
        // different products as one offer set, which is worse than not merging.
        'min_title_length' => 10,

        // Fall back to brand + normalised title when a row has no EAN. Without
        // this the large share of feed rows that carry no EAN could never be
        // compared across merchants at all.
        'allow_title_fallback' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Gift Whisperer
    |--------------------------------------------------------------------------
    */
    'gift' => [
        // Cents. Outside this window an item is not a gift: below it reads as an
        // afterthought, above it as an obligation.
        'min_price' => 500,
        'max_price' => 50000,

        'results' => 4,

        // Maximal Marginal Relevance. Without diversification the top four are
        // near-duplicates, because whatever scores well scores well for the same
        // reasons. 0.65 favours relevance while still breaking up clusters.
        'mmr_lambda' => 0.65,

        // Score weights, summing to 100.
        'weights' => [
            'interest_fit' => 40,
            'budget_fit' => 20,
            'surprise' => 20,
            'vibe' => 10,
            'values' => 10,
        ],

        // Budget fit peaks below the stated maximum rather than at the cheapest
        // option: a €12 gift against a €100 budget reads as thoughtless, not
        // thrifty.
        'budget_sweet_spot' => 0.85,
    ],

    /*
    |--------------------------------------------------------------------------
    | Daily Picks
    |--------------------------------------------------------------------------
    */
    'picks' => [
        'per_day' => 7,

        // Fixed drop time makes it an appointment, like a daily puzzle.
        'drop_time' => '09:00',

        // Never repeat a product within this window.
        'memory_days' => 90,

        // Themes are avoided for this long, so the editorial voice does not loop.
        'theme_memory_days' => 60,

        // Surprise scoring: deliberately ranks for the OPPOSITE of what
        // retailers rank for. Embedding distance from the category centroid is
        // the strongest known signal and is deferred to Phase 8 — the scorer
        // takes an open extras map so it folds in without reshaping anything.
        'surprise_weights' => [
            'category_rarity' => 0.35,
            'brand_rarity' => 0.25,
            'price_oddity' => 0.15,
            'freshness' => 0.15,
            'merchant_exclusivity' => 0.10,
        ],

        // The high-discount lane. Measured against our own 30-day median, not a
        // merchant-supplied "was" price, which is frequently fiction.
        'discount' => [
            'min_percent' => 25,
            // Cents. 40% off a €5 cable is not a deal worth a slot.
            'min_absolute_saving' => 1500,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Buying guides
    |--------------------------------------------------------------------------
    */
    'guides' => [
        'items_per_guide' => 8,
        'min_products' => 6,
        'topic_window_days' => 30,
        'freshness_check_days' => 30,
    ],

    /*
    |--------------------------------------------------------------------------
    | Wishlists
    |--------------------------------------------------------------------------
    */
    'wishlist' => [
        // Dedicated secret rather than APP_KEY, so rotating APP_KEY does not
        // orphan every existing claim.
        'claim_hash_secret' => env('CLAIM_HASH_SECRET', ''),
        'claim_undo_hours' => 24,
    ],

    /*
    |--------------------------------------------------------------------------
    | AI
    |--------------------------------------------------------------------------
    |
    | HARD INVARIANT: AI is only ever called from a queued job, never from a
    | request handler. A visitor request must never be able to cause AI spend.
    | With ai.enabled = false the site still works — curated daily-pick themes
    | and template guide copy.
    */
    'ai' => [
        'enabled' => (bool) env('AI_ENABLED', false),
        'api_key' => env('ANTHROPIC_API_KEY'),
        'model' => env('ANTHROPIC_MODEL', 'claude-sonnet-5'),

        'default_daily_cap' => 20,

        // Every AI caller must register a key here, otherwise its spend is
        // invisible in the admin usage table.
        'caps' => [
            // 1 theme + 7 blurbs per market per day, plus headroom for retries.
            'daily_picks' => 60,
            'guide_copy' => 30,
            'gift_angles' => 20,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Connectors
    |--------------------------------------------------------------------------
    */
    'connectors' => [
        'awin' => [
            'enabled' => true,
            'chunk_size' => 1000,

            /*
             * Awin publisher accounts.
             *
             * More than one, because an advertiser is only reachable through the
             * account actually joined to them. Vanden Borre sits under a
             * separate account from Coolblue and Krefel, and its feeds are
             * completely absent from the primary account's feed list — not
             * "Not Joined", simply not there.
             *
             * Each feed records which account it came from, so the connector
             * downloads it with the right key. Accounts with no token are
             * skipped silently, which is what lets this ship before the second
             * set of credentials exists.
             */
            'accounts' => array_filter([
                'default' => [
                    'label' => 'Brandcoves',
                    'api_token' => env('AWIN_API_TOKEN'),
                    'publisher_id' => env('AWIN_PUBLISHER_ID'),
                ],
                'vandenborre' => [
                    'label' => 'Vanden Borre',
                    'api_token' => env('AWIN_VDB_API_TOKEN'),
                    'publisher_id' => env('AWIN_VDB_PUBLISHER_ID'),
                ],
            ], fn (array $account) => filled($account['api_token'])),

            // Kept for anything still reading the single-token form.
            'api_token' => env('AWIN_API_TOKEN'),
            'publisher_id' => env('AWIN_PUBLISHER_ID'),

            /*
             * Advertisers we actually want, matched loosely on name.
             *
             * An allowlist rather than "the biggest feeds", because feed size is
             * a terrible proxy for usefulness here. FNAC's Belgian catalogue is
             * 550,000 rows of marketplace listings; Krefel and Coolblue are a
             * tenth the size but sell overlapping mainstream products with real
             * EANs — which is what makes two offers comparable at all.
             *
             * Comparison needs the SAME product at DIFFERENT shops. Three
             * overlapping electronics retailers produce far more comparable
             * products than one enormous marketplace.
             *
             * Empty array means "no filter", which is how you explore what else
             * is available: bc:awin-feeds --all --dry-run
             */
            'advertisers' => ['krefel', 'coolblue', 'vandenborre'],
        ],

        'bol' => [
            'enabled' => true,
            'client_id' => env('BOL_CLIENT_ID'),
            'client_secret' => env('BOL_CLIENT_SECRET'),
            // Turns a plain product URL into a tracked one. Without it the
            // click still works but earns nothing.
            'partner_id' => env('BOL_PARTNER_ID'),

            // bol documents 10 requests/second. A token bucket can emit
            // capacity + rate inside a single second — the full bucket plus
            // everything that refills while it drains — so rate 8 / burst 2
            // makes "never more than 10 in any rolling second" actually true.
            // The cost is ~20% throughput, which is the right trade against a 429.
            'rate' => 8.0,
            'burst' => 2,

            // On a 429, drain the bucket and stop trying for this long. Degrade
            // to the remaining sources rather than retrying into the wall.
            'cooldown_seconds' => 60,
        ],

        // Deferred to Phase 8. The connector is written and registered but
        // disabled, so enabling it is a credentials step rather than a refactor.
        'amazon' => [
            'enabled' => (bool) env('AMAZON_ENABLED', false),
            'access_key' => env('AMAZON_ACCESS_KEY'),
            'secret_key' => env('AMAZON_SECRET_KEY'),
            'partner_tags' => env('AMAZON_PARTNER_TAGS', ''),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Credentials at rest
    |--------------------------------------------------------------------------
    */
    'credentials_key' => env('CREDENTIALS_ENCRYPTION_KEY', ''),
];
