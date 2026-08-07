<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Discovery modes
|--------------------------------------------------------------------------
|
| Nine modes on one axis: how much intent the user has, from pinpoint ("I know
| the exact item") to none ("surprise me"). `position` is where a mode sits on
| that axis, 0..1, and it is what lets the switcher interpolate between two
| adjacent modes instead of hard-swapping between nine screens.
|
| A mode is CONFIG. Adding one is a row here (or in `mode_profiles`, which
| overrides this without a redeploy) plus a layout in the frontend. If a new
| mode needs a change to ModeEngine, either this schema is missing a field or
| the thing is not really a mode.
|
| Two dimensions are deliberately NOT modes:
|
|   - modality (text / voice / image) — changes how the query vector is
|     produced, never which mode you are in. Voice is speech-to-text feeding
|     the same `query`; an image feeds the image retriever.
|   - social (solo / collaborative) — adds a collaborative retriever and blends
|     its signal into ranking. Applies across Inspiration, Serendipity, Follow.
|
| ENABLED FLAGS: modes whose retrievers do not exist yet are declared and
| disabled, so the axis is visible in one place and turning one on is a flag
| flip plus a retriever class. Phase 1 ships the two endpoints of the axis —
| Search and Serendipity — which is what proves the shared pipeline and the
| dial. See docs/features/discovery-modes.md.
|
| Scoring: score = relevance^α · unexpectedness^β · novelty^γ · quality,
| then MMR at λ, then ε-greedy exploration. Multiplicative, so an exponent of
| zero neutralises its term and Search needs no special-casing.
*/

return [

    // How far the dial can be dragged past a stop before it snaps to the next.
    'dial_step' => 0.05,

    'modes' => [

        /*
         * "I know what I want."
         *
         * The pinpoint end of the axis. β = γ = 0: someone searching for a
         * specific product does not want to be surprised, and λ is low because
         * near-duplicates are genuinely useful when you are comparing variants.
         */
        'search' => [
            'intent' => 'pinpoint',
            'position' => 0.0,
            'required_input' => ['query'],
            'retrievers' => ['keyword' => 0.8, 'semantic' => 0.2],
            'scoring' => ['alpha' => 0.9, 'beta' => 0.0, 'gamma' => 0.0, 'lambda' => 0.2, 'epsilon' => 0.0],
            'layout' => 'list',
            'enabled' => true,
        ],

        /*
         * "I know the need, not the product."
         *
         * The Gift Whisperer generalised. Already shipped as its own surface;
         * folding it in here is a Phase 2 job, and until then the wizard's
         * dedicated flow is the better experience for a six-step brief.
         */
        'advisor' => [
            'intent' => 'need',
            'position' => 0.2,
            'required_input' => ['answers'],
            'retrievers' => ['twoTower' => 0.5, 'semantic' => 0.3, 'keyword' => 0.2],
            'scoring' => ['alpha' => 0.8, 'beta' => 0.2, 'gamma' => 0.1, 'lambda' => 0.4, 'epsilon' => 0.05],
            'layout' => 'cards',
            'enabled' => false,
        ],

        /* "Help me decide between options." */
        'compare' => [
            'intent' => 'decide',
            'position' => 0.3,
            'required_input' => ['items'],
            'retrievers' => ['spectrum' => 1.0],
            'scoring' => ['alpha' => 0.7, 'beta' => 0.1, 'gamma' => 0.0, 'lambda' => 0.6, 'epsilon' => 0.0],
            'layout' => 'compare',
            'enabled' => false,
        ],

        /* "Best price or timing, not a specific item." */
        'deals' => [
            'intent' => 'value',
            'position' => 0.4,
            'required_input' => [],
            'retrievers' => ['value' => 0.8, 'fresh' => 0.2],
            'scoring' => ['alpha' => 0.5, 'beta' => 0.0, 'gamma' => 0.3, 'lambda' => 0.4, 'epsilon' => 0.05],
            'layout' => 'deals',
            'enabled' => false,
        ],

        /* "Solve a situation." Decompose a goal into slots and fill each. */
        'projects' => [
            'intent' => 'situation',
            'position' => 0.45,
            'required_input' => ['goal'],
            'retrievers' => ['slots' => 1.0],
            'scoring' => ['alpha' => 0.7, 'beta' => 0.1, 'gamma' => 0.0, 'lambda' => 0.5, 'epsilon' => 0.0],
            'layout' => 'kit',
            'enabled' => false,
        ],

        /*
         * "I know the vibe, not the item."
         *
         * The mode most obviously blocked on embeddings: a mood is a vector or
         * it is nothing, and matching moods on keywords produces exactly the
         * junk this whole system exists to avoid.
         */
        'inspiration' => [
            'intent' => 'vibe',
            'position' => 0.6,
            'required_input' => [],
            'retrievers' => ['semantic' => 0.6, 'image' => 0.2, 'curated' => 0.2],
            'scoring' => ['alpha' => 0.5, 'beta' => 0.3, 'gamma' => 0.2, 'lambda' => 0.7, 'epsilon' => 0.1],
            'layout' => 'grid',
            'enabled' => false,
        ],

        /* "What's current." */
        'trends' => [
            'intent' => 'current',
            'position' => 0.7,
            'required_input' => [],
            'retrievers' => ['fresh' => 1.0],
            'scoring' => ['alpha' => 0.3, 'beta' => 0.0, 'gamma' => 0.7, 'lambda' => 0.5, 'epsilon' => 0.05],
            'layout' => 'feed',
            'enabled' => false,
        ],

        /*
         * "Editorial — someone already did the thinking."
         *
         * Buying guides and ghost-shop personas are the same thing to the
         * pipeline: a curated pool. This is why guides are a mode rather than a
         * separate subsystem — a guide's shortlist *is* a pool, and a persona's
         * would be another one feeding the same retriever.
         */
        'guides' => [
            'intent' => 'editorial',
            'position' => 0.8,
            'required_input' => [],
            'retrievers' => ['curated' => 0.8, 'keyword' => 0.2],
            'scoring' => ['alpha' => 0.5, 'beta' => 0.2, 'gamma' => 0.1, 'lambda' => 0.6, 'epsilon' => 0.05],
            'layout' => 'editorial',
            'enabled' => true,
        ],

        /*
         * "Surprise me with things I didn't know existed."
         *
         * The far end of the axis. α drops to 0.4 and β rises to 0.8, so
         * unexpectedness carries the ranking; λ is high because four of the
         * same thing is a failed surprise; ε is high because a purely greedy
         * ranker never learns — it never shows anything it is unsure about, so
         * nothing outside the top slice ever collects a reaction.
         */
        'serendipity' => [
            'intent' => 'none',
            'position' => 1.0,
            'required_input' => [],
            'retrievers' => ['curated' => 0.4, 'outlier' => 0.3, 'twoTower' => 0.3],
            'scoring' => ['alpha' => 0.4, 'beta' => 0.8, 'gamma' => 0.6, 'lambda' => 0.8, 'epsilon' => 0.15],
            'layout' => 'grid',
            'enabled' => true,
        ],

        /*
         * "Entertain me / let me follow a taste."
         *
         * Needs a curator or persona to follow before it means anything, which
         * is a Phase 3 concern alongside the social overlay.
         */
        'follow' => [
            'intent' => 'ambient',
            'position' => 1.0,
            'required_input' => [],
            'retrievers' => ['curated' => 1.0],
            'scoring' => ['alpha' => 0.4, 'beta' => 0.4, 'gamma' => 0.2, 'lambda' => 0.8, 'epsilon' => 0.1],
            'layout' => 'stream',
            'enabled' => false,
        ],
    ],
];
