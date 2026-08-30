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
    | The domain, and the one it replaced
    |--------------------------------------------------------------------------
    |
    | The site was called Brandcoves and lived at brandcoves.com until the rename
    | to GiftCoves. Both domains point at this same instance, so `RedirectLegacyHost`
    | 301s anything arriving on an old host to the same path on the canonical one.
    |
    | Both are empty by default. Local development and any environment that has
    | not been cut over must not redirect, and a half-configured pair is the one
    | state that could send a visitor somewhere that does not answer.
    |
    | `canonical_host` is a bare host — no scheme, no trailing slash — because the
    | scheme is taken from the incoming request, which is what keeps a local HTTP
    | test from being bounced to HTTPS.
    */
    'canonical_host' => env('CANONICAL_HOST', ''),

    /** Comma-separated. Every host that should 301 to `canonical_host`. */
    'legacy_hosts' => array_values(array_filter(array_map(
        trim(...),
        explode(',', (string) env('LEGACY_HOSTS', '')),
    ))),

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
        //
        // THIS VALUE IS APPLIED AS A SESSION SETTING, not as a WHERE clause.
        // `pg_trgm.word_similarity_threshold` is what the `<%` operator compares
        // against, and only the operator can use the trigram index — spelling the
        // same threshold as `word_similarity(?, title) >= 0.45` is an ordinary
        // function call, which no index can answer and which therefore forced a
        // sequential scan of product_groups on every search. AppServiceProvider
        // pushes this to Postgres on connect, so config stays the one place it is
        // set and a fresh clone cannot silently run on Postgres' 0.6 default.
        'trigram_threshold' => 0.45,

        // What `<%` meant before the threshold above became global: Postgres'
        // own default.
        //
        // Discover's anchor lookup and the narrative's related-search chips were
        // written against 0.6 and are not search — they answer "what is near
        // this?", where a loose match is a wrong neighbour rather than a
        // forgiving typo. Lowering the session threshold would have widened them
        // as a side effect, so both re-check against this explicitly. The `<%`
        // still drives the index; this only narrows what survives.
        'trigram_threshold_strict' => 0.6,

        // Live connector results are cached this long. Long enough to absorb a
        // burst of identical searches, short enough that a price on the results
        // page is not embarrassingly stale.
        'live_cache_ttl' => 900,

        // Facet counts are cached this long.
        //
        // Facets are computed from market, term and in-stock only — deliberately
        // ignoring the active filters, so a filter UI does not erase its own
        // options. That makes them identical across every brand, price, sort and
        // page variant of one term, and re-running three aggregates per click was
        // the largest remaining cost on a search page.
        //
        // The cost is a sidebar that can trail the grid by this long: a search
        // folds live offers in and moves merchant_count, so a count may be one
        // behind. Five minutes keeps that invisible in practice.
        'facet_cache_ttl' => 300,
    ],

    /*
    |--------------------------------------------------------------------------
    | The Amazon search hand-off
    |--------------------------------------------------------------------------
    |
    | One link, in the search sidebar: run this same term on Amazon.
    |
    | Deliberately NOT under `connectors.amazon`, and deliberately not gated on
    | `AMAZON_ENABLED`. That flag governs the PA-API connector, which is still
    | Phase 8 and needs credentials. This is an outbound anchor — no API call,
    | no catalogue row, nothing stored — so it works on every environment the
    | moment the tag is known, and tying it to the connector's flag would leave
    | a shipped feature switched off waiting on an unrelated one.
    |
    | It is also the one place Amazon may legitimately appear without the
    | mirroring problem invariant 6 exists for: a search URL stores no title, no
    | price and no image, because we never read the response.
    |
    | A market with no tag gets no link. Sending traffic to a storefront under
    | somebody else's tag, or under none, is unattributed either way — and an
    | untagged Amazon link is the failure that looks exactly like a working one.
    |
    */
    'amazon_search' => [
        /*
         * market => [storefront host, Associates tag].
         *
         * Belgium goes to `amazon.com.be` and the Netherlands to `amazon.nl`,
         * which is NOT what `AmazonLocale::primaryFor()` says — that prefers
         * `amazon.nl` for Belgian visitors because it is the deeper catalogue.
         * The tag is why they differ: an Associates tag is issued per
         * marketplace, so a `.be` tag on a `.nl` URL tracks nothing. Attribution
         * decides the host here; catalogue depth decides it there.
         *
         * `be-fr` uses the same `.com.be` storefront as `be-nl` — Amazon serves
         * that marketplace in both languages off one host, and there is no
         * separate French-Belgian tag to route to.
         *
         * `en` and `es` are absent, not empty: no tag has been issued for
         * `amazon.co.uk` or `amazon.es` under this account, so those markets
         * show no link at all rather than an untracked one.
         */
        'markets' => [
            'nl-nl' => ['host' => 'www.amazon.nl', 'tag' => env('AMAZON_TAG_NL', 'giftcoves-21')],
            'be-nl' => ['host' => 'www.amazon.com.be', 'tag' => env('AMAZON_TAG_BE', 'giftcoves05-21')],
            'be-fr' => ['host' => 'www.amazon.com.be', 'tag' => env('AMAZON_TAG_BE', 'giftcoves05-21')],
        ],
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

        // Score weights, summing to 100. The fallback when a profile names none;
        // `demand` is absent here so an unweighted profile leaves the bestseller
        // signal switched off rather than silently on.
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

        /*
        |----------------------------------------------------------------------
        | Suggestion profiles
        |----------------------------------------------------------------------
        |
        | The same engine answers two questions — "what should I buy them?" and
        | "what do I want?" — and they are not the same question, even though
        | the retrieval, the diversification and the explanation all are.
        |
        | `budget_shape` is the one that would silently go wrong if this were a
        | single profile. The sweet-spot curve exists because a cheap *gift*
        | reads as thoughtless, which is a fact about how a present is received
        | by another person. Nobody thinks their own €12 wish is thoughtless, so
        | applying that curve to your own list would quietly push every
        | affordable thing to the bottom — and look exactly like a working
        | feature while doing it.
        |
        | Keys mirror the shape of a Mode Profile in config/discovery.php on
        | purpose, so folding these into the discovery dial later is a data
        | change rather than a rewrite.
        */
        'profiles' => [
            'for_someone' => [
                'weights' => [
                    'interest_fit' => 40,
                    'budget_fit' => 20,
                    'surprise' => 20,
                    'vibe' => 10,
                    'values' => 10,
                    /*
                     * Zero, deliberately, and the zero is the decision.
                     *
                     * A bestseller chart is a demand signal we now hold, and
                     * this is the one place it must not be spent. `surprise`
                     * exists to stop the best-stocked product winning every
                     * tie; paying for demand alongside it would cancel that out
                     * and turn the Whisperer into a chart, while looking like a
                     * working feature. Chart products still reach the candidate
                     * pool — see SuggestionEngine::withDemandCoverage() — they
                     * just earn their place on the same terms as everything
                     * else. Raising this is a product decision, not a tuning
                     * one.
                     */
                    'demand' => 0,
                ],
                'mmr_lambda' => 0.65,
                'budget_shape' => 'sweet_spot',
            ],

            'for_myself' => [
                'weights' => [
                    'interest_fit' => 40,
                    // Any affordable price is equally fine, so the signal
                    // carries less and discriminates less.
                    'budget_fit' => 10,
                    // Still present, and lower. You already know your own
                    // taste, so the job is fewer blind spots rather than
                    // novelty for its own sake — but at zero the list collapses
                    // into whatever is best stocked.
                    'surprise' => 15,
                    'vibe' => 15,
                    'values' => 15,
                    // Small, and the opposite sign to the gift case. Nobody
                    // wants a surprising kettle on their own list; they want the
                    // one that turns out to be good, and "lots of people bought
                    // it" is the cheapest available evidence of that. Kept low
                    // so it breaks ties rather than choosing.
                    'demand' => 5,
                ],
                // Slightly stronger diversification: a wishlist of four
                // variations on one thing is less useful than a gift page of
                // them, because you will actually be given all four.
                'mmr_lambda' => 0.6,
                'budget_shape' => 'in_range',
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Daily Picks
    |--------------------------------------------------------------------------
    */
    /*
     * The "biggest drops" column beside the Daily Cove.
     *
     * A percentage on a cheap thing is noise: a phone case whose median is
     * €25 and which is now €4.70 is an 81% drop and not a find. Left on
     * percentage alone the column filled with silicone covers, which is
     * accurate, useless, and makes the whole page look like a bargain bin.
     */
    'deals' => [
        // Seen recently enough that we would stand behind the price.
        'window_days' => 14,

        // Below this a percentage swing says more about the price point than
        // about the offer.
        'min_price' => 2000,

        // And the saving has to be worth crossing the room for, in money and
        // not only in percent.
        'min_saving' => 1000,

        // One product per brand: six covers from one maker is one fact
        // repeated, the same reason feed discovery takes one feed per
        // advertiser.
        'per_brand' => 1,

        'limit' => 6,
    ],

    'picks' => [
        /*
         * Six, because the page shows them three across in two full rows.
         *
         * Seven left a single card alone on a third row, which reads as a
         * product that failed to load rather than the end of the list. The
         * number is a layout fact before it is an editorial one — an edition is
         * as long as its grid is wide, and the grid is three wide because it
         * shares the container with the rail.
         */
        'per_day' => 6,

        /*
         * Below this, the edition does not publish at all.
         *
         * A three-item page is worse than no page: it teaches a returning
         * reader that the column is not worth opening, and that lesson outlasts
         * the bad catalogue day that caused it. Lifted out of EditionBuilder so
         * the curation screen can warn about a locked plan that is under it
         * *before* 06:00 rather than after.
         */
        'minimum' => 3,

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
    /*
     * `items_per_guide` and `min_products` had no readers until the fold: the
     * builder used class constants of 7 and 5, the topic model tested viability
     * against 6, and the miner used a third 5 of its own. Three numbers for two
     * questions, and the one an editor could see in config was the one nothing
     * obeyed.
     *
     * They now say what has actually produced every guide on the site. Seven
     * entries is long enough to be a real comparison and short enough that each
     * one earns its place; below five it is a list with gaps and reads as one,
     * which is also the threshold the miner already refused to offer a topic
     * under. See App\Enums\CoveKind::targetItems() and minimumItems().
     */
    'guides' => [
        'items_per_guide' => 7,
        'min_products' => 5,
        'topic_window_days' => 30,
        'freshness_check_days' => 30,
    ],

    /*
    |--------------------------------------------------------------------------
    | Pages an article may link to
    |--------------------------------------------------------------------------
    |
    | The destinations behind `[[page:key]]`. A curated map rather than a free
    | path, for the same reason every other link token is checked: a writer
    | asked for a link to "the gift finder" will invent `/gifts`, `/gift-finder`
    | and `/tools/gifts` with equal confidence, and each one is a 404 in the
    | middle of an article.
    |
    | Unlike products and brands this list is ours rather than a feed's, so it
    | needs no per-article allowlist — every key here is valid in every market.
    | The value is the path after `/{market}/`; an empty string is the market
    | home page.
    |
    | Adding a page here is the only step needed to make it linkable. Renaming a
    | route without updating this is caught by EditorialLinkTest, which resolves
    | every entry against the router.
    */
    'linkable_pages' => [
        'home' => '',
        'search' => 'search',
        'discover' => 'discover',
        'daily' => 'daily',
        'guides' => 'guides',
        'brands' => 'brands',
        'gift-whisperer' => 'gift',
        'gift-cove' => 'gift-cove',
        'wishlists' => 'lists',
        'scanner' => 'scan',
        'secret-santa' => 'santa',
        'surprise' => 'surprise',
    ],

    /*
    |--------------------------------------------------------------------------
    | Editorial API
    |--------------------------------------------------------------------------
    |
    | Machine access to the writing surfaces. See docs/features/editorial-api.md.
    |
    */
    'editorial_api' => [
        // Matches EditionBuilder::EDITORIAL_LIMIT, so a Cove reads the same
        // length whoever wrote it — and so an author is told at the door rather
        // than having their last paragraph silently truncated on the way into
        // the edition. Raised from 4000 with the rule that every product gets
        // its own paragraph: at 4000 an author covering seven finds had 570
        // characters each, and the truncation took the last product's link
        // token with it, so it lost its card as well as its writing.
        'max_editorial_chars' => 8000,

        // Reads are generous on purpose: researching a Cove means looking at a
        // lot of products, and an author who finds lookup expensive starts
        // guessing product ids instead, which is the failure the lookup exists
        // to prevent.
        'reads_per_minute' => 120,

        // Writes are tighter. Each one rewrites rows, and a writer stuck in a
        // retry loop is the realistic way this gets hammered.
        'writes_per_minute' => 20,
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
        /*
         * Calls per feature per day. Measured against what the callers actually
         * do, not guessed — a cap set from a stale comment is a cap nobody can
         * reason about.
         *
         *   daily_picks  EditionBuilder, once per market per day: one call for
         *                the theme, one for the editorial. 5 markets × 2 = 10,
         *                doubled by the job's single retry. 30 is 3× headroom.
         *                (Was 60, from a comment claiming "1 theme + 7 blurbs" —
         *                there is no per-pick blurb call and there never was.)
         *
         *   guide_copy   GuideBuilder, one call per Cove. An edition builds at
         *                most one per market per day, and only when a topic is
         *                ripe: 0–5.
         *
         *   gift_angles  WidenGiftAngles, one call per market per night: 5. Also
         *                carries the credential test from the settings page.
         *
         *   community_triage
         *                TriageCommunityPost, one call per question or answer
         *                posted, doubled by the job's single retry. This is the
         *                only cap driven by *visitor* volume rather than by a
         *                schedule, so it is both the largest and the one that
         *                matters most: reaching it does not break anything, it
         *                just means the rest of the day's posts wait for a human
         *                instead of publishing themselves. 400 is roughly 200
         *                posts a day across five markets, which is far more
         *                community than this site has and cheap enough to be
         *                wrong about.
         */
        'caps' => [
            'daily_picks' => 30,
            'guide_copy' => 30,
            'gift_angles' => 20,
            'community_triage' => 400,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Who operates this site
    |--------------------------------------------------------------------------
    |
    | The legal identity behind GiftCoves, in one place because it appears in
    | three documents in four languages and a company number that disagrees with
    | itself across twelve files is a real problem rather than a typo.
    |
    | The legal pages interpolate these. Anything left empty is rendered as a
    | visible gap rather than silently omitted — an address that quietly vanishes
    | from an imprint is worse than one that says it is missing, because Belgian
    | law requires it to be there and only the visible version gets fixed.
    |
    | `company_number` is the Belgian enterprise number, which doubles as the VAT
    | number prefixed with BE.
    */
    'company' => [
        /*
         * Symergo CommV is the operating entity; GiftCoves is the site it runs.
         *
         * The legal form belongs in the name rather than being implied: a
         * Belgian imprint has to identify the company as it is registered, and
         * "Symergo" alone does not say who is liable for what. CommV is a
         * commanditaire vennootschap.
         */
        'name' => env('COMPANY_NAME', 'Symergo CommV'),
        'number' => env('COMPANY_NUMBER', 'BE0566975391'),
        'address' => env('COMPANY_ADDRESS', 'Schoonzichtlaan 13A, 3012 Leuven, Belgium'),

        /*
         * These mailboxes do not exist yet.
         *
         * Both are published in the legal pages and `email` is also
         * MAIL_FROM_ADDRESS, so until they are created a reply to any mail the
         * site sends bounces, and the GDPR contact point the privacy policy
         * commits to answering within a month is unreachable. That second one is
         * itself a compliance problem, not a to-do.
         *
         * Overridable by env so a working address can be swapped in without a
         * deploy.
         */
        'email' => env('COMPANY_EMAIL', 'hello@giftcoves.com'),
        'privacy_email' => env('PRIVACY_EMAIL', 'privacy@giftcoves.com'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Editable page copy
    |--------------------------------------------------------------------------
    |
    | The templated prose on search and brand pages is editable in admin, with
    | several variants per slot. This decides how often a given page swaps to a
    | different one.
    |
    | Not per request, deliberately. A page whose wording changes on every load
    | cannot be cached, flickers for anyone who hits back, and shows a crawler a
    | different document each fetch — which reads as an unstable page rather than
    | a fresh one. The draw is deterministic given the page and the period, and
    | the period is what moves.
    |
    |   weekly   default. Stable through any crawl window, visibly different a
    |            month later. ISO weeks, so the boundary is a Monday.
    |   daily    faster churn. Fine, and it costs edge-cache hit rate.
    |   monthly  slower.
    |   static   one variant per page, forever — the setting for comparing two
    |            rewrites rather than churning through them.
    |
    | Variety ACROSS pages is always on and is not affected by this: the page's
    | own identity is in the seed, so two brands never share a draw by default.
    */
    'copy' => [
        'rotation' => env('COPY_ROTATION', 'weekly'),
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
             * skipped, which is what lets this ship before the second set of
             * credentials exists.
             *
             * Skipping them *silently* was a mistake, and it cost a publisher's
             * worth of catalogue. `AWIN_VDB_*` was set in `.env` and never
             * passed through `docker-compose.coolify.yml`, so a laptop ingested
             * from two accounts and every deployed environment ingested from
             * one — with no error, because "no token" and "token that cannot
             * reach me" look identical from here.
             *
             * `declared` is the fix: the filter still decides what runs, but
             * the full list survives so a diagnostic can say *which* account is
             * absent and *which* variable to go and set. See `bc:check-config`
             * and the top of `bc:awin-feeds`.
             */
            'accounts' => array_filter([
                'default' => [
                    'label' => 'GiftCoves',
                    'api_token' => env('AWIN_API_TOKEN'),
                    'publisher_id' => env('AWIN_PUBLISHER_ID'),
                ],
                'vandenborre' => [
                    'label' => 'Vanden Borre',
                    'api_token' => env('AWIN_VDB_API_TOKEN'),
                    'publisher_id' => env('AWIN_VDB_PUBLISHER_ID'),
                ],
            ], fn (array $account) => filled($account['api_token'])),

            /** Every account this build knows about, and the variable each needs. */
            'declared_accounts' => [
                'default' => ['label' => 'GiftCoves', 'env' => 'AWIN_API_TOKEN'],
                'vandenborre' => ['label' => 'Vanden Borre', 'env' => 'AWIN_VDB_API_TOKEN'],
            ],

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
             * Two entries here are NOT on comparison grounds, and that is
             * deliberate — the site has to answer "what should I buy for this
             * person", not only "who is cheapest", and electronics alone is a
             * narrow answer to the first question:
             *
             * - dreamland: 31k toys in be-nl, 23k in be-fr. Toys are the most
             *   gift-shaped category on the network and nothing else here sells
             *   them. FNAC's toy catalogue (CatalogJouetsAll, 23k NL / 69k FR)
             *   would be its natural comparison partner and stays excluded — the
             *   rest of FNAC is 1.2M rows of music, books and DVDs, and the
             *   allowlist matches on advertiser, so there is no way to take the
             *   toys without the marketplace.
             * - action: only 188 rows, be-nl only. Kept because it is the sole
             *   low-price general retailer available, which is the price band
             *   gift discovery is otherwise blind to. Small enough that the
             *   download costs nothing; drop it if the rows prove to be junk.
             *
             * Empty array means "no filter", which is how you explore what else
             * is available: bc:awin-feeds --all --dry-run
             */
            'advertisers' => ['krefel', 'coolblue', 'vandenborre', 'dreamland', 'action'],
        ],

        'bol' => [
            'enabled' => true,
            'client_id' => env('BOL_CLIENT_ID'),
            'client_secret' => env('BOL_CLIENT_SECRET'),

            /*
             * Partner site ids, per country.
             *
             * bol attributes a sale through the partner.bol.com click tracker,
             * NOT through a parameter on the product URL — a tracked link is a
             * redirect through their domain carrying a site id. Getting this
             * wrong costs nothing visible: the click works, the visitor buys,
             * and the commission goes to nobody.
             *
             * Two ids because the Belgian and Dutch partner programmes are
             * separate accounts. Defaults are the ids v1 has been earning on.
             * Not secrets — they appear in every outbound link.
             */
            'partner_site_id' => [
                'BE' => env('BOL_PARTNER_SITE_ID_BE', '25421'),
                'NL' => env('BOL_PARTNER_SITE_ID_NL', '1005548'),
            ],

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

            /*
             * Bestseller charts — an internal demand signal, never a page.
             *
             * bol's /products/lists/popular is a browse endpoint: no search
             * term, so what comes back is what bol actually sells the most of.
             * It feeds product suggestions and market-trend identification. Its
             * ranking is never republished as visible content — see
             * docs/features/popularity-charts.md.
             */
            'popular' => [
                'enabled' => true,

                // bol caps page-size at 50.
                'page_size' => 50,

                // 100 entries per chart. Past the first hundred a bestseller
                // list stops being a bestseller list — the tail is ordinary
                // catalogue, and it costs a request per page to collect.
                'pages' => 2,

                /*
                 * Categories pulled per run, and the crawl's depth.
                 *
                 * bol publishes no category-id list, so categories are
                 * discovered from the charts themselves. Bounded per run
                 * because the alternative is an unbounded breadth-first crawl
                 * against a rate-limited API on the first night. Coverage
                 * widens over days instead; what a run defers is logged.
                 */
                'max_categories' => 40,
                'max_depth' => 2,

                /*
                 * NOT the connector's 8.0.
                 *
                 * RateLimiter buckets are per endpoint and share no budget, so
                 * each additional bucket raises what this connector can emit in
                 * one second. `bol:search` and `bol:product` are 8/2 each
                 * already, against a documented 10/s. This runs in a worker
                 * while visitors are searching, and background work has to lose
                 * that race by construction rather than by luck.
                 */
                'rate' => 2.0,
                'burst' => 1,

                // Rank history retention. Long enough for one year-over-year
                // comparison — "was this rising last August too?" is the
                // question a seasonal catalogue needs answered.
                'history_days' => 400,
            ],
        ],

        // Deferred to Phase 8. The connector is written and registered but
        // disabled, so enabling it is a credentials step rather than a refactor.
        'amazon' => [
            'enabled' => (bool) env('AMAZON_ENABLED', false),
            'access_key' => env('AMAZON_ACCESS_KEY'),
            'secret_key' => env('AMAZON_SECRET_KEY'),
            'partner_tags' => env('AMAZON_PARTNER_TAGS', ''),

            /*
             * Storefronts to hide, per market.
             *
             * Empty means every locale is offered everywhere, which is the
             * default and the right one: a shopper can judge a foreign price
             * for themselves better than we can decide it is irrelevant to
             * them.
             *
             * Populate a market's list when a storefront ships badly to it, or
             * when the Associates account is not approved there. The market's
             * primary locale is never hidden — see AmazonLocale::selectableFor.
             */
            'hidden_locales' => [
                'be-nl' => [],
                'be-fr' => [],
                'nl-nl' => [],
                'en' => [],
                'es' => [],
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Credentials at rest
    |--------------------------------------------------------------------------
    */
    'credentials_key' => env('CREDENTIALS_ENCRYPTION_KEY', ''),
];
