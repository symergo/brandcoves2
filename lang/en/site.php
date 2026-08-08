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
        'surprise' => 'Surprise me',
        'scan' => 'Scan',
        'lists' => 'My lists',
        'notifications' => 'Notifications',
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

        /*
         * On-page copy above the results.
         *
         * Every clause is a fact this page can back up — the counts, the range
         * and the brands are all read off the results themselves. Keyword-
         * stuffed filler would rank for a fortnight and then not at all.
         */
        'intro_lead' => 'We found :count products for “:term”, with :shops shop listings between them.',
        'intro_prices' => 'Prices for :term here run from :low to :high.',
        'intro_brands' => 'Brands on this page include :brands.',
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
        'price_as_of' => 'Price and availability as of the time shown and may change.',
        'disclosure' => 'We may earn a commission if you buy through this link. The price you pay is unchanged.',
        'unavailable' => 'This product is not currently available from any shop we track.',
        'seo_compare' => ':title from :price — compare offers from :count shops and find the cheapest.',
        'seo_single' => ':title from :price. Compare offers and check the price history before you buy.',
    ],

    'footer' => [
        'affiliate' => 'Brandcoves compares offers across shops. We may earn a commission on purchases made through our links — it never changes what you pay.',
    ],

    'auth' => [
        'title' => 'Sign in',
        'intro' => 'Enter your email and we will send you a link. No password to remember.',
        'email' => 'Email address',
        'send' => 'Send me a link',
        'link_sent' => 'Check your inbox — if that address has an account, a sign-in link is on its way.',
        'link_invalid' => 'That link has expired or has already been used. Request a new one.',
        'too_many' => 'Too many requests. Try again in :seconds seconds.',
        'or' => 'or',
        'google' => 'Continue with Google',
        'sign_out' => 'Sign out',
        'mail_subject' => 'Your Brandcoves sign-in link',
        'mail_heading' => 'Sign in to Brandcoves',
        'mail_body' => 'Tap the button below to sign in. The link works once and only from this email.',
        'mail_button' => 'Sign in',
        'mail_expiry' => 'The link expires in 15 minutes.',
        'mail_requested_from' => 'Requested from :ip',
        'mail_ignore' => 'If you did not request this, you can safely ignore this email — nobody can sign in without the link.',
        'mail_fallback' => 'If the button does not work, paste this into your browser:',
    ],

    'lists' => [
        'title' => 'My lists',
        'subtitle' => 'Things you are saving, for yourself and for other people.',
        'default_title' => 'Saved items',
        'new_list' => 'New list',
        'list_name' => 'List name',
        'create' => 'Create list',
        'for_someone' => 'This list is for someone else',
        'for_whom' => 'Who is it for?',
        'no_recipient' => 'Just for me',
        'empty' => 'Nothing saved yet.',
        'empty_hint' => 'Search for a product and press Save.',
        'empty_list' => 'This list is empty.',
        'items' => ':count items',
        'one_item' => '1 item',
        'added' => 'Saved to your list.',
        'removed' => 'Removed.',
        'remove' => 'Remove',
        'save' => 'Save',
        'saved' => 'Saved',
        'save_to_list' => 'Save to a list',
        'delete_list' => 'Delete this list',
        'delete_confirm' => 'Delete this list and everything in it?',
        'share' => 'Share',
        'sharing_off' => 'Only you can see this list.',
        'sharing_on' => 'Anyone with the link can see this list.',
        'enable_sharing' => 'Create a share link',
        'disable_sharing' => 'Stop sharing',
        'copy_link' => 'Copy link',
        'copied' => 'Link copied',
        'claim' => 'I\'ll get this',
        'claimed' => 'You are getting this.',
        'claimed_by_someone' => 'Someone is getting this',
        'unclaim' => 'Actually, I am not',
        'unclaimed' => 'No longer yours to get.',
        'already_claimed' => 'Someone else just claimed that one.',
        'cannot_unclaim' => 'You can only undo your own claim, and only within a day.',
        'shared_intro' => 'Tap an item to mark that you are getting it. :name will not see who claimed what.',
        'owner_view_note' => 'This is your own list, so claims are hidden from you — that is the point.',
        'recipient_added' => 'Person added.',
        'recipient_removed' => 'Person removed.',
        'add_person' => 'Add a person',
        'person_name' => 'Their name',
        'relationship' => 'Relationship',
        'occasion' => 'Occasion',
        'price_now' => 'Now :price',
        'price_was' => 'Was :price',
        'sign_in_to_keep' => 'Sign in to keep these lists safe',
        'sign_in_hint' => 'Your lists live in this browser right now. Sign in and they move to your account.',
    ],

    'alerts' => [
        'watch_price' => 'Tell me when it drops',
        'watch_restock' => 'Tell me when it is back',
        'watching_price' => 'Watching the price',
        'watching_restock' => 'Waiting for it to come back',
        'stop' => 'Stop watching',
        'target_label' => 'Tell me below',
        'confirm' => 'Watch it',
        'any_drop_hint' => 'Leave it empty and we will tell you about any drop.',
        // Named, not summarised. If one shop is not watched, saying so is the
        // difference between a promise kept and a promise quietly narrowed.
        'excluded' => 'We cannot watch :shops, so a drop there will not reach you.',
        'created' => 'We will let you know.',
        'removed' => 'No longer watching.',
        'not_available' => 'We cannot watch this one.',
    ],

    'notifications' => [
        'title' => 'Notifications',
        'recent' => 'Recent',
        'empty' => 'Nothing yet. We will tell you when something you watch changes.',
        'dropped_to' => 'Now :price, down from :was',
        'back_in_stock' => 'Back in stock',
        'watching' => 'What you are watching',
        'watching_empty' => 'You are not watching anything yet.',
        'await_restock' => 'Waiting for stock',
        'until' => 'Under :price',
        'any_drop' => 'Any drop',
        'now' => 'now :price',
    ],

    'gift' => [
        'title' => 'Gift Finder',
        'subtitle' => 'Tell us about them. We will find four things worth giving.',
        'seo_description' => 'Describe the person you are buying for and get four gift ideas, each with the reason it was chosen, compared across shops.',

        'step_who' => 'Who is it for?',
        'step_interests' => 'What are they into?',
        'step_vibe' => 'How should it feel?',
        'step_budget' => 'What are you spending?',
        'step_avoid' => 'Anything to avoid?',
        'step_values' => 'Anything that matters?',

        'interests' => [
            'cooking' => 'Cooking', 'coffee' => 'Coffee', 'photography' => 'Photography',
            'music' => 'Music', 'gaming' => 'Gaming', 'reading' => 'Reading',
            'fitness' => 'Fitness', 'outdoors' => 'The outdoors', 'travel' => 'Travel',
            'gardening' => 'Gardening', 'diy' => 'DIY', 'beauty' => 'Beauty',
            'fashion' => 'Fashion', 'tech' => 'Tech', 'home' => 'Their home',
            'craft' => 'Making things', 'film' => 'Film and TV', 'pets' => 'Their pet',
            'wellness' => 'Winding down', 'kids' => 'Kids',
        ],

        'vibes' => [
            'practical' => 'Useful',
            'playful' => 'Fun',
            'beautiful' => 'Beautiful',
        ],

        'values' => [
            'sustainable' => 'Sustainable',
            'local' => 'Made nearby',
            'handmade' => 'Handmade',
        ],

        'find' => 'Find gifts',
        'again' => 'Try again',
        'swap' => 'Something else',
        'start_over' => 'Start over',
        'results_title' => 'Four ideas',
        'no_results' => 'Nothing fit that brief. Try a wider budget or another interest.',
        'budget_any' => 'No limit',
        'budget_up_to' => 'Up to',
        'avoid_placeholder' => 'e.g. alcohol, wool',
        'avoid_hint' => 'We will not show anything matching these words.',
        'avoid_add' => 'Add',
        'recipient_use' => 'Use what we know about :name',
        'recipient_none' => 'Someone new',
        'step' => 'Step :current of :total',
        'back' => 'Back',
        'next' => 'Next',
        'skip' => 'Skip',

        // The card shows one reason, not a breakdown: three reasons read as a
        // machine justifying itself.
        'reasons' => [
            'interest_fit' => 'Matches :match',
            'budget_fit' => 'Right for your budget',
            'surprise' => 'Not the obvious choice',
            'vibe' => 'Fits the feeling you wanted',
            'values' => 'Matches what matters to you',
        ],
    ],

    'scan' => [
        'title' => 'Scan a barcode',
        'subtitle' => 'Standing in a shop? Scan it and see what it costs everywhere else.',
        'seo_description' => 'Scan a product barcode and compare the price across every shop that stocks it.',
        'start' => 'Open the camera',
        'stop' => 'Stop',
        'manual_placeholder' => 'Or type the barcode',
        'look_up' => 'Look up',
        'shops' => 'at :count shops',
        'preparing' => 'Getting ready…',
        'unsupported' => 'The scanner could not start. Type the number below instead — it is on the label under the bars.',
        'no_camera' => 'No camera available, or permission was declined. Type the number below instead.',
        'invalid' => 'That is not a valid barcode. Check the digits under the bars.',
        'not_found' => 'We do not have that one yet.',
        'search_instead' => 'Search for it anyway',
    ],

    'surprise' => [
        'title' => 'Things you did not know existed',
        'subtitle' => 'Rare, in stock, and sold by almost nobody.',
        'seo_description' => 'Unusual products you will not find on a bestseller list — scored for how rare they are and checked for whether they are worth seeing.',
        'reroll' => 'Show me more',
        'empty' => 'Nothing scored yet. Come back after the next catalogue run.',

        'why' => [
            'lexical' => 'Barely anything else is described like this',
            'category' => 'A corner of the catalogue nobody browses',
            'brand' => 'A brand you probably have not heard of',
            'exclusivity' => 'Almost no shop stocks it',
            'novelty' => 'New to us this month',
        ],
    ],

    'daily' => [
        'title' => 'The Daily Cove',
        'seo_description' => 'One puzzle, a handful of things you did not know existed, and a buying guide built from what people actually searched for.',
        'hunt_title' => 'Guess the price',
        'hunt_prompt' => 'What does this cost?',
        'guess' => 'Guess',
        'tries_left' => ':count tries left',
        'your_guesses' => 'Your guesses',
        'solved' => 'Got it',
        'missed' => 'Out of tries',
        'community' => ':percent% of :players players got it today',
        'share' => 'Share your result',
        'copied' => 'Copied',
        'see_offers' => 'See the offers',
        'streak' => ':days day streak',
        'finds_title' => "Today's finds",
        'guide_title' => "Today's guide",
        'guide_why' => 'Written because :count searches here asked for it.',
        'archive' => 'Earlier editions',

        // The no-AI theme rotation, indexed by day of year modulo 7. Dated
        // rather than random so a rebuild of the same day is identical.
        'themes' => [
            'Things nobody else is selling',
            'Quietly excellent',
            'Solves a problem you have',
            'Odd but useful',
            'Worth the shelf space',
            'Found in the back of the catalogue',
            'You did not know you needed this',
        ],
    ],

    'guides' => [
        'title' => 'Buying guides',
        'subtitle' => 'Written from what people search for here — not from a keyword tool.',
        'seo_description' => 'Buying guides built from real search demand, with live prices compared across every shop that stocks each product.',
        'empty' => 'No guides yet. They are written as topics build up enough demand.',
        'how_to_choose' => 'How to choose',
        'faq' => 'Questions',
        'updated' => 'Checked :date',
        'why' => 'written because :count searches here asked for it',
        'shops' => ':count shops',
        'unavailable' => 'Out of stock',
        'slug_prefix' => 'best',
        'template_title' => 'The best :topic',
        'template_intro' => ':count options for :topic, with every shop’s price compared side by side.',
    ],

    'discover' => [
        'dial_label' => 'How much do you already know?',
        'dial_low' => 'I know exactly what I want',
        'dial_high' => 'Surprise me',
        'surprise_label' => 'Surprise',
        'query_placeholder' => 'A product, a brand, or nothing at all',
        'go' => 'Go',
        'thinking' => 'Rearranging…',
        'considered' => 'Showing :shown of :considered candidates.',
        'empty' => 'Nothing to show here yet.',
        'shops' => ':count shops',
        'not_for_me' => 'Not for me',
        'goal_placeholder' => 'What are you setting up? e.g. home office, coffee corner',
        'kit_total' => ':count parts · :total in total',
        'now_showing' => 'Now: :mode',

        // Required of every mode: the dominant scoring factor, in words. A
        // surface that reorganises as a dial moves is incomprehensible without
        // it — the same product has to be able to say it is here for a
        // different reason than it was a moment ago.
        'why' => [
            'relevance' => 'Closest to what you asked for',
            'unexpectedness' => 'You are unlikely to have seen this',
            'novelty' => 'New here',
            'quality' => 'Well stocked and easy to compare',
        ],

        'modes' => [
            'search' => [
                'title' => 'Search',
                'description' => 'You know what you want. Every shop’s price, one card per product.',
            ],
            'guides' => [
                'title' => 'Guides',
                'description' => 'Someone already did the thinking — shortlists built from what people search for here.',
            ],
            'compare' => [
                'title' => 'Compare',
                'description' => 'The whole category, cheapest to dearest, with the lookalikes marked.',
            ],
            'deals' => [
                'title' => 'Deals',
                'description' => 'Real savings, measured against our own price history and against the other shops — never against a merchant’s “was” price.',
            ],
            'projects' => [
                'title' => 'Projects',
                'description' => 'Tell us the situation and a budget. We will put the parts together and add them up.',
            ],
            'trends' => [
                'title' => 'New and rising',
                'description' => 'Just arrived, or picked up by more shops this fortnight.',
            ],
            'follow' => [
                'title' => 'The house taste',
                'description' => 'A slow stream of everything we have chosen lately.',
            ],
            'serendipity' => [
                'title' => 'Surprise me',
                'description' => 'Things you did not know existed, ranked for exactly that.',
            ],
        ],
    ],
];
