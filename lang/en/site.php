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

    'search' => [
        'title' => 'Search',
        'placeholder' => 'Search for a product, brand or barcode',
        'submit' => 'Search',
        'results_for' => 'Results for ":term"',
        'count' => ':count products',
        'browse' => 'Browse the catalogue',
        'empty' => 'Nothing matched ":term".',
        'empty_hint' => 'Try a shorter search, or check the spelling.',
        'empty_filters' => 'No products match these filters.',
        'clear_filters' => 'Clear all filters',
        'sort' => 'Sort',
        'sort_relevance' => 'Most relevant',
        'sort_price_asc' => 'Cheapest first',
        'sort_price_desc' => 'Most expensive first',
        'sort_discount' => 'Biggest discount',
        'sort_newest' => 'Newest',
        'view_grid' => 'Grid',
        'view_store' => 'By shop',
        'filters' => 'Filters',
        'price' => 'Price',
        'price_min' => 'From',
        'price_max' => 'To',
        'brand' => 'Brand',
        'shop' => 'Shop',
        'in_stock_only' => 'In stock only',
        'discounted_only' => 'Discounted only',
        'comparable_only' => 'Available from several shops',
        'previous' => 'Previous',
        'next' => 'Next',
        'page_of' => 'Page :current of :last',
        'seo_term' => 'Compare :count products matching :term across bol, Amazon and hundreds of shops. Find the cheapest offer in seconds.',
        'seo_default' => 'Search products across bol, Amazon and hundreds of shops at once, and compare every offer side by side.',
    ],

    'product' => [
        'from' => 'from',
        'one_offer' => '1 offer',
        'offers' => ':count offers',
        'across_shops' => 'across :count shops',
        'one_shop' => 'at 1 shop',
        'off' => ':percent% off',
        'out_of_stock' => 'Out of stock',
        'in_stock' => 'In stock',
        'compare' => 'Compare :count offers',
        'all_offers' => 'All offers',
        'go_to_shop' => 'Go to shop',
        'cheapest' => 'Cheapest',
        'price_history' => 'Price over the last 90 days',
        'no_history' => 'Not enough price history yet.',
        'typical_price' => 'Typical price :price',
        'barcode' => 'Barcode',
        'disclosure' => 'We may earn a commission if you buy through this link. The price you pay is unchanged.',
        'unavailable' => 'This product is not currently available from any shop we track.',
        'seo_compare' => ':title from :price — compare offers from :count shops and find the cheapest.',
        'seo_single' => ':title from :price. Compare offers and check the price history before you buy.',
    ],

    'footer' => [
        'affiliate' => 'Brandcoves compares offers across shops. We may earn a commission on purchases made through our links — it never changes what you pay.',
    ],
];
