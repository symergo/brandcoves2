<?php

declare(strict_types=1);

namespace App\Services\Guides;

use App\Enums\Market;
use App\Services\Search\AmazonSearchLink;
use App\Services\Seo\BrandLinker;
use App\Support\CurrentMarket;

/**
 * Turns the link tokens in a Cove's prose into real internal links.
 *
 * ## Why the model never writes a URL
 *
 * Asked for links, a language model produces confident, well-formed, entirely
 * fictional ones — to brands we do not carry, products that do not exist and
 * paths that were never routed. Every one of them is a 404 in the middle of an
 * article, and at the scale this generates content that is a self-inflicted
 * crawl problem.
 *
 * So the contract is inverted. The builder hands the model an **allowlist** of
 * things it may link to, and the model writes tokens:
 *
 *     [[brand:Sony]]              → /{market}/search?brand=Sony
 *     [[search:draadloze koptelefoon]]
 *                                 → /{market}/search?q=draadloze+koptelefoon
 *     [[product:1234|Sony XM5]]   → /{market}/p/1234/slug
 *     [[guide:beste-koptelefoons]] → /{market}/guides/beste-koptelefoons
 *     [[page:gift-whisperer]]     → /{market}/gift
 *     [[amazon:koptelefoon]]      → the market's Amazon storefront, tagged
 *
 * `guide` and `page` are what make an article part of a site rather than a leaf.
 * `guide` is allowlisted like everything else — it points at a row that has to
 * exist and be published in this market. `page` is not: those destinations are
 * ours, enumerated in `giftcoves.linkable_pages`, and identical in every
 * market, so an allowlist per article would be the same list every time.
 *
 * Anything outside the allowlist is **stripped back to its label**, not
 * rendered as a link and not left as a visible token. A hallucinated brand
 * therefore degrades to plain text — the sentence still reads, and no reader or
 * crawler is sent anywhere that does not exist.
 *
 * That is the whole safety property: the model chooses *emphasis*, we choose
 * *destinations*.
 *
 * ## The one token that leaves the site
 *
 * `amazon` is the exception to every sentence above, and the differences are
 * the point.
 *
 * Our articles talk about Amazon constantly — it is the shop the reader was
 * going to check next anyway — and every one of those mentions was flat text
 * while the search page beside it carried a tagged hand-off. So the token
 * resolves through {@see AmazonSearchLink}, which already owns the two facts
 * that matter: which storefront a market belongs to, and the Associates tag
 * issued for it.
 *
 * **Its allowlist is the tag table, so it enforces "only where we are paid"
 * by construction.** A market with no tag — `en` and `es` — gets no link at
 * all, and the sentence degrades to plain text like any rejected token. That
 * is deliberate and it is the reason this does not read the market's tag
 * itself: sending a reader to a storefront under nobody's tag, or under the
 * wrong marketplace's, is unattributed traffic that looks exactly like working
 * traffic. See the note in `config/giftcoves.php`.
 *
 * **It is marked `sponsored`.** An affiliate link that Google cannot tell from
 * an editorial one is the kind of thing that costs a site its rankings, and
 * `nofollow` alone no longer says what this is. `target="_blank"` because the
 * reader is being sent to check a price, not to leave.
 */
class CoveMarkup
{
    /** `[[kind:value]]` or `[[kind:value|label]]` */
    private const TOKEN = '/\[\[(brand|search|product|guide|page|amazon):([^\]|]{1,120})(?:\|([^\]]{1,160}))?\]\]/u';

    /**
     * `**emphasis**`, the one piece of Markdown this renderer honours.
     *
     * Not a Markdown parser, and deliberately not the first step towards one.
     * The models write bold and only bold — a sentence they want the reader to
     * take away from a section of advice — and it reached the page as literal
     * asterisks, because prose here is escaped and then walked for link tokens
     * and nothing else. Two asterisks were the whole gap.
     *
     * `#`, `_`, `[]()` and the rest stay unhandled on purpose. Every one of
     * them is a syntax a feed's product title can contain by accident, and a
     * renderer that grows a rule per character ends up interpreting markup we
     * did not write. Bold is the one that was actually being produced.
     *
     * The pair must wrap something (`(?=\S)` … `(?<=\S)`), so `5 ** 2` and a
     * stray asterisk are left alone, and the match is non-greedy so two bold
     * runs in a paragraph do not merge into one.
     */
    private const BOLD = '/\*\*(?=\S)(.+?)(?<=\S)\*\*/us';

    /**
     * Injected rather than resolved inside `render()`.
     *
     * Reaching for the container mid-render made a **database query a hidden
     * dependency of turning a string into HTML** — which is not obvious from
     * either the signature or the name, and it turned the unit test for this
     * class into one that only passed when some earlier test happened to leave
     * a `brand_stats` table behind. It errored on 32 cases in a full run and
     * passed 11 of 11 on its own, which is the least useful pair of results a
     * test can produce.
     *
     * A constructor parameter says the dependency out loud and lets a caller
     * that already knows its brand URLs supply them.
     */
    public function __construct(private readonly BrandLinker $brands) {}

    /**
     * @param  array{brands?: list<string>, searches?: list<string>, products?: array<int, array{slug: string, title: string}>, guides?: list<string>}  $allowed
     * @return array{html: string, links: int, rejected: list<string>}
     */
    public function render(string $text, Market $market, array $allowed): array
    {
        $base = '/'.$market->value;
        $links = 0;
        $rejected = [];

        // One query for every brand the article is allowed to mention, resolved
        // before the walk rather than inside it — the callback runs once per
        // token and would otherwise be an N+1 on a page of prose.
        $brandUrls = $this->brands->urls($allowed['brands'] ?? [], $market);

        // Escape first, resolve second. The prose is model output and is
        // rendered as HTML, so anything that arrives already looking like
        // markup must stop being markup before we add our own.
        $escaped = e($text);

        $html = preg_replace_callback(
            self::TOKEN,
            function (array $m) use ($allowed, $base, $brandUrls, $market, &$links, &$rejected): string {
                $kind = $m[1];
                // The token survived escaping, so its contents are escaped too.
                $value = html_entity_decode($m[2], ENT_QUOTES);
                $label = isset($m[3])
                    ? html_entity_decode($m[3], ENT_QUOTES)
                    : $this->fallbackLabel($kind, $value, $allowed);

                $href = match ($kind) {
                    'brand' => $this->brand($value, $allowed['brands'] ?? [], $base, $brandUrls),
                    'search' => $this->search($value, $allowed['searches'] ?? [], $base),
                    'product' => $this->product($value, $allowed['products'] ?? [], $base),
                    'guide' => $this->guide($value, $allowed['guides'] ?? [], $base),
                    'page' => $this->page($value, $base),
                    'amazon' => $this->amazon($value, $market),
                    default => null,
                };

                if ($href === null) {
                    $rejected[] = "{$kind}:{$value}";

                    // Plain text, not a broken link and not a visible token.
                    return e($label);
                }

                $links++;

                /*
                 * The only destination here that is not ours, and the only one
                 * we are paid for. Both facts have to be in the markup:
                 * `sponsored` because it is an affiliate link, and a new tab
                 * because it is a price check rather than an exit.
                 */
                if ($kind === 'amazon') {
                    return sprintf(
                        '<a href="%s" rel="sponsored nofollow noopener" target="_blank">%s</a>',
                        e($href),
                        e($label),
                    );
                }

                return sprintf('<a href="%s">%s</a>', e($href), e($label));
            },
            $escaped,
        ) ?? $escaped;

        /*
         * Emphasis last, after the tokens have become anchors.
         *
         * Running it on the escaped text first would work for the common case
         * and break the one that matters: a bold run wrapping a link is the
         * shape a writer actually produces, and resolving the token afterwards
         * would have to walk text that already contains our own `<strong>`.
         * Doing it in this order, the only markup present when this runs is
         * markup this method emitted, and the pattern cannot reach inside an
         * `href` — every URL here has been through `e()` and holds no
         * asterisks.
         */
        $html = preg_replace(self::BOLD, '<strong>$1</strong>', $html) ?? $html;

        return ['html' => $html, 'links' => $links, 'rejected' => $rejected];
    }

    /**
     * The same text with its tokens reduced to their labels, and no links.
     *
     * For the places that take text and are not HTML: a `<meta>` description, a
     * FAQPage answer in JSON-LD, an email, a card blurb in a listing. Every one
     * of those would otherwise print `[[page:search]]` at a reader — or worse,
     * at a crawler reading the structured data literally.
     *
     * Not escaped, deliberately: the callers here are handing the string to
     * something that will escape it (Inertia, json_encode, a Blade `{{ }}`), and
     * escaping twice turns an apostrophe into `&#039;` on the page.
     *
     * `$allowed` is optional because most callers pass a blurb or an FAQ answer,
     * where a product token is not expected. Pass it wherever the text can name
     * a product, or an unlabelled one falls back to its id here as it used to.
     *
     * @param  array{brands?: list<string>, searches?: list<string>, products?: array<int, array{slug: string, title: string}>, guides?: list<string>}  $allowed
     */
    public function plain(?string $text, array $allowed = []): string
    {
        if ($text === null) {
            return '';
        }

        $text = (string) preg_replace_callback(
            self::TOKEN,
            // The label if one was given, the same fallback render() uses
            // otherwise — which for a product means its title, never its id.
            fn (array $m): string => $m[3] ?? $this->fallbackLabel($m[1], $m[2], $allowed),
            $text,
        );

        // Emphasis has no meaning in any of these destinations, and asterisks
        // in a `<meta>` description or a FAQPage answer are read literally by
        // the one audience that cannot ask what they were for.
        return (string) preg_replace(self::BOLD, '$1', $text);
    }

    /**
     * Paragraphs, with tokens resolved.
     *
     * @param  array{brands?: list<string>, searches?: list<string>, products?: array<int, array{slug: string, title: string}>, guides?: list<string>}  $allowed
     * @return array{html: list<string>, links: int, rejected: list<string>}
     */
    public function paragraphs(string $text, Market $market, array $allowed): array
    {
        $out = [];
        $links = 0;
        $rejected = [];

        foreach (preg_split('/\R{2,}/u', trim($text)) ?: [] as $paragraph) {
            if (trim($paragraph) === '') {
                continue;
            }

            $result = $this->render($paragraph, $market, $allowed);
            $out[] = $result['html'];
            $links += $result['links'];
            $rejected = [...$rejected, ...$result['rejected']];
        }

        return ['html' => $out, 'links' => $links, 'rejected' => $rejected];
    }

    /**
     * @param  list<string>  $brands
     * @param  array<string, string>  $brandUrls  lowered brand => brand page URL
     */
    private function brand(string $value, array $brands, string $base, array $brandUrls = []): ?string
    {
        // Case-insensitive, because the model will not reproduce a feed's
        // capitalisation and "sony" meaning Sony is not a hallucination.
        foreach ($brands as $brand) {
            if (mb_strtolower($brand) === mb_strtolower(trim($value))) {
                /*
                 * A brand page where one exists, a filtered search otherwise.
                 *
                 * This is the difference between a Cove being an SEO asset and a
                 * Cove being a dead end. `?brand[]=Sony` is `noindex` — facet
                 * URLs are a crawl-budget trap — so every brand mention in every
                 * generated article was a link a crawler followed and then
                 * declined to index. `/brand/sony` is the version worth linking
                 * to, and it links back here.
                 *
                 * The fallback stays because a brand can be in the allowlist and
                 * still have too few products for a page of its own, and a
                 * sentence whose link silently disappears reads worse than one
                 * pointing at a filtered search.
                 */
                return $brandUrls[mb_strtolower($brand)]
                    ?? $base.'/search?'.http_build_query(['brand' => [$brand]]);
            }
        }

        return null;
    }

    /** @param list<string> $searches */
    private function search(string $value, array $searches, string $base): ?string
    {
        $needle = mb_strtolower(trim($value));

        foreach ($searches as $search) {
            if (mb_strtolower($search) === $needle) {
                return $base.'/search?'.http_build_query(['q' => $search]);
            }
        }

        return null;
    }

    /** @param array<int, array{slug: string, title: string}> $products */
    private function product(string $value, array $products, string $base): ?string
    {
        $id = (int) trim($value);

        return isset($products[$id])
            ? $base.'/p/'.$id.'/'.$products[$id]['slug']
            : null;
    }

    /**
     * The anchor text for a token that came without one.
     *
     * For every kind but `product` the value already *is* the words — a brand
     * is its name, a search is its phrase — so echoing it is right. A product
     * is addressed by **id**, and echoing that put a bare number in the middle
     * of a sentence: three published editions read "the 6609172 is built for
     * the version on wheels". Rendered, escaped, linked and completely correct
     * by the old rule, which is why nothing caught it. Found 2026-09-01.
     *
     * The product's own title instead. It is deliberately not shortened: a feed
     * title runs long and reads like a spec sheet, so a missing label is
     * obvious to whoever reads the page rather than quietly acceptable. The
     * label is what should be there — see `promptContract()`, which now asks
     * the writer for one in as many words — and this is the floor under it, not
     * a substitute for it.
     *
     * An id that is not on the allowlist keeps falling through to the id
     * itself: the token is about to be rejected and rendered as plain text
     * anyway, and inventing a name for a product this page may not link to
     * would be worse than showing the writer their own broken reference.
     *
     * @param  array{brands?: list<string>, searches?: list<string>, products?: array<int, array{slug: string, title: string}>, guides?: list<string>}  $allowed
     */
    private function fallbackLabel(string $kind, string $value, array $allowed): string
    {
        if ($kind !== 'product') {
            return $value;
        }

        $id = (int) trim($value);

        return $allowed['products'][$id]['title'] ?? $value;
    }

    /**
     * Another guide on this site.
     *
     * Allowlisted by slug, and the caller is expected to have resolved that
     * list from *published* guides in this market. Both halves matter: a link
     * to a draft is a 404 for a reader and an indexed dead end for a crawler,
     * and a slug that exists in `be-nl` need not exist in `es`.
     *
     * This is the token that turns a pile of articles into a site — internal
     * links between them are how a reader gets from a tip to the thing it is
     * about, and how a crawler discovers the archive at all.
     *
     * @param  list<string>  $guides
     */
    private function guide(string $value, array $guides, string $base): ?string
    {
        $slug = mb_strtolower(trim($value));

        foreach ($guides as $candidate) {
            if (mb_strtolower($candidate) === $slug) {
                return $base.'/guides/'.$candidate;
            }
        }

        return null;
    }

    /**
     * One of our own pages.
     *
     * No per-article allowlist: the set is the same in every market and is
     * enumerated in config rather than coming from a feed, so the config *is*
     * the allowlist. An unknown key still degrades to plain text — a writer
     * inventing `[[page:deals]]` gets an unlinked phrase, not a 404.
     */
    private function page(string $value, string $base): ?string
    {
        $pages = (array) config('giftcoves.linkable_pages');
        $key = mb_strtolower(trim($value));

        if (! array_key_exists($key, $pages)) {
            return null;
        }

        $path = trim((string) $pages[$key], '/');

        // The market home page is the base with nothing after it, and
        // `/be-nl/` with a trailing slash is a different URL to `/be-nl`.
        return $path === '' ? $base : $base.'/'.$path;
    }

    /**
     * Amazon's own storefront for this market, searched for this term, tagged.
     *
     * Not allowlisted per article, for the same reason `page` is not: the set
     * of valid destinations is config, not content. But where `page` fails open
     * into our own site, this one fails *closed* — {@see AmazonSearchLink::for}
     * returns null for a market with no Associates tag, and null here means the
     * phrase renders as plain text.
     *
     * That is what implements "Amazon links in `nl-nl`, `be-nl` and `be-fr`
     * only" without a market list in this file, in the content, or in anybody's
     * memory. Issue a tag for a fourth market and its articles start linking;
     * revoke one and they stop. The alternative — writing the rule into each
     * article — would mean the English edition of a piece silently carrying a
     * Dutch tag, which earns nothing and is invisible when it happens.
     *
     * The term is the search, so an article writes what the reader would type:
     * `[[amazon:draadloze koptelefoon|zoek op Amazon]]`. Deep links to an ASIN
     * are deliberately not offered — invariant 6 is that we do not mirror
     * Amazon's catalogue, and a hand-written ASIN in an article is a product
     * claim with no price, no stock and nothing to re-check it.
     */
    private function amazon(string $value, Market $market): ?string
    {
        return AmazonSearchLink::for($market, $value)?->url;
    }

    /**
     * The instruction block handed to the model.
     *
     * Kept next to the parser on purpose: a prompt that describes a syntax the
     * renderer does not implement is the most common way this kind of feature
     * rots, and the two drifting apart is silent — the tokens simply stop
     * becoming links.
     *
     * **`amazon` is deliberately absent, and that direction of drift is safe.**
     * A prompt offering a token the parser lacks produces dead syntax; a parser
     * accepting one the prompt never mentions produces nothing at all unless a
     * person writes it. And a person is exactly who should: that token is a
     * paid link out of the site, and "the writer decides where the commercial
     * hand-offs go" is not a judgement to hand to a model that is also being
     * asked to sound helpful. Authored Coves use it; generated ones do not.
     *
     * @param  array{brands?: list<string>, searches?: list<string>, products?: array<int, array{slug: string, title: string}>, guides?: list<string>}  $allowed
     */
    public function promptContract(array $allowed): string
    {
        $products = [];

        foreach (array_slice($allowed['products'] ?? [], 0, 20, true) as $id => $product) {
            $products[] = "{$id} = {$product['title']}";
        }

        return implode("\n", array_filter([
            'Link by writing tokens. Never write a URL, a markdown link or an HTML tag.',
            // Stated here rather than in the editable voice prompt for the same
            // reason the token syntax is: it is a fact about the renderer. The
            // one Markdown construct it understands, said out loud so a writer
            // does not reach for `#`, `_` or a list and get literal characters.
            'Markdown: **bold** renders. Nothing else does - no headings, no lists, no italics.',
            '  [[brand:NAME]]        [[search:PHRASE]]        [[product:ID|label]]',
            '  [[guide:SLUG]]        [[page:KEY]]',
            '',
            /*
             * The label on a product token is not optional, and this says so in
             * its own line because the syntax line above did not say it loudly
             * enough: three published editions wrote `[[product:6609172]]` and
             * read "the 6609172 is built for the version on wheels".
             *
             * Phrased as "name it in your own words" rather than "copy the
             * title" on purpose. The list below carries feed titles — "Draadloze
             * Bluetooth Koptelefoon met ruisonderdrukking, zwart, 40u" — and a
             * sentence built around one of those reads like a spec sheet. The
             * few words a person would actually use is the thing being asked
             * for, and it is the only part of a link that a reader sees.
             */
            'A product token MUST carry a label: [[product:1234|the lockable diary]].',
            'Name the product in your own words - the two or three a person would say out',
            'loud, fitting the sentence around it. Never write [[product:1234]] on its own:',
            'the id is an address, not a name, and it renders as a number in your sentence.',
            '',
            'You may ONLY use these. Anything else is deleted:',
            'Brands: '.implode(', ', array_slice($allowed['brands'] ?? [], 0, 40)),
            'Searches: '.implode(', ', array_slice($allowed['searches'] ?? [], 0, 40)),
            $products === [] ? null : 'Products: '.implode(' | ', $products),
            ($allowed['guides'] ?? []) === []
                ? null
                : 'Guides: '.implode(', ', array_slice($allowed['guides'], 0, 40)),
            // Not sliced and not conditional: the page list is short, fixed and
            // the same everywhere, and it is the one set a writer can rely on
            // without being told what today's article happens to contain.
            'Pages: '.implode(', ', array_keys((array) config('giftcoves.linkable_pages'))),
        ]));
    }

    /** Convenience for a controller that already has the current market. */
    public function forCurrent(string $text, CurrentMarket $current, array $allowed): array
    {
        return $this->paragraphs($text, $current->get(), $allowed);
    }
}
