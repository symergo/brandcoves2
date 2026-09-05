<?php

declare(strict_types=1);

/**
 * Site copy, per language.
 *
 * Keyed by LANGUAGE (nl, fr, en, es), not by market, be-nl and nl-nl are two
 * markets sharing one language, so they share this file. What differs between
 * them is the catalogue, currency formatting and merchants, not the words.
 */
return [
    'nav' => [
        'search' => 'Search',
        'feedback' => 'Feedback',
        'organise' => 'Organise',
        'discover' => 'Discover',
        'submenu' => 'What is in :section',
        'gift' => 'Gift Finder',
        'daily' => 'Daily Cove',
        'guides' => 'Guides',
        'surprise' => 'Surprise Cove',
        'lists' => 'My Lists',
        'shared_lists' => 'Shared Lists',
        'group_lists' => 'Group Lists',
        'notifications' => 'Notifications',
        'sign_in' => 'Sign in',
        'sign_out' => 'Sign out',
        'admin' => 'Admin',
        'main' => 'Main',
        'account' => 'Account',
        'skip' => 'Skip to content',
        'choose_market' => 'Choose your market',
        'choose_language' => 'Choose your language',

        /*
         * The flags on the switcher. Europe is not a country and is not
         * pretending to be one — it is where the English market lives, because
         * that market exists for a language rather than for a place.
         */
        'countries' => [
            'be' => 'Belgium',
            'nl' => 'Netherlands',
            'int' => 'International',
            'es' => 'Spain',
        ],
        /*
         * The Cove types, as the Discover menu lists them.
         *
         * Three shapes of the same thing: an edition that changes every
         * morning, a shelf built around a person, and a long read built around
         * a subject. `coves` — "Idea Cove" — used to be the only one of these
         * in the header and stood for the article archive alone, which made
         * "Cove" mean one of its three meanings in the one place a visitor
         * learns the word.
         *
         * The qualifier translates and the noun does not, per the rule in
         * localisation.md: Gift Coves / Cadeau Coves / Coves Cadeau.
         */
        'gift_coves' => 'Gift Coves',
        'all_coves' => 'All Coves',
        'brand_coves' => 'Brand Coves',

        /*
         * Deliberately NOT one of the row above, and deliberately without
         * "Cove" in its name.
         *
         * The other shelves are a shape — a gift, a brand, a shop — and
         * "Cove" is our word for what we make of one. This shelf is not a
         * shape but a promise: it is where you learn to buy better.
         * "Inspiration Coves" said neither, and somebody looking for buying
         * advice does not click on inspiration.
         *
         * So it translates in full, unlike the Cove names: Shop Smarter /
         * Slim kopen / Acheter malin / Comprar mejor. The key is `smart`
         * because that is the one word all four keep.
         */
        'smart' => 'Shop Smarter',

        /*
         * One line under each entry in the Discover menu.
         *
         * Five Cove entries whose names differ by one word — Daily, Surprise,
         * Theme, Gift, All — cannot be told apart by their labels alone the
         * first time somebody opens the menu. Deliberately short: this is a
         * dropdown, not the hub page, and the hub is one click away for the
         * argument.
         */
        'hint_daily' => 'New every morning',
        'hint_surprise' => 'Something rare, not something popular',
        'hint_smart' => 'Buying advice and guides by subject',
        'hint_gift_coves' => 'Ideas built around a person',
        'hint_all_coves' => 'Everything we have published',
        'hint_ask' => 'Let other people suggest something',

        'santa' => 'Secret Friend',

        // Names, not words: a Cove is called the same thing in every language,
        // exactly like GiftCoves itself. A translated name is a second name.
        'cove' => 'Gift Cove',
        'discover_cove' => 'Discover Cove',
    ],

    'home' => [
        /*
         * `seo_title` and `seo_description` are what a search engine and a
         * social card show; `title` is the browser tab and, on most pages, the
         * H1 as well. They are separate keys because they are read in different
         * places: a listing has to say what the site is to someone who has never
         * heard of it, and an H1 sitting above the page it describes does not.
         *
         * The home page is the exception: its `title` is not an H1 anywhere —
         * the hero uses `headline_1`/`headline_2` — so one key serves the tab,
         * the listing and the social card. It carries the brand itself, and the
         * title template in app.tsx skips its own suffix when the name is
         * already there.
         */
        'seo_description' => 'Search bol, Amazon and hundreds of shops at once. Keep wish lists, share them, club together on one gift, and run a Secret Friend.',
        'title' => 'GiftCoves wish lists: give and get gifts at the best price',
        'headline_1' => 'Something worth giving.',
        'headline_2' => 'Yourself included.',
        // Names the second way in, not a third example. The examples this
        // used to carry ("Headphones, a coffee grinder, a brand name…") taught
        // the syntax of a field nobody struggles with; the camera beside it is
        // the affordance people miss, because a scan button is not something a
        // search box normally has. Only the two fields that actually sit next
        // to a `ScanButton` — this one and `search.placeholder` — may promise
        // it; the same line in a modal with no camera is a broken promise.
        'search_placeholder' => 'Search for a gift or scan a barcode',
        'recent_heading' => 'Recently searched',
        'cta_gift' => 'Find a gift',
        'today_badge' => "Today's Cove",
        'today_cta' => "See today's finds",
        /*
         * The persona band, worded as the shelf at /gift-ideas words
         * itself. Two headings for one thing that read differently is
         * how a visitor ends up unsure whether they are the same page.
         */
        'personas_heading' => 'Gift ideas, by person',
        'personas_all' => 'All gift ideas',

        'coves_heading' => 'Coves',
        'coves_intro' => 'Long reads around a theme, with every brand and product linked straight into a live search.',
        'coves_all' => 'All Coves',
        'coves_volume' => ':count searches a month',
        /*
         * The card for your own lists, which used to say nothing at all: the
         * band's opening sentence covered all five cards and this one was the
         * only card left leaning on it. With that sentence gone the card has to
         * say what it is, and privacy is the part a first-time visitor is
         * actually unsure about.
         */
        'organise_mine_hint' => 'What you want yourself, kept in one place and private until you send somebody the link.',
        'organise_group_hint' => 'One present, several people, and nobody has to chase anyone for the money.',
        // The card says what a registry IS to somebody who has none, and which
        // one theirs is to somebody who has. Never how much of it has been
        // bought — this is the owner's own front page (invariant #4).
        'organise_occasion' => 'Occasion',
        'organise_occasion_hint' => 'Put a date on a list — a birthday, a wedding, Christmas — and everyone with the link knows what it is for.',
        'organise_registry_on' => ':occasion on :date',
        'gifting_lists' => 'Lists',
        'gifting_lists_count' => ':count lists on the go',
        'gifting_santa' => 'Secret Friend',
        'gifting_santa_hint' => 'One group, one draw, nobody knows who has who.',
        'gifting_santa_count' => ':count groups you are running',
    ],

    'search' => [
        'title' => 'Search',
        'placeholder' => 'Search for a gift or scan a barcode',
        'pasted_searched' => 'That is an Amazon link. We read the product as :terms and looked for it at the shops we cover.',
        'pasted_unreadable' => 'That is an Amazon link, but it carries no product name we can read, only its Amazon code. Copy the longer link with the product title in it, or search for the product by name.',
        'pasted_shortlink' => 'That is a shortened Amazon link, and we do not open links to find out where they go. Open it yourself and paste the full address, or search for the product by name.',
        'submit' => 'Search',
        'searching' => 'Searching…',
        'results_for' => 'Results for ":term"',

        /*
         * The browser tab and the search listing, which is NOT `results_for`.
         *
         * `results_for` stays where it belongs: a live region announcing a new
         * result set to a screen reader. It is a poor listing title — the first
         * twelve characters, the ones weighted hardest, spend themselves on
         * "Results for", and the quotation marks read as an exact-match
         * citation rather than as a page about the thing.
         *
         * The term leads instead, capitalised, followed by the phrase this
         * market actually shops in.
         *
         * ## This one is allowed past 60 characters, and that is the trade
         *
         * The phrase alone is 39-48 characters, so it cannot share the ~60 a
         * listing shows with a real search term — there is no wording of "at
         * the best price - offers and discounts" that leaves room for
         * "koptelefoon". Chosen deliberately on 2026-09-05: the words that earn
         * the click are all in front, and what a search engine drops off the
         * end is " · GiftCoves" and possibly the last word of the phrase.
         *
         * Every other interpolated title on the site still measures itself and
         * degrades. This one only guards the term: past 30 characters the query
         * is a sentence, already carries its own intent, and stands alone.
         */
        'seo_title_term' => ':term at the best price - offers and discounts',
        'empty' => 'Nothing matched ":term".',
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
        'brand' => 'Brand',
        'shop' => 'Shop',
        // The chip row above the by-store lanes: the reset, and the label a
        // screen reader hears on a chip that is currently narrowing the view.
        'all_shops' => 'All shops',
        'only_shop' => 'Show only :shop',
        'hide_shop' => 'Stop showing :shop',
        'in_stock_only' => 'In stock only',
        'discounted_only' => 'Discounted only',
        /*
         * The hand-off to Amazon, in the sidebar.
         *
         * `:term` is the visitor's own words, unquoted — the quotation marks
         * were removed on request; they made a one-word search look like a
         * citation. `amazon_search_host` names the storefront rather than
         * leaving "Amazon" to mean whichever country they expect — a Belgian
         * visitor landing on amazon.com.be should have been told so before the
         * click, not after it.
         */
        'amazon_search' => 'Search :term on Amazon too',
        // Shown where there is no term to quote: the search page before
        // anything is typed. Not the same sentence with an empty gap in it.
        'amazon_search_any' => 'Try searching on Amazon',
        'amazon_search_host' => 'Opens :host',
        'previous' => 'Previous',
        'next' => 'Next',
        'page_of' => 'Page :current of :last',
        'seo_term' => 'Find :term at bol, Amazon and hundreds of shops. One card per product, with the lowest price and any discount marked.',

        /*
         * The vocabulary of the results, above the grid.
         *
         * What replaced four paragraphs of statistics — counts, price ranges,
         * how many were reduced. Those numbers were true and nobody read them:
         * a shopper can see the grid, and a sentence counting what is already on
         * screen is not a reason to stop scrolling. The words are the half that
         * was doing work, and as links they are also navigation.
         */
        'terms_heading' => 'Often in these results',
        'seo_default' => 'Discover products and brands across bol, Amazon and hundreds of shops at once, with a link to every shop that sells them.',
    ],

    /*
     * Brand pages — the chrome. Headings, breadcrumbs, empty states.
     *
     * The templated paragraphs that used to live here — the opening line and its
     * three alternatives, the shop counts, the price ranges, the discount claims
     * — were the `brand_intro` copy surface, and they were removed with it when
     * the brand page stopped opening with statistics. The prose a brand page
     * still carries is `brand_narrative`, further down this file.
     *
     * `and` stays: `PageNarrative` joins its category lists with it.
     */
    'brand' => [
        'title' => ':brand offers and discounts',
        'heading' => ':brand',
        'seo_description' => 'Every :brand product with the price at each shop that sells it. Compare the offers and see where :brand costs least right now.',
        'crumb' => 'Brands',
        'index_title' => 'Brands',
        'index_seo_title' => 'Every brand, A to Z',
        'index_seo_description' => 'Every brand in the catalogue, with live prices from bol, Amazon and hundreds of shops that stock them.',
        'index_intro' => 'Every brand in the catalogue, with live prices from the shops that stock it.',
        'index_empty' => 'No brands in this market yet.',
        'products_heading' => ':brand products',
        'coves_heading' => 'Coves that mention :brand',
        'related_heading' => 'Other brands people look at',
        'empty' => 'Nothing from :brand is in stock right now.',
        'and' => 'and',
        /*
         * Offers from a source we may show but not store. The note says why they
         * sit apart from the grid instead of leaving a reader to wonder — they
         * are single listings rather than full product pages, and saying so is
         * more honest than a heading that implies otherwise.
         */
        'narrowed_to' => 'Narrowed to',
        'live_heading' => 'More :brand, fetched just now',
        'live_note' => 'Listed live from a shop whose prices we are not allowed to keep, so these are single offers rather than a full product page.',
    ],
    /*
     * Long-form copy below a results grid.
     *
     * Every line is either a fact read off the page (counts, ranges, brands) or a
     * true explanation of how the site works. Neither can be padded with the
     * keyword, which is the point, see App\Services\Seo\PageNarrative.
     *
     * Placeholders available everywhere: :term :count :shown :shops :comparable
     * :reduced :percent :low :high
     */

    /*
     * The same idea on a brand page. Different framing: this reader has already
     * chosen the brand and is choosing between its products and its shops.
     *
     * Placeholders: :brand :shop :category :count :shown :shops :comparable
     * :reduced :percent :low :high
     */

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
        'typical_price' => 'Typical price :price',
        'barcode' => 'Barcode',
        /*
         * The Amazon hand-off, product-page wording.
         *
         * "this product" rather than the barcode itself: a shopper does not
         * recognise their product in a thirteen-digit number, so the number
         * goes in the detail line as evidence of *what* will be searched
         * instead of standing in for the product's name.
         */
        /*
         * The shop's own description, below the offers.
         *
         * ":shop" is not decoration. The text is a merchant's marketing copy
         * quoted verbatim, and unattributed it reads as this site's editorial
         * voice — a claim we cannot stand behind and did not write.
         */
        'description_heading' => 'About this product',
        'description_source' => 'Description supplied by :shop.',
        'amazon_search' => 'Search for this product on Amazon too',
        'amazon_search_barcode' => 'By barcode :ean',
        'price_as_of' => 'Price and availability as of the time shown and may change.',
        'disclosure' => 'We may earn a commission if you buy through this link. The price you pay is unchanged.',
        'unavailable' => 'This product is not currently available from any shop we track.',
        'seo_compare' => 'From :price at :count shops. Compare every seller of :title and see where it is cheapest right now.',
        'seo_single' => 'From :price, with the price history before you buy. :title',
        // Nothing is priced yet, so neither of the two above will do:
        // both open with a price and would print an empty gap where it goes.
        'seo_unpriced' => ':title — the shops that stock it, with the price as soon as one lists it.',

        /*
         * The shop count goes in the title; the price stays in the description.
         *
         * Both are ours to claim and only one of them is safe up there. A
         * cached snippet quoting a price we no longer offer is a trust problem,
         * and the price is the number most likely to have moved since the last
         * crawl. A merchant count barely moves. The JSON-LD AggregateOffer
         * remains the honest machine-readable copy of both.
         */
        'seo_title_multi' => ':title — at :count shops',
    ],

    /*
     * Cove subscriptions.
     *
     * Every response to the signup form is identical whatever actually happened,
     * so the form cannot be used to discover whether an address reads this site.
     */
    'discover_cove' => [
        'seo_title' => 'Gift ideas and product finds, new every day',
        'seo_description' => 'Three ways to find something you were not looking for: a new edition every day, a surprise chosen for how rare it is, and long reads around one theme.',
        'title' => 'Discover',
        // Counts no longer. The hub described "three" while the cards were
        // four, and the persona card made it five — a number in the copy is a
        // promise the card row has to keep, and this one has been broken twice.
        'intro' => 'Ways to find something you were not looking for. One changes every day, one is deliberately unpredictable, one is about a person rather than a thing, and the rest are worth sitting down with.',
        'daily_what' => 'A new edition every day: a theme, a handful of finds and a price puzzle. Every past edition keeps its own page.',
        'surprise_what' => 'Something you did not know existed, chosen for how rare it is rather than how well it sells.',
        'idea_what' => 'Buying advice and guides around one subject: what to look at and what actually makes the difference, with every brand and product linked straight into a live search.',
        'persona_what' => 'Presents chosen around a person rather than a date — the coffee obsessive, the one who already has everything.',
        'persona_all' => 'All gift ideas',
    ],

    /*
     * All Coves: the overview page at /coves.
     *
     * The one page that shows the whole shape of the thing the site is named
     * after. Section copy says what each kind *is*, because "Shop Smarter" and
     * "Gift Coves" say little to a reader meeting them for the first
     * time has nothing else to go on.
     */
    'shops' => [
        'seo_title' => 'The online shops we link to',
        'seo_description' => 'Every shop whose offers appear here, with the ones that arrived most recently called out. No totals, just the list.',
        'title' => 'Shop Coves',
        'intro' => 'Every offer on this site names the shop it came from. These are those shops — the ones serving this market.',
        'empty' => 'No shops wired up for this market yet.',
        'coves_heading' => 'Written about these shops',
        'coves_what' => 'What a shop is like to buy from — the half of the decision a price cannot answer.',
        'new_heading' => 'New here',
        'new_what' => 'Wired up in the last month. They appear in the list below as well — this is a spotlight, not a filter.',
        'new_badge' => 'New',
        'all_heading' => 'Every shop',
    ],

    'coves' => [
        'seo_title' => 'Gift ideas, guides and reading',
        'seo_description' => 'The full shelf. A new edition every morning, gift ideas built around a person, and long reads around one subject with live prices inside them.',
        'title' => 'All Coves',
        'intro' => 'Everything we have written here, by the shape it takes. One arrives every morning, one is built around a person, and one is built around a subject.',
        'empty' => 'Nothing published in this market yet. The first Coves are on their way.',
        'daily_heading' => 'Daily Cove',
        'daily_what' => 'One edition every morning: a theme, a handful of finds and a price puzzle. Every past edition keeps its own page.',
        'daily_all' => "Read today's edition",
        'gift_heading' => 'Gift Coves',
        'gift_what' => 'Built around a person rather than a date — the herbalist, the dad who has everything, the friend who reads.',
        'gift_all' => 'All Gift Coves',
        'smart_heading' => 'Shop Smarter',
        'smart_what' => 'Buying advice and guides: what to look at, what makes the difference and what it should cost — long reads around one subject.',
        'smart_all' => 'All buying advice',
        'brand_heading' => 'Brand Coves',
        'brand_what' => 'One page per maker: everything of theirs we carry here, with every shop’s price on each product.',
        'brand_all' => 'All Brand Coves',
        'shop_heading' => 'Shop Coves',
        'shop_what' => 'The online shops serving this market, newest first.',
        'shop_all' => 'All Shop Coves',
        'rail_products' => 'More in these categories',
    ],

    'cove' => [
        'subscribe_heading' => 'The Cove, every morning',
        'subscribe_intro' => 'One short email a day: the theme, a few of the finds, and why they are worth a look. No product spam, and one click to leave.',
        'subscribe_placeholder' => 'you@example.com',
        'subscribe_button' => 'Send it to me',
        'subscribe_thanks' => 'Check your inbox, if that address is new to us, a confirmation link is on its way.',
        'subscribe_privacy' => 'We use your address for this email and nothing else.',
        'confirm_done' => "You're on the list. The next Cove arrives tomorrow morning.",
        'confirm_invalid' => 'That link has expired or has already been used. Sign up again to get a new one.',
        'unsubscribed' => "You're unsubscribed. No hard feelings.",
    ],
    'suggestions' => [
        'added' => 'Added to the list.',
        'add_invite' => 'Add something to this list',
        'add_invite_hint' => 'Anything you add goes straight on, so everyone can see it and claim it.',
        'add_action' => 'Add to the list',
        'heading' => 'Suggested for you',
        'from' => 'From :name',
        'from_anonymous' => 'From someone with your link',
        'waiting' => ':count waiting',
        'one_waiting' => '1 waiting',
        'note_label' => 'Add a note',
        'accept' => 'Add it',
        'dismiss' => 'No thanks',
        'sent' => 'Sent. They decide whether it goes on the list.',
        'accepted' => 'Added to your list.',
        'dismissed' => 'Dismissed.',
        'suggest' => 'Suggest something',
        'invite' => 'Know something they would like?',
        'invite_hint' => 'Put it forward and they decide. It joins the list only if they accept it.',
        'search_placeholder' => 'Search for something they would like',
        'none_found' => 'Nothing matched that. Try another word.',
        'already_on_list' => 'That one is already on the list.',
        'manual_hint' => 'Not in the shops we cover? Put it forward anyway — they still decide.',
    ],

    'registry' => [
        'hint' => 'Say what this list is for, and when. Everybody you share the link with sees it.',
        'occasion' => 'Occasion',
        'none' => 'No occasion',
        'date' => 'Date',
        'address' => 'Delivery address',
        'address_hint' => 'Stored encrypted, and shown only to someone who has claimed something.',
        'send_to' => 'Where to send it',
        'address_locked' => 'Claim something and the delivery address appears here.',
        'occasion_on' => ':occasion on :date',
        'types' => [
            'birthday' => 'Birthday',
            'christmas' => 'Christmas',
            'wedding' => 'Wedding',
            'anniversary' => 'Anniversary',
            'baby' => 'New baby',
            'housewarming' => 'New home',
            'graduation' => 'Graduation',
            'retirement' => 'Retirement',
            'farewell' => 'Leaving do',
            'valentines' => 'Valentine’s Day',
            'mothers_day' => 'Mother’s Day',
            'fathers_day' => 'Father’s Day',
            'thank_you' => 'Thank you',
            'other' => 'Something else',
        ],
        'badge' => 'Special occasion',
    ],

    'handover' => [
        'hint' => 'Give the list to :name. It becomes their own wishlist, which they can share with others.',
        'action' => 'Hand it over',
        'confirm' => 'Give this list to :name? You will no longer own it.',
        'done' => 'Handed over to :name.',
        'already' => 'This list has already been handed over.',
        'only_gift_lists' => 'Only a list for someone else can be handed over.',
        'no_account' => 'Nobody with that email has an account here yet. Send them the "ask them what they want" link first.',
        'badge' => 'Hand over',
    ],

    'votes' => [
        'vote' => 'Vote for this',
        'voted' => 'Voted',
        'none' => 'No votes yet',
        'count' => ':count votes',
        'heading' => 'Vote for the one we should get',
    ],

    /*
     * The discussion beside a shared list.
     *
     * `hint` states who is reading, because that is the one thing somebody
     * typing here needs to know and cannot see: the people with the link, and
     * — on a wish list — not the person it is for. See
     * App\Services\Wishlist\Board.
     */
    'board' => [
        'title' => 'Talk it over',
        'hint' => 'Everyone with the link can read this. The person the list is for cannot.',
        'empty' => 'Nothing said yet. Start it off.',
        'placeholder' => 'Shall we go halves on the coat?',
        'your_name' => 'Your name',
        'post' => 'Post',
        'remove' => 'Delete',
        'posted' => 'Posted.',
        'removed' => 'Deleted.',
    ],

    'pledges' => [
        'hint' => 'Say what you are putting in. One person buys it and the rest settle up between you.',
        'amount' => 'Your share',
        'your_name' => 'Your name',
        'added' => 'You are in.',
        'removed' => 'Taken back out.',
        'pledged' => ':total pledged of :price',
        'join' => 'I am in',
        'leave' => 'Actually, no',

        // How many are in, never who or for how much. "Four people are in" is
        // coordination; a ladder of names and amounts is pressure on whoever
        // put in least, so only the organiser of a group list is shown that.
        'count' => ':count people are in',
        'one_in' => 'One person is in',
        'standard_share' => 'You are in for :amount.',
        'none' => 'Nobody has put anything in yet.',
        'your_share_is' => 'You put in :amount',
        'organiser_note' => 'You can see who put in what. Everyone else sees the total and their own share.',
    ],

    'cove_mail' => [
        'confirm_subject' => 'Confirm your Daily Cove subscription',
        'confirm_heading' => 'One click and you are in',
        'confirm_body' => 'Tap below to confirm you want the Daily Cove. Until you do, we will not send you anything else.',
        'confirm_button' => 'Confirm my subscription',
        'confirm_expiry' => 'The link works for 48 hours.',
        'confirm_requested_from' => 'Requested from :ip',
        'confirm_ignore' => 'If this was not you, ignore this email, nothing happens without the click, and we will not write again.',

        'digest_subject' => "Today's Cove: :theme",
        'digest_button' => "Open today's Cove",
        'across_shops' => 'at :count shops',
        'more_on_page' => 'There are :count more finds on the page, including some we can only show there.',
        'why_receiving' => 'You are receiving this because you confirmed a subscription to the Daily Cove.',
        'unsubscribe' => 'Unsubscribe',
    ],
    'legal' => [
        'about' => 'About',
        'privacy' => 'Privacy',
        'terms' => 'Terms',
        'cookies' => 'Cookies',
        'updated' => 'Last updated :date',
        'untranslated' => 'This page has not been translated yet, so you are reading the English version. The English text is the one that applies.',
    ],

    /*
     * The cookie banner. One question, asked once.
     *
     * The body says what the cookie is for and does not pretend the site needs
     * it — it does not, and a visitor who declines loses nothing. Naming
     * Google is deliberate: "we use cookies to improve your experience" tells
     * somebody nothing they could base a decision on.
     */
    'cookies' => [
        'title' => 'Cookies',
        'body' => 'We would like to count visits with Google Analytics, which sets a cookie. Nothing on this site needs it, so it is entirely your call.',
        'accept' => 'Allow',
        'decline' => 'No thanks',
        'more' => 'What we collect',
    ],

    'footer' => [
        'affiliate' => 'We may earn a commission on purchases made through our links, it never changes what you pay.',
        'explore' => 'Explore',
    ],

    'auth' => [
        'title' => 'Sign in',
        'intro' => 'Enter your email and we will send you a link. No password to remember.',
        'email' => 'Email address',
        'send' => 'Send me a link',
        'link_sent' => 'Check your inbox, if that address has an account, a sign-in link is on its way.',
        'link_invalid' => 'That link has expired or has already been used. Request a new one.',
        'too_many' => 'Too many requests. Try again in :seconds seconds.',
        'or' => 'or',
        'google' => 'Continue with Google',
        'mail_subject' => 'Your GiftCoves sign-in link',
        'mail_heading' => 'Sign in to GiftCoves',
        'mail_body' => 'Tap the button below to sign in. The link works once and only from this email.',
        'mail_button' => 'Sign in',
        'mail_expiry' => 'The link expires in 15 minutes.',
        'mail_requested_from' => 'Requested from :ip',
        'mail_ignore' => 'If you did not request this, you can safely ignore this email, nobody can sign in without the link.',
        'mail_fallback' => 'If the button does not work, paste this into your browser:',
        'name' => 'Your name (optional)',
        'mail_failed' => 'We could not send the email just now. Try again in a moment.',
    ],

    'lists' => [

        // Public, and it explains itself to a visitor with no account,
        // so it is indexable. It shipped with no title and no description
        // at all until 2026-09-05.
        'seo_title' => 'Wish lists you can share',
        'seo_description' => 'Keep a wish list, share it with the people buying for you, and let them claim a gift without you seeing who claimed what.',
        'title' => 'My lists',
        'subtitle' => 'Everything you are saving, and everything other people have shared with you.',
        'shared_subtitle' => 'Lists people have shared with you. This is how you shop for them.',
        'shared_empty' => 'Nobody has shared a list with you yet. When they do, it appears here — with what they want on it.',
        'shop_for' => 'Claim something for :name',
        'shared_with_me' => 'Shared with me',
        'owned_by' => 'From :name',
        'group_subtitle' => 'One gift, chosen together. Everyone votes, and what each of you puts in stays between you and the organiser.',
        'default_title' => 'My wishlist',
        'default_badge' => 'Default',
        'shared_short' => 'Shared',
        'private_short' => 'Private',
        'tool_on' => 'on',
        'find_things' => 'Find things to add',
        'manual_add' => 'Add something yourself',
        'manual_title' => 'What is it?',
        'manual_url' => 'Link (optional)',
        'manual_price' => 'Price (optional)',
        'manual_save' => 'Add it',
        'manual_no_preview' => 'We do not open the link, so it shows exactly what you type here.',
        'manual_url_invalid' => 'A link has to start with https://',
        'added_to' => 'Saved to :list',
        'view_list' => 'View list',
        'undo' => 'Undo',
        // The picker fetch failed, which is not the same as owning no lists —
        // and offering "+ New list" to someone whose connection dropped is how
        // a duplicate of a list they already have gets made.
        'save_failed' => 'That did not save. Try again?',
        // Filling one named list, rather than saving one product.
        'adding_to' => 'Adding to :list',
        'added_count' => ':count added',
        'done_adding' => 'Done',
        'add_to_this' => 'Add to :list',
        // One control for the whole of putting a thing on a list: search the
        // catalogue and the live sources, adjust the wording, or write in
        // something we do not sell — without searching first.
        'add_product' => 'Add a product',
        'add_search_placeholder' => 'Search for a product...',
        'search_failed' => 'The search did not work. Try again?',
        'add_nothing_found' => 'Nothing found for ":term".',
        'add_own_intro' => 'Not in the shops we cover?',
        'add_own_cta' => 'Write it in yourself',
        'add_description' => 'Description',
        'add_note_placeholder' => 'size M, in blue',
        'add_live_title_note' => 'The title and price come straight from :shop, so they cannot be edited here.',
        'back' => 'Back',
        'new_list' => 'New list',
        // The front page's version of the same button. Longer on
        // purpose: on /lists it sits under a heading that already says
        // "lists", and on the home page it has to say what it makes.
        'make_new' => 'Make a new list',
        'list_name' => 'List name',
        'create' => 'Create list',
        'for_someone' => 'This list is for someone else',
        'for_whom' => 'Who is it for?',
        'empty' => 'Nothing saved yet.',
        'empty_hint' => 'Search for a product and press Save.',
        'empty_list' => 'This list is empty.',
        'empty_mine_step1' => 'Add things you would like. The bookmark on any product puts it here.',
        'empty_mine_step2' => 'When you are ready, press Share. Not before — this is yours until you say otherwise.',
        'empty_mine_step3' => 'People claim what they are getting, or you send the list as a quiz.',
        'empty_for_someone_step1' => 'Add ideas as you come across them. Nobody else sees this yet.',
        'empty_for_someone_step2' => 'If others are helping, press Share and add them by email.',
        'empty_for_someone_step3' => 'Everyone marks what they are getting, so nobody buys the same thing twice.',
        'empty_group_step1' => 'Add a few candidates. You will all choose one of them.',
        'empty_group_step2' => 'Press Share and invite the others.',
        'empty_group_step3' => 'They vote for the one to get and say what they can put in.',
        'items' => ':count items',
        'one_item' => '1 item',
        'added' => 'Saved to your list.',
        'removed' => 'Removed.',
        'remove' => 'Remove',
        'save' => 'Save',
        'saved' => 'Saved',
        'save_to_list' => 'Save to a list',
        'save_to' => 'Save to :list',
        'move_to' => 'Move to :list',
        'remove_from' => 'Remove from :list',
        'delete_list' => 'Delete this list',
        'delete_confirm' => 'Delete this list and everything in it?',
        'share' => 'Share',
        'sharing_off' => 'Only you can see this list.',
        'sharing_on' => 'Anyone with the link can see this list.',
        'share_hint' => 'This list is private. Share it and anyone with the link can see it.',
        'disable_sharing' => 'Stop sharing',
        'anyone_can_add' => 'Anyone can add gifts',
        'pledgers_visible' => 'Everyone sees who is chipping in',
        'pledgers_visible_hint' => 'Names only. What each person put in stays yours alone.',
        'voting_enabled' => 'Everyone can vote on the presents',
        'voting_enabled_hint' => 'The shortlist sorts itself by the tally. Turn it off if the present is already decided.',
        'pledge_mode' => 'How everyone chips in',
        'pledge_mode_each' => 'Everyone says what they are putting in',
        'pledge_mode_fixed' => 'Everyone puts in the same',
        'pledge_mode_each_person' => 'each',
        'copy_link' => 'Copy link',
        'copied' => 'Link copied',
        'claim' => 'I\'ll get this',
        'claimed' => 'I am getting this',
        'claimed_by_someone' => 'Someone is getting this',
        'unclaim' => 'Actually, I am not',
        'unclaimed' => 'No longer yours to get.',
        'already_claimed' => 'Someone else just claimed that one.',
        'cannot_unclaim' => 'You can only undo your own claim, and only within a day.',
        'shared_intro' => 'Tap an item to mark that you are getting it. :name will not see who claimed what.',
        'recipient_added' => 'Person added.',
        'recipient_removed' => 'Person removed.',
        'add_person' => 'Add a person',
        'person_name' => 'Their name',
        'someone_new' => 'Someone new',
        'copy_to' => 'Copy to another list',
        'copy_to_which' => 'Which list?',
        'copied_to' => 'Copied to :list.',
        'add_to_my_list' => 'Add to my list',
        'birthday_optional' => 'Their birthday (optional)',
        'birthday_why' => 'Day and month only. We use it to remind you in good time — never to work out their age.',
        'birthday_day' => 'Day',
        'birthday_month' => 'Month',
        'price_now' => 'Now :price',
        'sign_in_to_keep' => 'Sign in to keep these lists safe',
        'sign_in_hint' => 'Sign in and everything you save is kept to your account, on every device you use.',
        'marked_sent' => 'Marked as bought.',
        'cannot_mark_sent' => 'You can only do that for something you claimed.',
        'mark_sent' => 'I have bought it',
        'sent' => 'Bought',
        'progress' => ':claimed of :total claimed',
        'asked_none' => ':name has not put anything on a list yet.',
        'ask_tab' => 'Ask :name',
        'collaborator_removed' => 'Removed.',
        'who_sees_what' => 'Who sees what',
        'share_link' => 'The link to this list',
        'copy_message' => 'Copy message and link',
        'copy_manual' => 'Your browser would not let us copy. The link is selected — press Ctrl+C.',
        'invited_before' => 'Invited before sharing became a link',
        'role_viewer' => 'Can look',
        'role_editor' => 'Can add and remove',
        'share_email' => 'Email',
        'friends' => 'Friends lists',
        'friends_empty' => 'Nobody you follow has a public list yet.',
        'follow' => 'Follow',
        'unfollow' => 'Unfollow',
        'followed' => 'Following them now.',
        'shared_intro_anon' => 'Tap an item to mark that you are getting it. Whoever made this list will not see who claimed what.',
        'shared_intro_gift' => 'Several of you are buying for this person. Mark what you are getting so nobody buys the same thing twice.',
        'shared_intro_group' => 'You are all buying one present together. Vote for the one to get, then say what you can put in.',
        'progress_gift' => ':claimed of :total spoken for',
        /*
         * What kind of list this is, and what that means you can do with it.
         *
         * The badge names the kind and never changes. The sentence reads the
         * kind AND whether anybody else is on the list: most lists are private,
         * and a private list offers none of the mechanisms — so it says what
         * the list is now, then what sharing would do. That second half is the
         * only place these features are ever taught.
         *
         * See resources/js/Components/ListKindBadge.tsx.
         */
        'claimed_by' => ':name is getting this',
        'claim_anonymous_note' => 'Nobody will see it was you — not even the person organising this list.',
        'claim_named_note' => 'Your name will be shown to the others on this list, so they know who is getting what.',
        'claim_names_visible' => 'Names of who is buying what are visible (except to the recipient)',
        'claim_mine_show_hint_mine' => 'Off by default: a wish list works because you do not know what is coming. Turn it on if you would rather see.',
        'claim_mine_show' => 'Show me what has been claimed',
        'claim_mine' => 'What you see',
        'kind_mine' => 'Wish list',
        'kind_for_someone' => 'Gift list',
        'kind_group' => 'Group gift',
        'about_mine_private' => 'Things you are keeping. Only you can see this — share it and people can claim them, and you will never see which.',
        'about_mine_shared' => 'People can claim things off this list. You will never see which.',
        'about_for_someone_private' => 'A list about them, and only you can see it. Share it if several of you are buying.',
        'about_for_someone_shared' => 'Several of you are buying different things. Claim one so nobody doubles up.',
        'about_group_private' => 'Nobody can join yet. Press Share to invite them.',
        'about_group_shared' => 'You are buying one present together. Vote for it, then say what you can put in.',
        'quiz_unlocks' => 'Share it and you can send it as a quiz: four products, one really yours. See who knows you best.',
        'new_mine_body' => 'Things you would like. Keep it to yourself, or share it and people can claim them.',
        'new_for_someone_body' => 'A list about them. Keep it to yourself, or share it and split the shopping.',
        'new_group_body' => 'Several of you buy one present and split it. Everyone votes and chips in.',

        'for_me' => 'For me',
        'for_someone_else' => 'For someone else',
        // The third kind. Named by what you do rather than by what it is
        // called internally: "group list" describes our schema, "together, for
        // someone" describes the afternoon you are about to have.
        'for_group' => 'Together, for someone',
        'group_gift' => 'Group gift',
        'start_group_gift' => 'Start a group gift',
        'for_person' => 'For :name',
        'cancel' => 'Cancel',
        'share_text' => 'Here is my list: :title',
        'someones_wishlist' => ":name's wishlist",
        'gift_list_for' => 'Gift list for :name',
        'shared_by' => ':name shared this list',
        'note_add' => 'Add a note',
        'note_edit' => 'Edit',
        'note_placeholder' => 'Anything the people opening this should know.',
        'share_native' => 'More apps…',
        'share_instagram' => 'Instagram cannot take links from a browser — copy it and paste it there.',
        'shared_badge' => 'Shared — anyone with the link can see it',
        'private_badge' => 'Private to you',
        'owner_view_note' => 'This is your own list, so claims are hidden from you, that is the point.',
    ],

    /*
    |--------------------------------------------------------------------------
    | The other end of a recipient
    |--------------------------------------------------------------------------
    |
    | Read by the person being bought for. Every string here has to be written
    | as if they will read it, because they will - nothing may hint at what has
    | been picked for them.
    */
    'recipients' => [
        'step_birthday' => 'When is your birthday?',
        'birthday_why' => 'Day and month only, so they can be reminded in time. We never ask for the year.',
        'self_title' => 'Tell them what you would actually like',
        'self_intro' => 'Someone is shopping for you, :name. Answer as much or as little as you like, and add anything you actually want.',
        'saved' => 'Saved. They will see it next time they look.',
        'linked' => 'This is you now.',
        'claim_this_is_me' => 'This is me',
        'claim_is_you' => 'This is the link you send them. You made this list, so it cannot be your own — they say "this is me" at their end.',
        'claim_sign_in' => 'Sign in to say this is you. Then this becomes your own list, and you can share it with anyone.',
        'claim_hint' => 'Link this to your account and your own lists show up when they shop for you.',
        'my_list' => 'What :name would like',
        'about_you' => 'About you',
        'step_interests' => 'What are you into?',
        'step_vibe' => 'How should it feel?',
        'step_values' => 'Anything that matters to you?',
        'your_list' => 'Things you would like',
        'add_something' => 'Add something',
        'search_placeholder' => 'Search for something you want',
        'suggest' => 'Show me ideas',
        'nothing_yet' => 'Nothing here yet. Add the first thing.',
        'ask_them' => 'Ask them what they want',
        'ask_them_hint' => 'Send this link. They fill in their own tastes and never see what you picked.',
    ],
    /*
    |--------------------------------------------------------------------------
    | Secret Friend
    |--------------------------------------------------------------------------
    |
    | An assignment layer over ordinary lists: the draw decides who you are
    | shopping for, and the rest is the gift page you already know.
    */
    'santa' => [

        // Public, and it explains itself to a visitor with no account,
        // so it is indexable. It shipped with no title and no description
        // at all until 2026-09-05.
        'seo_title' => 'Secret Friend, drawn online',
        'seo_description' => 'Set up a group, draw the names online, and everybody sees only who they are buying for. No hat, no spreadsheet, no accidental spoilers.',
        'title' => 'Secret Friend',
        'subtitle' => 'One group, one draw, nobody knows who has who.',
        'create' => 'Start a group',
        'group_name' => 'What is this group called?',
        'budget' => 'Budget',
        'budget_hint' => 'Roughly what everyone should spend.',
        'exchange_date' => 'When are you exchanging?',
        'theme' => 'Theme (optional)',
        'invite' => 'Invite link',
        'invite_hint' => 'Send this to everyone. They join with a name and an email.',
        'join' => 'Join this group',
        'your_name' => 'Your name',
        'your_email' => 'Your email',
        'exclusions' => 'Anyone you should not draw?',
        'exclusions_hint' => 'Names or emails, separated by commas. Partners, or whoever you had last year.',
        'joined' => 'You are in. We will email you when the draw happens.',
        'members' => 'Who is in',
        'draw' => 'Do the draw',
        'drawn' => 'Drawn. Everyone has been emailed.',
        'redraw' => 'Redraw this person',
        'remove_member' => 'Remove',
        'member_removed' => 'Removed. Their giver has been told who they are buying for now.',
        'remove_confirm' => 'Remove :name from this group?',
        'remove_confirm_drawn' => 'Remove :name? The draw has happened, so one other person will be emailed a new name. That cannot be undone.',
        'redraw_confirm' => 'Redraw for :name? Two people will be emailed a new name, and that cannot be undone.',
        'email_changed_subject' => 'Your Secret Friend has changed: you now have :name',
        'email_changed_intro' => 'Something changed in the group, so this replaces the name we sent you before.',
        'redrawn' => 'Redrawn. Both people have been emailed.',
        'you_have' => 'You are buying for :name',
        'their_list' => 'What :name asked for',
        'no_list' => ':name has not made a list. You are on your own, but we can help.',
        'build_yours' => 'Make your own list first',
        'build_yours_hint' => 'Whoever drew you has nothing to go on until you do.',
        'mark_done' => 'I have bought mine',
        'marked_done' => 'Nice. That is you finished.',
        'done_count' => ':done of :total have finished shopping',
        'too_few' => 'You need at least two people to draw.',
        'impossible' => 'Nobody can be matched with those exclusions.',
        'already_drawn' => 'This group has already been drawn.',
        'not_drawn' => 'The draw has not happened yet.',
        'organiser_only' => 'Only the organiser can do that.',
        'email_subject' => 'You have drawn :name',
        'email_intro' => 'The draw is done. You are buying for :name.',
        'email_budget' => 'The budget is around :budget.',
        'email_date' => 'You are exchanging on :date.',
        'email_list' => 'They have made a list. Have a look:',
        'email_no_list' => 'They have not made a list yet, so you are going in blind. We can help with that:',
        'attach_hint' => 'Point a group at this list so whoever drew you has something to go on.',
        'attach_list' => 'Use this list',
        'list_attached' => 'That group now sees this list.',
        'list_attached_short' => 'In use',
        'invite_text' => 'Join our Secret Friend: :title',
        'delete' => 'Delete this group',
        'delete_confirm' => 'Delete :title? Everyone who joined will lose it.',
        'delete_confirm_drawn' => 'Delete :title? It has already been drawn, so everyone loses who they were buying for — and nobody will be told. Tell them yourself first.',
        'deleted' => 'Group deleted.',
        'email_hint' => 'So the person who draws you can be told who they have. It is not shown to anyone else.',
    ],
    'quiz' => [
        'title' => 'How well do you know them?',
        'intro' => 'Four things. One of them is really on :name list. Pick it.',
        'create' => 'Make a quiz from this list',
        'created' => 'Quiz ready. Send it to anyone.',
        'too_short' => 'You need at least :count things on the list before a quiz is worth playing.',
        'share_first' => 'Share the list first. A quiz shows what is on it.',
        'round' => 'Round :current of :total',
        'score' => 'You got :score out of :total',
        'share' => 'Share your score',
        'played' => ':count people have played',
        'average' => 'Average score :score',
        'owner_note' => 'This is your own list, so you cannot play. That would be cheating.',
        'missed' => 'What you missed',
        'missed_hint' => 'Every one of these is something they actually want.',
        'play_again' => 'You have already played. One go each, otherwise the score means nothing.',
        'intro_own' => 'Share this list as a quiz: four things, one of them really on your list.',
        'share_text' => 'How well do you know me?',
        'own_title' => 'Find out how well your friends know you!',
        'open' => 'Open the quiz',
        'intro_anon' => 'Four things. One of them is really on their list. Pick it.',
        'badge' => 'Quiz',
    ],
    'preview' => [
        'badge' => 'Preview',
        'note' => 'This has not been published. Nobody else can see it, and search engines are told to ignore it.',
    ],

    /*
    |--------------------------------------------------------------------------
    | The Gift Cove
    |--------------------------------------------------------------------------
    |
    | Each tool gets a sentence saying what it is *for*. "Secret Friend" explains
    | itself; "a list you build for somebody and then hand over" does not, and a
    | tool nobody understands is a tool nobody opens.
    |
    | `_body` answers "what is this"; `_step1..3` answers "how do I do it", and
    | the two are written for different readers — one scanning nine cards, one
    | who has already chosen and needs the button's name. So a step quotes the
    | label that is really on the screen ("press Share", "press People"). Rename
    | a control and the step that names it is now wrong.
    |
    | Three steps, and no fourth line. Caveats were written and removed: this is
    | how to start, not the full behaviour of the tool, and every rule it drops
    | is enforced by the tool itself regardless.
    */
    'gift_cove' => [
        'seo_title' => 'Wish lists, gift lists and Secret Friend',
        'seo_description' => 'Nine gifting tools in one place: wish lists, a list for someone else, buying together, Secret Friend and a gift quiz. Nobody sees who bought what.',
        'title' => 'The Gift Cove',
        'rail_hint' => 'Everything here for buying for somebody else, in one place.',
        'rail_cta' => 'Open the Gift Cove',
        'intro' => 'Everything for buying for other people, and for telling them what you would like. Nobody ever sees who bought what.',
        'tools' => 'What you can do here',
        'items_count' => ':count things saved',
        'open_list' => 'Open my wishlist',
        'start_list' => 'Start my wishlist',
        'my_wishlists' => 'My wishlists',
        'another_list' => 'Another wishlist',
        'lists_count' => ':count wishlists',
        'privacy' => 'One rule runs through all of it: the person a list is for never learns what has been claimed. Not who, not how many, not that anything has.',

        'manual' => 'How each one works',
        'manual_link' => 'How each one works',
        'manual_intro' => 'Nine tools and the steps for each. Every button named below is a button on the page it sends you to.',
        'manual_back' => 'Back to the Gift Cove',

        'wishlist_title' => 'My wishlist',
        'wishlist_body' => 'Things you would actually like. Share it and people can mark what they are getting, without you ever seeing who took what.',
        'wishlist_step1' => 'Find something you want and press the bookmark on it. The picker asks which list; choose your own.',
        'wishlist_step2' => 'Open the list and press Share. That turns the link on and shows it, ready to send to whoever is asking what you want.',
        'wishlist_step3' => 'They open the link and mark what they are getting. You are never shown that anything has been marked.',

        'giftlist_title' => 'A list for someone else',
        'giftlist_body' => 'Somewhere to gather ideas for one person. Private to you, and never claimable, because it is research rather than a registry.',
        'giftlist_step1' => 'Press New list, choose "For someone else" and name the person. This card opens that form already on that setting.',
        'giftlist_step2' => 'Save things to it as you come across them, exactly as you would to any other list.',
        'giftlist_step3' => 'Keep it to yourself, or press Share: then the others can see it and mark what they are getting, so nobody buys the same thing twice.',

        'collab_title' => 'Buy together',
        'collab_body' => 'Invite other people onto a gift list so several of you can choose together, or pledge towards one bigger present and let one person buy it.',
        'collab_step1' => 'Press New list, choose "Together, for someone", and name the person it is for.',
        'collab_step2' => 'Press Share and send the link to each co-giver. Anyone holding it can look and claim; you decide whether they can add things too.',
        'collab_step3' => 'Choose together, and mark what you are getting so two of you never buy the same thing. Under Share you also decide whether names are shown.',

        'handover_title' => 'Hand a list over',
        'handover_body' => 'Started a list for someone before they were here? Give it to them once they join, and it becomes their own wishlist.',
        'handover_step1' => 'Open the list and send them the "Ask them what they want" link, so there is an account the list can go to.',
        'handover_step2' => 'Once they have used it, press Hand over and type the email they signed up with.',
        'handover_step3' => 'Confirm, and the list is theirs: they can share it, and other people can claim from it.',

        'santa_title' => 'Secret Friend',
        'santa_body' => 'One group, one draw, nobody knows who has who. Everyone can attach their own wishlist so whoever draws them is not guessing.',
        'santa_step1' => 'Press Start a group and give it a name, roughly what everyone should spend, and the date you are exchanging.',
        'santa_step2' => 'Send the invite link to everyone. They join with a name and an email, no account needed, and can name anyone they should not draw.',
        'santa_step3' => 'Once everybody is in, press Do the draw. Each person gets one email naming one person: theirs.',

        'registry_title' => 'A registry',
        'registry_body' => 'A wishlist with an occasion and a date on it, for a wedding, a baby or a new home. Add a delivery address and only people who have claimed something can see it.',
        'registry_step1' => 'Open one of your wishlists and press Occasion.',
        'registry_step2' => 'Pick the occasion and the date, and add a delivery address if people should be posting things to you.',
        'registry_step3' => 'Share it as you would any list. It behaves like one: people claim, and you are never told what.',

        'quiz_title' => 'How well do they know you?',
        'quiz_body' => 'Turn your wishlist into a quiz: four things, one of them really on it. Share the score, not the answers.',
        'quiz_step1' => 'Open your list and press Share.',
        'quiz_step2' => 'Press Quiz, then "Make a quiz from this list".',
        'quiz_step3' => 'Send the quiz link. Five rounds of four things, one go each.',

        'suggestions_title' => 'Suggestions',
        'suggestions_body' => 'People who know you can put things forward for your list. Nothing appears on it until you say yes.',
        'suggestions_step1' => 'Share your wishlist. A suggestion can only come from somebody holding the link.',
        'suggestions_step2' => 'When one arrives it waits at the top of the list, with the name of whoever sent it.',
        'suggestions_step3' => 'Press "Add it" and it joins the list, or "No thanks" and it goes. Nothing lands on the list before you decide.',

        'whisperer_title' => 'Gift Whisperer',
        'whisperer_body' => 'Describe a person and get four ideas, each with the reason it was chosen. For when you know who it is for and not what to buy.',
        'whisperer_step1' => 'Answer six short questions about them: who they are, what they are into, what you want to spend, anything to avoid.',
        'whisperer_step2' => 'Four ideas come back, each with the reason it was chosen. Ask for something else and what you rejected is never offered again.',
        'whisperer_step3' => 'Save the good ones straight onto a list for that person.',
    ],

    'reminders' => [
        'birthday_title' => ':name\'s birthday is coming up',
        'exchange_title' => ':title is coming up',
        'list_title' => ':name\'s :occasion is coming up',
        'list_title_mine' => 'Your :occasion is coming up',
        'lead' => ':days days to go. Enough time to sort something out for :name.',
        'list_lead_mine' => ':days days to go. A good moment to check your list is saying what you want it to.',
        'mail_button' => 'Open it',
        'mail_why' => 'You are getting this because you saved this date. You can turn reminders off in your account.',
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
        'seo_description' => 'Describe the person you are buying for and get four gift ideas, each with the reason it was chosen and where to buy it.',

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

    /*
     * The search box explains itself somewhere it can be linked to.
     *
     * Every line here is a claim about behaviour that exists in the code — the
     * "not found is normal" line in particular is the designed limitation from
     * docs/features/barcode-scanner.md, and softening it would turn a working
     * feature into one that looks broken.
     */
    /*
     * Ask others.
     *
     * The copy has one hard job: to be honest that a post is read before it
     * appears, without making that sound like suspicion of the person who wrote
     * it. "We read every post before it goes up" is a promise about the board
     * being worth reading, not an accusation — and saying nothing at all is
     * worse, because then the feature simply looks broken for ten minutes.
     */
    'ask' => [
        'seo_title' => 'Ask others what to buy',
        'seo_description' => 'Stuck for a gift? Describe who it is for and let other people suggest something. Every answer comes with real products and a price.',
        'seo_question' => 'Gift ideas for: :title. Real suggestions from other people, with the products and prices to go with them.',

        'title' => 'Ask others',
        'intro' => 'Need inspiration? Describe who you are buying for and let the GiftCoves community suggest something. Answers come with actual products, not just advice.',
        'nav_hint' => 'Describe who it is for and let other people suggest something.',

        'all' => 'All questions',
        'more_about_them' => 'Say a bit more about them (optional)',
        'more_hint' => 'None of this is required. It just tends to get better answers.',
        'occasion_label' => 'Occasion',
        'occasion_placeholder' => 'Birthday, retirement…',
        'age_label' => 'Roughly how old',
        'age_placeholder' => '30s',
        'ask_cta' => 'Ask a question',
        'ask_heading' => 'What are you stuck on?',
        'question_label' => 'Your question',
        'question_placeholder' => 'My sister is turning 30 and already owns everything',
        'detail_label' => 'Anything else that helps',
        'detail_placeholder' => 'What they are into, what you have already ruled out, how well you know them.',
        'budget_label' => 'Up to',
        'budget_hint' => 'Optional. Answers tend to be better with a number to aim at.',
        'submit' => 'Ask',
        'cancel' => 'Cancel',

        'sign_in_to_ask' => 'Sign in to ask a question or answer one.',

        // Said at the moment of posting, so nobody watches for a question that
        // is not going to appear yet.
        'submitted' => 'Thanks — we read every question before it goes up. Yours will appear shortly.',
        'answer_submitted' => 'Thanks — we read every answer before it goes up.',

        'mine_heading' => 'Your questions',
        'pending_notice' => 'We are reading this one. It is not on the board yet.',
        'rejected_notice' => 'We could not put this one on the board.',

        'empty' => 'Nobody has asked anything yet.',
        'empty_hint' => 'Be the first. Somebody usually knows.',

        'answers' => ':count answers',
        'one_answer' => '1 answer',
        'no_answers' => 'No answers yet',
        'asked_by' => 'Asked by :name',
        'budget_up_to' => 'Up to :amount',

        'answer_heading' => 'Answer this',
        'answer_placeholder' => 'What would you get them, and why?',
        'answer_submit' => 'Post my answer',
        'answers_heading' => 'Answers',
        'be_first' => 'No answers yet. Yours would be the first.',

        // The picker inside the answer form. Products come from our own
        // catalogue rather than a pasted link — see docs/features/ask-others.md.
        'picks_heading' => 'Suggest something specific',
        'picks_hint' => 'Add up to :count things from the shops we cover. An answer with a product in it is worth ten without one.',
        'picks_search' => 'Search for a product',
        'picks_add' => 'Add',
        'picks_added' => 'Added',
        'picks_full' => 'That is as many as one answer can carry.',
        'picks_none_found' => 'Nothing matched that.',

        'status' => [
            'pending' => 'Being read',
            'published' => 'On the board',
            'rejected' => 'Not published',
        ],
    ],

    'invitations' => [
        'mail_subject' => ':name would like your help choosing a gift',
        'mail_heading' => 'Help choose a present',
        'mail_intro' => ':name has asked you to help choose something, on a list called ":list".',
        'mail_intro_for' => ':name has asked you to help choose something for :person.',
        // Says what happens next, because "you have been invited" on its own
        // does not tell somebody whether they need an account.
        'mail_what' => 'Open the link below and sign in with this address. You will be able to see the list and add ideas to it.',
        'mail_button' => 'See the list',
        'mail_expiry' => 'The link works for two weeks.',
        'sign_in_first' => 'Sign in with the address the invitation was sent to, and the list will be waiting.',
    ],

    /*
    |--------------------------------------------------------------------------
    | Feedback
    |--------------------------------------------------------------------------
    |
    | Every quality problem this catalogue has is visible to a visitor long
    | before it is visible to us. These are the words on the only page where
    | they can say so.
    |
    | The address hint is written next to the field rather than left to the
    | privacy policy, because "what will you do with this" is the question being
    | asked at the moment the cursor is in the box.
    |
    */
    'feedback' => [
        'seo_title' => 'Tell us what could be better',
        'seo_description' => 'Say what could be better — a price that is out of date, a link that goes nowhere — or tell us what you like. You do not need an account.',
        'title' => 'Tell us what could be better, or pay us a compliment',
        'message_label' => 'Your message',
        'message_placeholder' => 'What is going wrong, what is missing, what would you do differently? Or just tell us what you like about GiftCoves :D',
        'path_label' => 'Which page?',
        'path_placeholder' => '/en/p/1234/…',
        'email_label' => 'Your email (optional)',
        'email_placeholder' => 'you@example.com',
        'email_hint' => 'Only so we can reply to this. Nothing else is ever sent to it.',
        'submit' => 'Send',
        'sending' => 'Sending…',
        'thanks' => 'Thank you — this has arrived and somebody will read it.',
    ],

    /*
     * What this market searches for, as a page.
     *
     * Replaced the related-search chips under every result set, which were
     * removed for cost on 2026-09-05. Linked from the footer of every page.
     */
    'popular_searches' => [
        'title' => 'What people search for',
        'empty' => 'Nothing to show yet — this market has not been searched enough for a pattern to be worth printing.',
        'empty_link' => 'Try a search',
        'note' => 'Only searches that found something, and only those run often enough to be a pattern rather than one person.',
        'seo_title' => 'What people search for',
        'seo_description' => 'The most-run searches on this site over the last three months, each linking to its results.',
        'popular_heading' => 'Most searched',
        'trending_heading' => 'Rising fastest',
        'trending_intro' => 'Searched more this week than their own recent average, not simply searched most.',
        'latest_heading' => 'Searched recently',
        'latest_intro' => 'Established searches that came round again in the last few days.',
        'movement_up' => 'Higher than the period before',
        'movement_down' => 'Lower than the period before',
        'movement_new' => 'New in this period',
        'movement_new_short' => 'New',
        'movement_same' => 'Unchanged',
        'period_empty' => 'Nothing yet.',
    ],

    'search_help' => [
        'seo_title' => 'How to search: words, barcodes and Amazon links',
        'seo_description' => 'What the search box understands — product names, brands, barcodes and pasted Amazon links — and how to narrow results down to the offer you want.',
        'title' => 'Searching and scanning',
        'intro' => 'What the search box understands, how to narrow a set of results, and what happens when you point a camera at a barcode.',
        'link' => 'What can I search for?',
        /*
         * The footer's own label. `link` is a question — it works beside a
         * search box that has just disappointed somebody, and reads oddly in a
         * row of nouns at the bottom of every page.
         */
        'footer_link' => 'Search tips',

        'searching_heading' => 'What you can search for',
        'searching_intro' => 'One box, four kinds of input. It looks like an ordinary search field and it accepts rather more than one.',
        'what_words_term' => 'Words',
        'what_words' => 'Product names, brands, categories, and words from a shop’s own description. A word in the title counts for more than the same word in a description, so the closest names come first.',
        'what_typos_term' => 'Typos',
        'what_typos' => 'Spelling is forgiven. "blutooth koptelefon" finds Bluetooth headphones — the match is made word by word, so one wrong letter does not cost you the rest of the query.',
        'what_accents_term' => 'Accents',
        'what_accents' => 'Optional in both directions. "creme" finds "crème" and the other way round, in every language we carry.',
        'what_language_term' => 'Language',
        'what_language' => 'Each market searches in its own language, plurals and word endings included. Searching in the language of the shops you are looking at gives the best results.',
        'what_barcode_term' => 'A barcode',
        'what_barcode' => 'Type or paste the digits under the bars — 8 to 14 of them — and you land on the product itself rather than on a list. It is the same lookup the camera does.',
        'what_amazon_term' => 'An Amazon link',
        'what_amazon' => 'Paste the address of an Amazon product page and we read the product name out of the link itself to search for it here. We never open the link, and a shortened amzn.to address has nothing in it to read — paste the full one.',

        'narrowing_heading' => 'Narrowing a set of results',
        'narrowing_intro' => 'The filters sit above the results. Everything you set stays in the address, so a filtered search is a link you can send or bookmark.',
        'narrow_price_term' => 'Price',
        'narrow_price' => 'A floor, a ceiling, or both. Prices are always the ones that apply in your market.',
        'narrow_brand_term' => 'Brand and shop',
        'narrow_brand' => 'Pick one or several of either. Brands are matched by identity rather than by spelling, so "Audio-Technica" and "Audio Technica" are one brand and not two.',
        'narrow_stock_term' => 'In stock',
        'narrow_stock' => 'On by default. Turn it off to include things no shop can send you today.',
        'narrow_sort_term' => 'Sorting',
        'narrow_sort' => 'Best match, price up or down, biggest discount, or newest. There is also a by-shop view, which groups the same results under the shops selling them.',
        'narrow_terms_term' => 'The words above the results',
        'narrow_terms' => 'Words read off the products in front of you. Each one adds itself to what you typed rather than replacing it, so the search narrows and cannot dead-end.',

        'scanning_heading' => 'Scanning a barcode',
        'scanning_intro' => 'Point your phone at the barcode on a box in a shop and find out whether it is cheaper somewhere else, while you are still standing there.',
        'scan_where_term' => 'Where to start',
        'scan_where' => 'The camera button in the search field, on the search page. There is a page of its own as well, if you would rather keep a shortcut to it.',
        'scan_privacy_term' => 'The camera image never leaves your phone',
        'scan_privacy' => 'The barcode is read on the device itself and only the digits are sent to us. No picture is uploaded, stored or logged. The camera needs a secure connection and your permission, and it stays off until you press the button.',
        'scan_devices_term' => 'Which phones can do it',
        'scan_devices' => 'Chrome on Android reads barcodes itself. On iPhone, and in Safari and Firefox, a reader is downloaded when you open the scanner — the first scan takes a moment longer and the ones after it do not.',
        'scan_misses_term' => 'Finding nothing is normal',
        'scan_misses' => 'Only products identified by their barcode can be matched, and not every shop publishes one. We check our own catalogue first and then ask bol directly. Nothing found means we do not carry that item yet — not that it does not exist.',
        'scan_misread_term' => 'Misreads',
        'scan_misread' => 'Every barcode carries a check digit, and a code that fails it is thrown away rather than looked up: one wrong digit is a different real product, not a near miss. If nothing happens, keep the camera on it.',
        'scan_manual_term' => 'When the camera will not read it',
        'scan_manual' => 'Curved, creased and shrink-wrapped barcodes are genuinely hard, and shop lighting does not help. Type the digits in instead — that always works.',

        'go_search' => 'Go to search',
        'go_scan' => 'Open the scanner',
    ],

    'scan' => [
        'title' => 'Scan a barcode',
        'subtitle' => 'Standing in a shop? Scan it and see what it costs everywhere else.',
        'seo_description' => 'Scan a product barcode and see what every shop that stocks it is asking.',
        'start' => 'Open the camera',
        'stop' => 'Stop',
        'manual_placeholder' => 'Or type the barcode',
        'look_up' => 'Look up',
        'close' => 'Close',
        'shops' => 'at :count shops',
        'preparing' => 'Getting ready…',
        'unsupported' => 'The scanner could not start. Type the number below instead, it is on the label under the bars.',
        'no_camera' => 'No camera available, or permission was declined. Type the number below instead.',
        'invalid' => 'That is not a valid barcode. Check the digits under the bars.',
        'not_found' => 'We do not have that one yet.',
        'search_instead' => 'Search for it anyway',
    ],

    'surprise' => [
        'title' => 'Things you did not know existed',
        'subtitle' => 'Rare, in stock, and sold by almost nobody.',
        'seo_description' => 'Unusual products you will not find on a bestseller list, scored for how rare they are and checked for whether they are worth seeing.',
        'reroll' => 'Show me more',
        'empty' => 'Nothing scored yet. Come back after the next catalogue run.',

        // Shown only when no offer carries a description worth printing. The
        // maker's name is the least we can say about an object, and it is more
        // than an empty gap under the title.
        'by_brand' => 'By :brand',
    ],

    /*
     * Gift personas: the Coves that are about a person rather than a day.
     *
     * Same machinery as the Daily Cove, different question. The daily column is
     * a stream you catch up with; these are a shelf you browse, and "who am I
     * shopping for" is the question most visitors actually arrive with.
     */
    'gift_ideas' => [

        /*
         * A persona's listing title, which its theme_title cannot be.
         *
         * "The one who reads" is a good heading and an unsearchable
         * listing: it holds no word anybody types. The query is "gift for
         * someone who reads" and this page is exactly that answer, so the
         * H1 keeps the editorial title and the listing gets this one.
         * GiftIdeasController falls back to the bare theme_title when the
         * two together would run past what a listing shows.
         */
        'persona_seo_title' => 'Gift ideas for :persona',

        /*
         * The placeholder title a drafted persona wears.
         *
         * `PlanDrafter` writes one per interest the gift wizard knows about,
         * so a market's persona shelf can be filled with shortlists to react
         * to rather than a blank table. It is deliberately dull: the interest
         * leads so that a label like "DIY" or "The outdoors" keeps its own
         * capitalisation, and no adjective has to agree with a noun in four
         * languages. A person renames it before approving.
         */
        'draft_title' => ':interest — gift ideas',

        'title' => 'Gift ideas, by person',
        'description' => 'Presents chosen around a person rather than a date — the herbalist, the dad who has everything, the friend who reads.',
        'empty' => 'Nothing here yet. These are written one at a time, and the first is on its way.',
        'finds_title' => 'What to get them',
        'find_count' => ':count ideas',
    ],

    'daily' => [
        'title' => 'The Daily Cove',
        'seo_title' => ':theme — gift tips',
        'seo_description' => 'A handful of things you did not know existed, and a buying guide built from what people actually searched for.',
        'finds_title' => "Today's finds",
        'guide_title' => "Today's guide",
        'guide_why' => 'Written because :count searches here asked for it.',

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
            'wildlife' => ['title' => 'For watching wildlife', 'blurb' => 'World Wildlife Day, things for looking closely at animals that have not agreed to it.'],
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
            'earth' => ['title' => 'Things that last', 'blurb' => 'Earth Day, objects built to be owned twice.'],
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
            'environment' => ['title' => 'Less rubbish', 'blurb' => 'World Environment Day, things that replace something disposable.'],
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
            'photography' => ['title' => 'For looking properly', 'blurb' => 'World Photography Day, the accessories nobody tells you about.'],
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
        'deals_title' => 'Biggest drops right now',
        'deals_hint' => 'Against our own 30-day median, not a shop’s crossed-out price.',
    ],

    'guides' => [
        /*
         * The heading is the section's name; the `seo_*` pair is deliberately
         * not.
         *
         * "Shop Smarter" is what this section is called on this site and is
         * what the header, the footer and the front page say — but nobody
         * searches for it, because it is our phrase. The <title> and the meta
         * description still lead with "buying guides", which is what a person
         * actually types. Our own name in an H1 and the reader's vocabulary in
         * the <title> is the normal split, not an inconsistency.
         *
         * It replaced "Inspiration Coves" on 2026-09-01, which named a mood
         * where this shelf gives advice — see navigation.md.
         */
        'seo_title' => 'Buying guides with a live price on every product',
        'title' => 'Shop Smarter',
        'subtitle' => 'Buying advice and guides, written from what people search for here rather than from a keyword tool.',
        'seo_description' => 'Buying guides built from real search demand, with live prices from every shop that stocks each product.',
        'empty' => 'No buying advice yet. It is written as topics build up enough demand.',
        'how_to_choose' => 'How to choose',
        'faq' => 'Questions',
        'updated' => 'Checked :date',
        'why' => 'written because :count searches here asked for it',
        'shops' => ':count shops',
        'unavailable' => 'Out of stock',
        'slug_prefix' => 'best',
        'template_title' => 'The best :topic',
        'template_intro' => ':count options for :topic, with every shop’s price side by side.',
        // A season published as a series. See docs/features/seasonal-series.md.
        'series_title' => ':topic, part :part',
        'series_slug_part' => 'part',
        'series_heading' => 'In this series',
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
        // it, the same product has to be able to say it is here for a
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
                'description' => 'Someone already did the thinking, shortlists built from what people search for here.',
            ],
            'compare' => [
                'title' => 'Compare',
                'description' => 'The whole category, cheapest to dearest, with the lookalikes marked.',
            ],
            'deals' => [
                'title' => 'Deals',
                'description' => 'Real savings, measured against our own price history and against the other shops, never against a merchant’s “was” price.',
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

    /*
     * Social cards. Rendered into a 1200x630 PNG by OgImage, so these are read
     * by people scrolling a timeline rather than by a crawler: label, headline,
     * one line of substance.
     */
    'og' => [
        'daily' => 'The Daily Cove',
        'default_title' => 'Discover products and brands',
        'default_footnote' => 'giftcoves.com',
        'product' => 'Product',
        'guide' => 'Buying guide',
        'guide_footnote' => ':count products, chosen and written up',
        'brand' => 'Brand',
        'brand_footnote' => ':products products at :shops shops',
        'shops' => '{1} 1 shop|[2,*] :count shops',
        'from_price' => 'from :price',
    ],
];
