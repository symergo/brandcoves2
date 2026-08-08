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
        'today_badge' => "Today's Cove",
        'today_puzzle' => 'with a price puzzle',
        'today_cta' => "See today's finds",
        'coves_heading' => 'Coves',
        'coves_intro' => 'Long reads around a theme, with every brand and product linked straight into a live search.',
        'coves_all' => 'All Coves',
        'coves_volume' => ':count searches a month',
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
        'intro_brands' => 'Brands on this page:',
        'intro_discounts' => ':count of the products on this page are below their 30-day median price, the largest saving being :percent%.',
        'intro_comparable' => ':count of these :term are sold by more than one shop, so there is a cheapest offer to find rather than a single price to accept.',
        'intro_terms' => 'Words that come up across these :term listings: :terms.',
        'seo_default' => 'Search products across bol, Amazon and hundreds of shops at once, and compare every offer side by side.',
    ],

    /*
     * Brand pages.
     *
     * Every one of these is emitted only when the number behind it exists — see
     * App\Services\Seo\BrandCopy. Rewriting one of them into a claim the
     * catalogue cannot back up is a bug, not a copy tweak.
     *
     * The four `lead_*` variants exist because thousands of pages opening with
     * one identical sentence is a pattern a crawler sees in a single sample.
     * BrandCopy::LEAD_VARIANTS must match how many there are.
     */
    'brand' => [
        'title' => ':brand',
        'heading' => ':brand',
        'seo_description' => 'Compare :count :brand products across every shop we track, and find the cheapest offer.',
        'crumb' => 'Brands',
        'index_title' => 'Brands',
        'index_intro' => 'Every brand in the catalogue, with live prices compared across the shops that stock it.',
        'index_count' => ':count products',
        'all_brands' => 'All brands',
        'products_heading' => ':brand products',
        'coves_heading' => 'Coves that mention :brand',
        'related_heading' => 'Other brands people compare',
        'empty' => 'Nothing from :brand is in stock right now.',
        'empty_hint' => 'Prices and stock are re-checked twice a day, so this page changes.',

        'lead_1' => 'Looking for :brand? We are tracking :count :brand products and comparing what every shop charges for them.',
        'lead_2' => 'There are :count :brand products in the catalogue right now, with every offer for each one priced side by side.',
        'lead_3' => 'This is every :brand product we can find a live price for — :count of them, across the shops that actually stock the brand.',
        'lead_4' => ':count :brand products, one page, every shop\'s price on each of them.',

        'shops_named' => ':shop stocks more :brand than anyone else we track, and :count shops in total carry the brand — which is what makes the prices below worth comparing.',
        'shops_count' => ':count shop carries :brand at the moment.',

        'price_from' => ':brand starts at :low here.',
        'price_range' => ':brand prices run from :low to :high on this page.',
        'price_range_category' => ':brand prices run from :low to :high, and most of what we carry is :category.',

        'discount_named' => ':shop currently has discounts on :brand — :count products are below their usual price, the largest by :percent%. Measured against our own 30-day median, not a shop\'s crossed-out figure.',
        'discount_count' => ':count :brand products are below their 30-day median price right now.',

        'comparison' => 'Because the same :brand product is often sold by several shops at different prices, the cheapest offer is the thing worth finding — and it is the first one shown on every card below.',
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
        'explore' => 'Explore',
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
        'close' => 'Close',
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
        // Days worth building an edition around. A blurb is optional; a missing
        // one renders as no blurb rather than as a dotted key.
        'observances' => [
            // January.
            'new_year' => ['title' => 'Start of something', 'blurb' => 'The first day of the year, and the objects people buy when they mean it.'],
            'sleep' => ['title' => 'Festival of Sleep Day', 'blurb' => 'A real day, invented for exactly this time of January.'],
            'three_kings' => ['title' => 'Día de Reyes', 'blurb' => 'The night the presents actually arrive.'],
            'houseplants' => ['title' => 'Houseplant Appreciation Day', 'blurb' => 'The tree is out; something has to fill the corner.'],
            'pet_style' => ['title' => 'Dress Up Your Pet Day', 'blurb' => 'They will forgive you. Probably.'],
            'hats' => ['title' => 'National Hat Day', 'blurb' => 'Mid-January, which is the only argument this day needs.'],
            'hot_tea' => ['title' => 'Hot Tea Day', 'blurb' => 'Everything between the kettle and the cup.'],
            'popcorn' => ['title' => 'Popcorn Day', 'blurb' => 'And the rest of the apparatus of staying in.'],
            'cheese' => ['title' => 'Cheese Lovers Day', 'blurb' => 'The boards, the knives, the slightly excessive fondue set.'],
            'hugs' => ['title' => 'Soft things day', 'blurb' => 'It is International Hug Day, so today is about things you want to touch.'],
            'pie' => ['title' => 'National Pie Day', 'blurb' => 'Tins, dishes and the one gadget that actually helps.'],
            'burns_night' => ['title' => 'Burns Night', 'blurb' => 'Glassware, mostly.'],
            'lego_day' => ['title' => 'International LEGO Day', 'blurb' => 'The patent was filed today in 1958. Everything since is decoration.'],
            'puzzles' => ['title' => 'National Puzzle Day', 'blurb' => 'The right week of the year for a thousand pieces.'],
            'blue_monday' => ['title' => 'Blue Monday', 'blurb' => 'Supposedly the bleakest day of the year. The science is nonsense; the lamps still work.'],

            // February.
            'pizza' => ['title' => 'Pizza, seriously', 'blurb' => 'World Pizza Day. The kit is more interesting than you would think.'],
            'science' => ['title' => 'Women and Girls in Science Day', 'blurb' => 'Things that let you look closer.'],
            'radio' => ['title' => 'World Radio Day', 'blurb' => 'Everything that plays sound at you without being asked.'],
            'valentines' => ['title' => "Valentine's Day", 'blurb' => 'Held to a higher standard than a garage bouquet.'],
            'kindness' => ['title' => 'Random Acts of Kindness Day', 'blurb' => 'Small things, sent to someone who is not expecting them.'],
            'wine' => ['title' => 'Drink Wine Day', 'blurb' => 'The glassware and the gadgets, not the bottle.'],
            'love_your_pet' => ['title' => 'Love Your Pet Day', 'blurb' => 'They will accept the tribute.'],
            'cocktails' => ['title' => 'National Margarita Day', 'blurb' => 'The equipment holds up all year.'],
            'pokemon' => ['title' => 'Pokémon Day', 'blurb' => 'Thirty years and still going.'],

            // March.
            'wildlife' => ['title' => 'For watching wildlife', 'blurb' => 'World Wildlife Day — things for looking closely at animals that have not agreed to it.'],
            'womens_day' => ['title' => "International Women's Day", 'blurb' => 'Tools, not trinkets.'],
            'mario_day' => ['title' => 'MAR10 Day', 'blurb' => 'Written down, the date says his name.'],
            'pi_day' => ['title' => 'Pi Day', 'blurb' => '3.14, so: round things you bake in.'],
            'happiness' => ['title' => 'Cheerful objects', 'blurb' => 'International Day of Happiness, taken literally.'],
            'poetry' => ['title' => 'World Poetry Day', 'blurb' => 'Pens, paper and somewhere to put the results.'],
            'water' => ['title' => 'World Water Day', 'blurb' => 'Bottles, filters and one large barrel.'],
            'tolkien' => ['title' => 'Tolkien Reading Day', 'blurb' => 'The day the Ring went into the fire.'],
            'pencils' => ['title' => 'National Pencil Day', 'blurb' => 'The patent is from today in 1858.'],
            'backup' => ['title' => 'World Backup Day', 'blurb' => 'The day before April Fools, which is not an accident.'],

            // April.
            'april_fools' => ['title' => "April Fools' Day", 'blurb' => 'Genuinely useful things that look like a joke.'],
            'childrens_books' => ['title' => "International Children's Book Day", 'blurb' => "Andersen's birthday, and the lamp to read them by."],
            'health' => ['title' => 'Quietly good for you', 'blurb' => 'World Health Day, without the lecture.'],
            'pets' => ['title' => 'For the animal that runs your house', 'blurb' => 'National Pet Day. They did not ask, but here we are.'],
            'space' => ['title' => 'Yuri’s Night', 'blurb' => 'The first human in orbit, sixty-odd years ago today.'],
            'earth' => ['title' => 'Things that last', 'blurb' => 'Earth Day — objects built to be owned twice.'],
            'books' => ['title' => 'For readers', 'blurb' => 'World Book Day, and everything around the reading rather than the books.'],
            'kingsday' => ['title' => 'Koningsdag', 'blurb' => 'Orange optional. A cool box is not.'],
            'record_store_day' => ['title' => 'Record Store Day', 'blurb' => 'What to play it on, since you are buying it anyway.'],
            'dance' => ['title' => 'International Dance Day', 'blurb' => 'Things that make a room loud.'],
            'jazz' => ['title' => 'International Jazz Day', 'blurb' => 'Analogue, mostly.'],

            // May.
            'makers' => ['title' => 'Things that make things', 'blurb' => 'Labour Day, read as an excuse to buy a better drill.'],
            'star_wars' => ['title' => 'May the Fourth', 'blurb' => 'The pun is the entire justification and it has held for decades.'],
            'eat_what_you_want' => ['title' => 'Eat What You Want Day', 'blurb' => 'The appliances behind the decision.'],
            'family' => ['title' => 'International Day of Families', 'blurb' => 'Things that require more than one person.'],
            'bees' => ['title' => 'World Bee Day', 'blurb' => 'Small architecture for very small tenants.'],
            'tea' => ['title' => 'International Tea Day', 'blurb' => 'The ceremony, at whatever scale you like.'],
            'geek_pride' => ['title' => 'Geek Pride Day', 'blurb' => 'Also Towel Day, and the anniversary of the first Star Wars release. A busy 25 May.'],
            'mountains' => ['title' => 'Everest Day', 'blurb' => 'Summited today in 1953. This is the gentler end of the same idea.'],
            'mothers_day' => ['title' => 'For your mother', 'blurb' => 'Not a mug.'],

            // June.
            'bicycle' => ['title' => 'Two wheels', 'blurb' => 'World Bicycle Day. Some of this is genuinely clever.'],
            'environment' => ['title' => 'Less rubbish', 'blurb' => 'World Environment Day — things that replace something disposable.'],
            'oceans' => ['title' => 'World Oceans Day', 'blurb' => 'For getting into it, or at least near it.'],
            'sushi' => ['title' => 'International Sushi Day', 'blurb' => 'It is mostly about the rice and the knife.'],
            'music' => ['title' => 'Make some noise', 'blurb' => 'World Music Day, instruments included.'],
            'skateboarding' => ['title' => 'Go Skateboarding Day', 'blurb' => 'Including the parts that stop you breaking.'],
            'fathers_day' => ['title' => 'For your father', 'blurb' => 'Not socks.'],

            // July.
            'chocolate' => ['title' => 'World Chocolate Day', 'blurb' => 'The moulds, the thermometers, the fountain nobody needs.'],
            'emoji' => ['title' => 'World Emoji Day', 'blurb' => 'The date on the calendar emoji is 17 July. That is the whole reason.'],
            'moon' => ['title' => 'Moon Day', 'blurb' => 'Apollo 11 landed today in 1969.'],
            'belgian_national' => ['title' => 'Belgian National Day', 'blurb' => 'Chips and a barbecue, essentially.'],
            'friendship' => ['title' => 'International Day of Friendship', 'blurb' => 'Things that are better sent than kept.'],
            'wizarding' => ['title' => 'A wizarding birthday', 'blurb' => '31 July, if you know you know.'],

            // August.
            'cats' => ['title' => 'Cat day', 'blurb' => 'International Cat Day. They are aware.'],
            'book_lovers' => ['title' => 'Book Lovers Day', 'blurb' => 'Everything except the books.'],
            'lefthanders' => ['title' => 'International Lefthanders Day', 'blurb' => 'Objects designed by people who were paying attention.'],
            'photography' => ['title' => 'For looking properly', 'blurb' => 'World Photography Day — the accessories nobody tells you about.'],
            'dogs' => ['title' => 'International Dog Day', 'blurb' => 'They are already at the door.'],
            'back_to_school' => ['title' => 'The last day of the holidays', 'blurb' => 'Everything you meant to buy in July.'],

            // September.
            'coffee' => ['title' => 'The coffee rabbit hole'],
            'literacy' => ['title' => 'International Literacy Day', 'blurb' => 'Things that make reading easier.'],
            'programmers' => ['title' => "Programmers' Day", 'blurb' => 'The 256th day of the year, which is the joke.'],
            'pirates' => ['title' => 'Talk Like a Pirate Day', 'blurb' => 'Yes, it is a real day. No, we do not know why either.'],
            'peace_quiet' => ['title' => 'Peace and quiet', 'blurb' => 'International Day of Peace, interpreted as loudly as possible: things that make it stop.'],
            'travel' => ['title' => 'World Tourism Day', 'blurb' => 'The kit that survives the airport.'],

            // October.
            'coffee_intl' => ['title' => 'International Coffee Day', 'blurb' => 'The gear, from the sensible to the excessive.'],
            'animals' => ['title' => 'World Animal Day', 'blurb' => 'For the ones who live with you.'],
            'teachers' => ['title' => "World Teachers' Day", 'blurb' => 'Things that survive a school bag.'],
            'food' => ['title' => 'World Food Day', 'blurb' => 'The tools, not the ingredients.'],
            'chefs' => ['title' => 'International Chefs Day', 'blurb' => 'One knife, properly chosen.'],
            'internet' => ['title' => 'World Internet Day', 'blurb' => 'The unglamorous boxes that make the rest work.'],
            'halloween' => ['title' => 'Halloween', 'blurb' => 'Tonight, so this is your last chance.'],

            // November.
            'singles_day' => ['title' => "Singles' Day", 'blurb' => 'The largest shopping day on earth, and almost nobody here has heard of it.'],
            'world_kindness' => ['title' => 'World Kindness Day', 'blurb' => 'For posting rather than keeping.'],
            'mens_health' => ['title' => 'Grooming, unfussily'],
            'television' => ['title' => 'World Television Day', 'blurb' => 'Mostly the things attached to it.'],
            'digital_tidy' => ['title' => 'Computer Security Day', 'blurb' => 'The physical half of it: drives, sticks, shredders.'],
            'black_friday' => ['title' => 'Actually cheaper', 'blurb' => 'Measured against our own price history, not a sticker.'],

            // December.
            'wildlife_conservation' => ['title' => 'Watching, not disturbing'],
            'sinterklaas' => ['title' => 'Pakjesavond', 'blurb' => 'Small things worth wrapping.'],
            'saint_nicolas' => ['title' => 'Saint-Nicolas', 'blurb' => 'Small things worth wrapping.'],
            'solstice' => ['title' => 'The longest night', 'blurb' => 'Light and blankets, in that order.'],
            'christmas_eve' => ['title' => 'Christmas Eve', 'blurb' => 'The wrapping, at this point, more than the presents.'],
            'christmas_day' => ['title' => 'Christmas Day', 'blurb' => 'For whoever is looking anyway: batteries, and a game that needs a fourth player.'],
            'boxing_day' => ['title' => 'Boxing Day', 'blurb' => 'The long afternoon.'],
            'new_years_eve' => ['title' => "New Year's Eve", 'blurb' => 'Glasses, mostly.'],
        ],

        /*
         * Evergreen themes for every day the named calendar does not cover.
         *
         * The copy rule that matters: **never imply the date has a name.** These
         * appear on ordinary Tuesdays, and "today we celebrate the desk reset"
         * invents a holiday. A title and, at most, a line of framing.
         */
        'day_themes' => [
            'desk_reset' => ['title' => 'The desk reset', 'blurb' => 'The point where the cables stop being a problem you look at every day.'],
            'coffee_ritual' => ['title' => 'The morning ritual', 'blurb' => 'Ten minutes you either enjoy or endure.'],
            'tea_corner' => ['title' => 'The tea corner'],
            'one_good_knife' => ['title' => 'One good knife', 'blurb' => 'The single upgrade that changes cooking most.'],
            'slow_cooking' => ['title' => 'Things that take hours', 'blurb' => 'Cast iron and patience.'],
            'baking' => ['title' => 'Weighing, mixing, waiting'],
            'sound' => ['title' => 'Sounding better', 'blurb' => 'Where the money actually goes further than the marketing suggests.'],
            'vinyl' => ['title' => 'Records and what plays them'],
            'gaming_night' => ['title' => 'The setup', 'blurb' => 'The parts you touch, which is where the difference is.'],
            'board_games' => ['title' => 'Around a table', 'blurb' => 'For evenings without a screen in them.'],
            'reading_nook' => ['title' => 'Somewhere to read', 'blurb' => 'A chair is optional; the light is not.'],
            'better_sleep' => ['title' => 'Sleeping better', 'blurb' => 'A third of your life, furnished carelessly.'],
            'bathroom' => ['title' => 'The small room'],
            'skincare' => ['title' => 'The face you have'],
            'hair' => ['title' => 'Hair, handled'],
            'shaving' => ['title' => 'Grooming, unfussily'],
            'running' => ['title' => 'Going out running', 'blurb' => 'Shoes first, then everything else.'],
            'yoga' => ['title' => 'Floor work', 'blurb' => 'Almost nothing, and the almost matters.'],
            'home_gym' => ['title' => 'The spare room gym', 'blurb' => 'For when the subscription lapsed.'],
            'cycling' => ['title' => 'On two wheels'],
            'travel_kit' => ['title' => 'Packing properly', 'blurb' => 'The kit that survives the airport.'],
            'small_flying_things' => ['title' => 'Small flying things'],
            'smart_home' => ['title' => 'The house that does things', 'blurb' => 'Start with a lamp, end up with a hobby.'],
            'clean_house' => ['title' => 'Cleaning, mechanised'],
            'laundry' => ['title' => 'The laundry problem', 'blurb' => 'Nobody has solved it. Some of this helps.'],
            'storage' => ['title' => 'Where to put it all'],
            'plants' => ['title' => 'Keeping plants alive', 'blurb' => 'The honest answer is light and water on a schedule.'],
            'tools' => ['title' => 'Fixing it yourself'],
            'car_care' => ['title' => 'Inside the car', 'blurb' => 'The half nobody thinks about until January.'],
            'the_dog' => ['title' => 'For the dog'],
            'the_cat' => ['title' => 'For the cat', 'blurb' => 'Purchased for them, judged by them.'],
            'kids_making' => ['title' => 'Making a mess on purpose', 'blurb' => 'For a rainy afternoon with children in it.'],
            'bricks' => ['title' => 'Building things'],
            'writing' => ['title' => 'On paper', 'blurb' => 'Still the fastest interface ever designed.'],
            'drawing' => ['title' => 'Drawing badly, happily'],
            'sewing' => ['title' => 'Made rather than bought'],
            'photography_kit' => ['title' => 'The bits around the camera', 'blurb' => 'Where the actual improvements hide.'],
            'the_hallway' => ['title' => 'The first two metres', 'blurb' => 'The part of the house everyone sees and nobody furnishes.'],
            'phone_life' => ['title' => 'Keeping the phone alive'],
            'first_flat' => ['title' => 'The first flat', 'blurb' => 'Everything you only discover you need on the second evening.'],

            'grilling' => ['title' => 'Cooking outside'],
            'picnic' => ['title' => 'Eating on the ground', 'blurb' => 'A blanket, a box, and better weather than expected.'],
            'beach' => ['title' => 'Sand and salt'],
            'keeping_cool' => ['title' => 'Getting through the heat', 'blurb' => 'Bought in a panic every year. Buy it now instead.'],
            'camping' => ['title' => 'Sleeping outdoors'],
            'garden' => ['title' => 'The garden, or the balcony'],
            'hiking' => ['title' => 'A long walk'],
            'cosy' => ['title' => 'Staying in', 'blurb' => 'The season for it has arrived whether you agreed or not.'],
            'hot_drinks' => ['title' => 'Something hot'],
            'rain' => ['title' => 'The wet months', 'blurb' => 'The coat you should have bought in September.'],
            'indoor_air' => ['title' => 'The air indoors', 'blurb' => 'Closed windows, five months, one small machine.'],
            'winter_sports' => ['title' => 'Snow, eventually'],
            'dark_evenings' => ['title' => 'Dark by five', 'blurb' => 'Lamps, mostly, and one that pretends to be the sun.'],
            'spring_clean' => ['title' => 'The annual purge'],

            'early_summer' => ['title' => 'The first warm weekend', 'blurb' => 'It arrives without notice and everything sells out in a fortnight.'],
            'pool_side' => ['title' => 'Water in the garden'],
            'grilling_season' => ['title' => 'Barbecue season', 'blurb' => 'Bought now, or bought in a queue in July.'],
            'holiday_packing' => ['title' => 'Before you go', 'blurb' => 'The list you write on the drive to the airport.'],
            'school_run_up' => ['title' => 'Before term starts', 'blurb' => 'Cheaper now than in the last week of August.'],
            'pre_halloween' => ['title' => 'Before Halloween', 'blurb' => 'Costumes decided this week are better than costumes decided on the day.'],
            'autumn_indoors' => ['title' => 'Moving indoors'],
            'sinterklaas_run_up' => ['title' => 'Before pakjesavond', 'blurb' => 'The shortlist, while there is still stock.'],
            'gift_season' => ['title' => 'Present season', 'blurb' => 'Ideas for the people who are hard to buy for.'],
            'new_year_reset' => ['title' => 'The January reset', 'blurb' => 'Everyone means it in week one. Some of this survives to March.'],
        ],

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
