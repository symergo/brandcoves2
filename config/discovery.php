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
| ENABLED FLAGS: every mode is declared here whether or not it runs, so the
| whole axis is visible in one place. A disabled mode is one that cannot yet do
| its job honestly — not one that would crash. Both of the two currently off
| would happily return a page; that is exactly why they are off:
|
|   - `inspiration` would renormalise onto `curated` and answer a mood with
|     last week's guide.
|   - `advisor` would be a thinner version of the Gift Whisperer, which already
|     answers that question better.
|
| A plausible wrong answer costs more than a missing one. See
| docs/features/discovery-modes.md.
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
         * DISABLED, and not because a retriever is missing.
         *
         * The Gift Whisperer is already this mode, and it is better at it: a
         * six-step brief with skippable questions, a reason per card and a
         * per-card swap. Exposing a thinner version here would put two
         * different answers to the same question on one site, and the worse one
         * would be the one with the dial on it.
         *
         * Turning this on means folding the wizard's brief into
         * DiscoveryRequest::$answers and giving it a retriever that reads them
         * — a real piece of work, not a flag flip. See gift-whisperer.md.
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
            // The ranker picks which rungs; the ladder decides their order.
            // Presented by score, a spectrum is just a list again.
            'order' => 'price_asc',
            'enabled' => true,
        ],

        /* "Best price or timing, not a specific item." */
        'deals' => [
            'intent' => 'value',
            'position' => 0.4,
            'required_input' => [],
            'retrievers' => ['value' => 0.8, 'fresh' => 0.2],
            'scoring' => ['alpha' => 0.5, 'beta' => 0.0, 'gamma' => 0.3, 'lambda' => 0.4, 'epsilon' => 0.05],
            'layout' => 'deals',
            'enabled' => true,
        ],

        /* "Solve a situation." Decompose a goal into slots and fill each. */
        'projects' => [
            'intent' => 'situation',
            'position' => 0.45,
            'required_input' => ['goal'],
            'retrievers' => ['slots' => 1.0],
            'scoring' => ['alpha' => 0.7, 'beta' => 0.1, 'gamma' => 0.0, 'lambda' => 0.5, 'epsilon' => 0.0],
            'layout' => 'kit',
            'enabled' => true,
        ],

        /*
         * "I know the vibe, not the item."
         *
         * DISABLED. The mode most obviously blocked on embeddings: a mood is a
         * vector or it is nothing, and matching moods on keywords produces
         * exactly the junk this whole system exists to avoid.
         *
         * It *would* run — 80% of its weight is unavailable, so it would
         * renormalise onto `curated` alone and return the editorial pool. That
         * is a working page and a dishonest one: it would answer "show me
         * something calm and woody" with whatever was in last week's guide.
         * Better absent than plausible.
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

        /*
         * "What's current."
         *
         * `popular` leads because it is the only retriever measuring actual
         * demand — a retailer's bestseller chart, and specifically movement
         * within it. `fresh` keeps a real share rather than being replaced: the
         * chart is one retailer's view, and the whole Awin catalogue is
         * invisible to it. Two partial answers beat one.
         */
        'trends' => [
            'intent' => 'current',
            'position' => 0.7,
            'required_input' => [],
            'retrievers' => ['popular' => 0.6, 'fresh' => 0.4],
            'scoring' => ['alpha' => 0.3, 'beta' => 0.0, 'gamma' => 0.7, 'lambda' => 0.5, 'epsilon' => 0.05],
            'layout' => 'feed',
            'enabled' => true,
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
         * Runs on the editorial pool today, which is a real lean-back stream —
         * everything in it was chosen by a job or a person. Following a
         * *specific* curator or persona needs those to exist first, and that is
         * a Phase 3 concern alongside the social overlay. Until then this is
         * "the house taste", which is an honest thing to offer.
         */
        'follow' => [
            'intent' => 'ambient',
            'position' => 1.0,
            'required_input' => [],
            'retrievers' => ['curated' => 1.0],
            'scoring' => ['alpha' => 0.4, 'beta' => 0.4, 'gamma' => 0.2, 'lambda' => 0.8, 'epsilon' => 0.1],
            'layout' => 'stream',
            'enabled' => true,
        ],
    ],
];
