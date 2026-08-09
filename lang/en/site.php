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
        'santa' => 'Secret Santa',
        'cove' => 'Gift Cove',
    ],

    'home' => [
        'title' => 'Find it, love it, gift it',
        'headline_1' => "You don't know what you want.",
        'headline_2' => "You know who it's for.",
        'intro' => 'Search bol, Amazon and hundreds of shops at once, follow a brand wherever it turns up, and let the Gift Whisperer turn a description of a person into something worth wrapping.',
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
        'gifting_heading' => 'Buying for someone else',
        'gifting_intro' => 'Describe them and we will suggest something. Or let them tell you themselves, and never see who bought what.',
        'gifting_whisperer' => 'Find a gift for someone',
        'gifting_whisperer_hint' => 'Six questions about them, four suggestions with a reason each.',
        'gifting_people_count' => ':count people you shop for',
        'gifting_lists' => 'Lists',
        'gifting_lists_hint' => 'Save things for yourself, or research a present without them seeing.',
        'gifting_lists_count' => ':count lists on the go',
        'gifting_santa' => 'Secret Santa',
        'gifting_santa_hint' => 'One group, one draw, nobody knows who has who.',
        'gifting_santa_count' => ':count groups you are running',
    ],

    'search' => [
        'title' => 'Search',
        'placeholder' => 'Search for a product, brand, barcode or paste an Amazon link',
        'pasted_searched' => 'That is an Amazon link. We read the product as :terms and looked for it at the shops we cover.',
        'pasted_unreadable' => 'That is an Amazon link, but it carries no product name we can read, only its Amazon code. Copy the longer link with the product title in it, or search for the product by name.',
        'pasted_shortlink' => 'That is a shortened Amazon link, and we do not open links to find out where they go. Open it yourself and paste the full address, or search for the product by name.',
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
         * Every clause is a fact this page can back up, the counts, the range
         * and the brands are all read off the results themselves. Keyword-
         * stuffed filler would rank for a fortnight and then not at all.
         */
        'intro_lead' => 'We found :count products for “:term”, with :shops shop listings between them.',
        'intro_prices' => 'Prices for :term here run from :low to :high.',
        'intro_brands' => 'Brands on this page:',
        'intro_discounts' => ':count of the products on this page are below their 30-day median price, the largest saving being :percent%.',
        'intro_comparable' => ':count of these :term are sold by more than one shop, so there is a cheapest offer to find rather than a single price to accept.',
        'intro_terms' => 'Words that come up across these :term listings: :terms.',
        'seo_default' => 'Discover products and brands across bol, Amazon and hundreds of shops at once, with a link to every shop that sells them.',
    ],

    /*
     * Brand pages.
     *
     * Every one of these is emitted only when the number behind it exists, see
     * App\Services\Seo\BrandCopy. Rewriting one of them into a claim the
     * catalogue cannot back up is a bug, not a copy tweak.
     *
     * THIS FILE IS THE FALLBACK, NOT THE SOURCE. These lines are what a page
     * renders when the `copy_templates` table has no enabled variant for the
     * slot: which is what makes that table safe to hand to an editor: deleting
     * every row restores exactly this. `bc:seed-copy` imports them as the first
     * variant of each slot.
     *
     * `lead_2` … `lead_4` are seed material only. Nothing reads them directly:
     * the `lead` slot falls back to `lead`, and the alternatives exist so an
     * editor opens the admin with four opening lines rather than one.
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

        'lead' => 'Looking for :brand? We are tracking :count :brand products and comparing what every shop charges for them.',
        'lead_2' => 'There are :count :brand products in the catalogue right now, with every offer for each one priced side by side.',
        'lead_3' => 'This is every :brand product we can find a live price for, :count of them, across the shops that actually stock the brand.',
        'lead_4' => ':count :brand products, one page, every shop\'s price on each of them.',

        'shops_named' => ':shop stocks more :brand than anyone else we track, and :count shops in total carry the brand: which is what makes the prices below worth comparing.',
        'shops_count' => ':count shop carries :brand at the moment.',

        'price_from' => ':brand starts at :low here.',
        'price_range' => ':brand prices run from :low to :high on this page.',
        'price_range_category' => ':brand prices run from :low to :high, and most of what we carry is :category.',

        'discount_named' => ':shop currently has discounts on :brand: :count products are below their usual price, the largest by :percent%. Measured against our own 30-day median, not a shop\'s crossed-out figure.',
        'discount_count' => ':count :brand products are below their 30-day median price right now.',

        'comparison' => 'Because the same :brand product is often sold by several shops at different prices, the cheapest offer is the thing worth finding, and it is the first one shown on every card below.',
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
    'narrative' => [
        'faq_heading' => 'Questions about :term',
        'related_heading' => 'Related searches',
        'related_intro' => 'Other things people looked for here after searching for :term.',

        'compare_heading' => 'Comparing :term across shops',
        'compare_1' => 'This page collects every :term we can find a live price for and puts one card per physical product, not one card per listing. That distinction is the whole reason the page exists: a shop that stocks the same :term as three others is offering the same object at a different number, and the only interesting question is which number is lowest today.',
        'compare_2' => ':comparable of the :shown products shown here are stocked by more than one shop, so each of those cards is a small comparison in itself, the cheapest offer first, the rest a click away, and the shop names on every one of them. Where a product is sold by a single shop we say so rather than implying a choice that does not exist.',
        'compare_3' => 'Offers come from retailer feeds and from live queries at the moment you load the page, which is why a price here can differ from one you saw this morning. Every link goes to the shop that made the offer; we do not sell anything ourselves and we hold no stock.',

        'prices_heading' => 'What the prices for :term mean',
        'prices_1' => 'Prices on this page run from :low to :high, and that spread is usually a spread of products rather than of shops, the cheapest :term and the most expensive one are rarely the same thing with a different sticker. Sorting by price is the fastest way to see where the useful middle of that range sits.',
        'prices_2' => 'A discount badge here is measured against our own 30-day median price for that exact product, never against a shop\x27s crossed-out figure. The two disagree more often than you would expect: a "was" price is a marketing decision, while a median is what the thing has actually cost over a month across everyone selling it. If nothing genuinely moved, no badge appears.',
        'prices_3' => ':reduced of the products on this page are currently below that median, the largest by :percent%. Prices and stock are re-checked twice a day and live sources are queried on every search, so this page is not a snapshot of last week.',

        'choosing_heading' => 'Choosing between :term',
        'choosing_1' => 'Start with the offer count rather than the price. A product carried by four shops has a real market price and a floor you can trust; one carried by a single shop has a price and no way to test it, which is worth knowing before you decide the deal is good.',
        'choosing_2' => 'Then check stock. Everything on this page is in stock by default, because an unbuyable price is not an offer, you can turn that filter off if you want to see the full range, including things that are only temporarily unavailable. The price history on each product page shows whether today is genuinely a good moment or an ordinary one.',
        'choosing_3' => 'Brands appearing in these results include :brands. Each has a page of its own listing everything we carry from it, with the same comparison across shops.',

        'faq_price_q' => 'How much does :term cost?',
        'faq_price_a' => 'On this page, :term ranges from :low to :high. That range covers :count products across the shops we track, so the low end and the high end are usually different kinds of product rather than the same one at two prices.',
        'faq_where_q' => 'Where can I buy :term?',
        'faq_where_a' => 'From the shops listed on each card. This page draws :shops shop listings across the products shown. We are a discovery site, not a retailer: every link goes to the shop making the offer, and you buy from them on their own terms.',
        'faq_fresh_q' => 'How current are these :term prices?',
        'faq_fresh_a' => 'Feed prices are refreshed twice a day and live sources are queried when you search, so this page reflects today rather than last week. A price can still change between loading the page and reaching the shop, and the shop\x27s own page is always the final word.',
    ],

    /*
     * The same idea on a brand page. Different framing: this reader has already
     * chosen the brand and is choosing between its products and its shops.
     *
     * Placeholders: :brand :shop :category :count :shown :shops :comparable
     * :reduced :percent :low :high
     */
    'brand_narrative' => [
        'faq_heading' => 'Questions about :brand',
        'related_heading' => 'Related searches',
        'related_intro' => 'What people looked for here around :brand.',

        'compare_heading' => 'Comparing :brand prices across shops',
        'compare_2' => ':comparable of the :shown :brand products shown here are carried by more than one shop. Those are the ones where comparing actually pays: the same :brand model, the same warranty, a different number at the till. Where only one shop stocks something we say so, rather than implying a choice you do not have.',
        'compare_1' => 'This page gathers every :brand product we can find a live price for and shows one card per product rather than one per listing. Two shops selling the same :brand item produce one card with both prices on it, cheapest first: which is the comparison a shop\x27s own :brand page structurally cannot show you.',
        'compare_3' => ':shop carries more :brand than any other shop we track, which makes it a sensible place to start and a poor place to stop. The cheapest offer on a given :brand product is frequently somewhere else, and it is on the card either way.',

        'prices_heading' => 'What :brand costs here',
        'prices_1' => ':brand prices on this page run from :low to :high. That is a range of products rather than a range of margins, the cheapest :brand thing and the most expensive one are different objects, and sorting by price is the quickest way to see where the range actually clusters.',
        'prices_2' => 'When we mark a :brand product as reduced, that is measured against our own 30-day median for that exact product, not against a shop\x27s crossed-out "was" price. The difference matters: one is a marketing decision, the other is what the product has genuinely cost over a month across everyone selling it.',
        'prices_3' => ':reduced :brand products are below that median right now, the biggest saving being :percent%. Feed prices are re-checked twice a day, so this page is current rather than archived.',

        'choosing_heading' => 'Choosing a :brand product',
        'choosing_1' => 'Most of what we carry from :brand falls under :category, which is a useful thing to know before you scroll: it tells you what this brand is actually for in this market, as opposed to what its catalogue claims worldwide.',
        'choosing_2' => 'Look at the offer count before the price. A :brand product stocked by four shops has a market price you can trust; one stocked by a single shop has a price and nothing to check it against. Everything here is in stock by default, an unbuyable price is not an offer, and you can turn that filter off to see the full catalogue.',
        'choosing_3' => 'Each product page carries the full offer table and 90 days of price history, so you can see whether today is a genuinely good moment to buy this :brand product or an ordinary one.',

        'faq_price_q' => 'How much do :brand products cost?',
        'faq_price_a' => ':brand products on this page range from :low to :high, across :count products from the shops we track. The low and the high are usually different products rather than the same one at two prices.',
        'faq_where_q' => 'Which shops sell :brand?',
        'faq_where_a' => 'The shops named on each card. This page draws :shops shop listings across the :brand products shown. We list rather than sell: every link goes to the shop making the offer.',
        'faq_discount_q' => 'Is :brand on offer right now?',
        'faq_discount_a' => 'Yes, :reduced :brand products are currently below their 30-day median price, the largest by :percent%. That is measured against our own price history rather than a shop\x27s crossed-out figure, so it reflects a real movement rather than a claimed one.',
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
        'typical_price' => 'Typical price :price',
        'barcode' => 'Barcode',
        'price_as_of' => 'Price and availability as of the time shown and may change.',
        'disclosure' => 'We may earn a commission if you buy through this link. The price you pay is unchanged.',
        'unavailable' => 'This product is not currently available from any shop we track.',
        'seo_compare' => ':title from :price, compare offers from :count shops and find the cheapest.',
        'seo_single' => ':title from :price. Compare offers and check the price history before you buy.',
    ],

    /*
     * Cove subscriptions.
     *
     * Every response to the signup form is identical whatever actually happened,
     * so the form cannot be used to discover whether an address reads this site.
     */
    'cove' => [
        'subscribe_heading' => 'The Cove, every morning',
        'subscribe_intro' => 'One short email a day: the theme, a few of the finds, and the puzzle. No product spam, and one click to leave.',
        'subscribe_placeholder' => 'you@example.com',
        'subscribe_button' => 'Send it to me',
        'subscribe_thanks' => 'Check your inbox, if that address is new to us, a confirmation link is on its way.',
        'subscribe_privacy' => 'We use your address for this email and nothing else.',
        'confirm_done' => "You're on the list. The next Cove arrives tomorrow morning.",
        'confirm_invalid' => 'That link has expired or has already been used. Sign up again to get a new one.',
        'unsubscribed' => "You're unsubscribed. No hard feelings.",
    ],
    'suggestions' => [
        'heading' => 'Suggested for you',
        'hint' => 'Nothing appears on your list until you accept it.',
        'from' => 'From :name',
        'accept' => 'Add it',
        'dismiss' => 'No thanks',
        'sent' => 'Sent. They decide whether it goes on the list.',
        'accepted' => 'Added to your list.',
        'dismissed' => 'Dismissed.',
        'suggest' => 'Suggest something',
    ],

    'registry' => [
        'heading' => 'Make this a registry',
        'hint' => 'Add an occasion and a date, and a delivery address if people should post things to you.',
        'occasion' => 'Occasion',
        'none' => 'Not a registry',
        'date' => 'Date',
        'address' => 'Delivery address',
        'address_hint' => 'Stored encrypted, and shown only to someone who has claimed something.',
        'types' => [
            'wedding' => 'Wedding',
            'baby' => 'New baby',
            'housewarming' => 'New home',
            'birthday' => 'Birthday',
            'other' => 'Something else',
        ],
    ],

    'handover' => [
        'heading' => 'Hand this list over',
        'hint' => 'Give the list to :name. It becomes their own wishlist, and they can share it and be bought from.',
        'action' => 'Hand it over',
        'confirm' => 'Give this list to :name? You will no longer own it.',
        'done' => 'Handed over to :name.',
        'already' => 'This list has already been handed over.',
        'only_gift_lists' => 'Only a list for someone else can be handed over.',
        'not_linked' => 'They need to claim their link first, so there is an account to hand it to.',
    ],

    'pledges' => [
        'heading' => 'Chip in together',
        'hint' => 'Say what you are putting in. One person buys it and the rest settle up between you.',
        'amount' => 'Your share',
        'your_name' => 'Your name',
        'added' => 'You are in.',
        'removed' => 'Taken back out.',
        'pledged' => ':total pledged of :price',
        'who' => ':name put in :amount',
        'join' => 'I am in',
        'leave' => 'Actually, no',
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
        'puzzle_tease' => "Today's price puzzle is waiting. Most people get it in three.",
        'why_receiving' => 'You are receiving this because you confirmed a subscription to the Daily Cove.',
        'unsubscribe' => 'Unsubscribe',
    ],
    'legal' => [
        'about' => 'About',
        'privacy' => 'Privacy',
        'terms' => 'Terms',
        'updated' => 'Last updated :date',
        'untranslated' => 'This page has not been translated yet, so you are reading the English version. The English text is the one that applies.',
    ],

    'footer' => [
        'affiliate' => 'Brandcoves compares offers across shops. We may earn a commission on purchases made through our links, it never changes what you pay.',
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
        'sign_out' => 'Sign out',
        'mail_subject' => 'Your Brandcoves sign-in link',
        'mail_heading' => 'Sign in to Brandcoves',
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
        'title' => 'My lists',
        'subtitle' => 'Things you are saving, for yourself and for other people.',
        'default_title' => 'My wishlist',
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
        'owner_view_note' => 'This is your own list, so claims are hidden from you, that is the point.',
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
        'marked_sent' => 'Marked as bought.',
        'cannot_mark_sent' => 'You can only do that for something you claimed.',
        'mark_sent' => 'I have bought it',
        'sent' => 'Bought',
        'progress' => ':claimed of :total claimed',
        'not_claimable' => 'This is a shortlist, not a wish list, so nothing here can be claimed.',
        'asked_for' => 'What :name asked for',
        'asked_none' => ':name has not put anything on a list yet.',
        'my_finds' => 'What I found',
        'collaborator_invited' => 'If they have an account, they can see this list now.',
        'collaborator_removed' => 'Removed.',
        'collaborators' => 'Who else can see this',
        'invite_collaborator' => 'Invite someone to help',
        'invite_hint' => 'Useful when several of you are buying together. They see what has been claimed, so nobody doubles up.',
        'role_viewer' => 'Can look',
        'role_editor' => 'Can add and remove',
        'share_email' => 'Email',
        'friends' => 'Friends lists',
        'friends_empty' => 'Nobody you follow has a public list yet.',
        'follow' => 'Follow',
        'unfollow' => 'Unfollow',
        'followed' => 'Following them now.',
        'shared_intro_anon' => 'Tap an item to mark that you are getting it. Whoever made this list will not see who claimed what.',
        'for_me' => 'For me',
        'for_someone_else' => 'For someone else',
        'for_person' => 'For :name',
        'cancel' => 'Cancel',
        'share_heading' => 'Share this list',
        'share_text' => 'Here is my list: :title',
        'someones_wishlist' => ":name's wishlist",
        'share_native' => 'More apps…',
        'share_instagram' => 'Instagram cannot take links from a browser — copy it and paste it there.',
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
        'self_title' => 'Tell them what you would actually like',
        'self_intro' => 'Someone is shopping for you, :name. Answer as much or as little as you like, and add anything you actually want.',
        'saved' => 'Saved. They will see it next time they look.',
        'linked' => 'This is you now.',
        'claim_this_is_me' => 'This is me',
        'claim_hint' => 'Link this to your account and your own lists show up when they shop for you.',
        'my_list' => 'What :name would like',
        'about_you' => 'About you',
        'your_list' => 'Things you would like',
        'add_something' => 'Add something',
        'search_placeholder' => 'Search for something you want',
        'suggest' => 'Show me ideas',
        'suggest_hint' => 'Not sure? Answer the questions above and we will suggest things.',
        'nothing_yet' => 'Nothing here yet. Add the first thing.',
        'privacy_note' => 'You will never see who is getting what. That is the whole idea.',
        'ask_them' => 'Ask them what they want',
        'ask_them_hint' => 'Send this link. They fill in their own tastes and never see what you picked.',
    ],
    /*
    |--------------------------------------------------------------------------
    | Secret Santa
    |--------------------------------------------------------------------------
    |
    | An assignment layer over ordinary lists: the draw decides who you are
    | shopping for, and the rest is the gift page you already know.
    */
    'santa' => [
        'title' => 'Secret Santa',
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
        'exclusions_hint' => 'Names or emails, one per line. Partners, or whoever you had last year.',
        'joined' => 'You are in. We will email you when the draw happens.',
        'members' => 'Who is in',
        'draw' => 'Do the draw',
        'drawn' => 'Drawn. Everyone has been emailed.',
        'redraw' => 'Redraw this person',
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
        'invite_text' => 'Join our Secret Santa: :title',
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
        'copied' => 'Copied. Go and gloat.',
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
    | Each tool gets a sentence saying what it is *for*. "Secret Santa" explains
    | itself; "a list you build for somebody and then hand over" does not, and a
    | tool nobody understands is a tool nobody opens.
    */
    'cove' => [
        'title' => 'The Gift Cove',
        'intro' => 'Everything for buying for other people, and for telling them what you would like. Nobody ever sees who bought what.',
        'tools' => 'What you can do here',
        'items_count' => ':count things saved',
        'open_list' => 'Open my wishlist',
        'start_list' => 'Start my wishlist',
        'privacy' => 'One rule runs through all of it: the person a list is for never learns what has been claimed. Not who, not how many, not that anything has.',

        'wishlist_title' => 'My wishlist',
        'wishlist_body' => 'Things you would actually like. Share it and people can mark what they are getting, without you ever seeing who took what.',

        'giftlist_title' => 'A list for someone else',
        'giftlist_body' => 'Somewhere to gather ideas for one person. Private to you, and never claimable, because it is research rather than a registry.',

        'collab_title' => 'Buy together',
        'collab_body' => 'Invite other people onto a gift list so several of you can choose together, or pledge towards one bigger present and let one person buy it.',

        'handover_title' => 'Hand a list over',
        'handover_body' => 'Started a list for someone before they were here? Give it to them once they join, and it becomes their own wishlist.',

        'santa_title' => 'Secret Santa',
        'santa_body' => 'One group, one draw, nobody knows who has who. Everyone can attach their own wishlist so their Santa is not guessing.',

        'registry_title' => 'A registry',
        'registry_body' => 'A wishlist with an occasion and a date on it, for a wedding, a baby or a new home. Add a delivery address and only people who have claimed something can see it.',

        'quiz_title' => 'How well do they know you?',
        'quiz_body' => 'Turn your wishlist into a quiz: four things, one of them really on it. Share the score, not the answers.',

        'suggestions_title' => 'Suggestions',
        'suggestions_body' => 'People who know you can put things forward for your list. Nothing appears on it until you say yes.',

        'whisperer_title' => 'Gift Whisperer',
        'whisperer_body' => 'Describe a person and get four ideas, each with the reason it was chosen. For when you know who it is for and not what to buy.',
    ],

    'reminders' => [
        'birthday_title' => ":name's birthday is coming up",
        'exchange_title' => ':title is coming up',
        'lead_14' => 'Two weeks to go. Enough time to find something good for :name.',
        'lead_3' => 'Three days to go. Time to decide about :name.',
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
            'space' => ['title' => 'Yuri\x27s Night', 'blurb' => 'The first human in orbit, sixty-odd years ago today.'],
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
    ],

    'guides' => [
        'title' => 'Buying guides',
        'subtitle' => 'Written from what people search for here, not from a keyword tool.',
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
        'template_intro' => ':count options for :topic, with every shop\x27s price compared side by side.',
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
                'description' => 'You know what you want. Every shop\x27s price, one card per product.',
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
                'description' => 'Real savings, measured against our own price history and against the other shops, never against a merchant\x27s “was” price.',
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
        'default_footnote' => 'brandcoves.com',
        'product' => 'Product',
        'guide' => 'Buying guide',
        'guide_footnote' => ':count products, chosen and written up',
        'brand' => 'Brand',
        'brand_footnote' => ':products products at :shops shops',
        'shops' => '{1} 1 shop|[2,*] :count shops',
        'from_price' => 'from :price',
    ],
];
