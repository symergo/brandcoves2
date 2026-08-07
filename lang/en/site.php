<?php

declare(strict_types=1);

/**
 * Site copy, per language.
 *
 * Keyed by LANGUAGE (nl, fr, en, es), not by market — be-nl and nl-nl are two
 * markets sharing one language, so they share this file. What differs between
 * them is the catalogue, currency formatting and merchants, not the words.
 */
return [
    'nav' => [
        'search' => 'Search',
        'gift' => 'Gift Finder',
        'daily' => 'Daily Picks',
        'guides' => 'Guides',
        'lists' => 'My lists',
        'sign_in' => 'Sign in',
        'main' => 'Main',
        'skip' => 'Skip to content',
        'choose_market' => 'Choose your market',
    ],

    'home' => [
        'title' => 'Find it, compare it, gift it',
        'headline_1' => "You don't know what you want.",
        'headline_2' => "You know who it's for.",
        'intro' => 'Search bol, Amazon and hundreds of shops at once, compare every offer for the same product, and let the Gift Whisperer turn a description of a person into something worth wrapping.',
        'cta_gift' => 'Find a gift',
        'cta_search' => 'Search products',
        'stats_label' => 'Catalogue status',
        'stat_products' => 'Products indexed',
        'stat_comparable' => 'With more than one offer',
        'stat_comparable_hint' => 'Comparable across shops',
        'stat_guides' => 'Buying guides published',
        'empty_catalogue' => 'The catalogue is empty. Run a feed ingestion to populate it:',
    ],

    'footer' => [
        'affiliate' => 'Brandcoves compares offers across shops. We may earn a commission on purchases made through our links — it never changes what you pay.',
    ],
];
