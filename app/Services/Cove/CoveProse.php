<?php

declare(strict_types=1);

namespace App\Services\Cove;

use App\Enums\CoveKind;
use App\Models\BrandStat;
use App\Models\DailyPick;
use App\Models\DailyPickSet;
use App\Services\Editorial\Allowlist;
use App\Services\Editorial\ProseCards;
use App\Services\Guides\CoveMarkup;
use App\Services\Shops\ShopDirectory;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * A Cove's prose as the page shows it: link tokens resolved into anchors,
 * paragraphs split, products paired with the paragraph that names them.
 *
 * ## Why this is worked out at build
 *
 * Every Cove page used to do it on every view: build the link allowlist (the
 * 300 largest brands with a page, the 200 newest articles of the market: two
 * queries), look up the brand page addresses (a third), then run the token and
 * paragraph passes over the article, its FAQ and every product's copy. None of
 * that depends on the visitor or on stock. It changes when the text changes,
 * which is when `EditionBuilder` builds, rebuilds, refreshes or redoes the
 * Cove, so the builder calls `store()` and the page reads `for()`.
 *
 * ## When the stored value is not used
 *
 * The stored value carries a fingerprint of everything it was rendered from:
 * the Cove's text fields, its link list, and its picks (id, product, copy).
 * If anything wrote those since (the admin, the editorial API, `bc:tidy-prose`,
 * a product merge re-pointing a pick; several of them write with plain
 * queries, so a model event would miss them), the fingerprint no longer
 * matches and the page renders live instead, caching that for a day under a
 * key that includes the fingerprint. The next build stores it again.
 *
 * Bump `VERSION` whenever what `render()` produces changes shape or markup
 * (`CoveMarkup`, `ProseCards`), or stored Coves keep the old rendering until
 * they are rebuilt.
 *
 * ## What freezes, and what does not
 *
 * Stored at build, a link reflects the site as it was then. A brand page that
 * later disappears still gets its link (to our own brand page, which then
 * answers as it does for any old link), and a product retitled since keeps its
 * old words in an unlabelled token. Accepted: every link here points at our
 * own pages, never at a shop.
 *
 * The other direction would be worse, so it is guarded: a `[[guide:…]]` to an
 * article published *after* this Cove, or a `[[brand:…]]` whose brand has no
 * page yet, renders as plain text today and would stay plain text for good.
 * A render with such a token is not stored; the page renders it live, cached
 * for a day, so the link appears within a day of its target.
 *
 * Nothing here depends on stock or price. The finds, the in-stock filter and
 * every price stay live in the controllers.
 */
final class CoveProse
{
    /** Bump when the rendered output changes; see the class comment. */
    public const VERSION = 1;

    /**
     * A day, for a Cove whose stored prose is missing or out of date.
     *
     * The key carries the fingerprint, so an edit is never served stale; the
     * day only bounds how late a newly published guide or a new brand page
     * shows up as a link.
     */
    private const FALLBACK_TTL = 86400;

    /** Token kinds whose set of valid targets grows after a Cove is built. */
    private const MAY_RESOLVE_LATER = ['guide', 'brand'];

    public function __construct(
        private readonly Allowlist $allowlist,
        private readonly CoveMarkup $markup,
        private readonly EntityLinks $links,
        private readonly ShopDirectory $shops,
    ) {}

    /**
     * What the page renders: the stored prose if it still matches the Cove,
     * otherwise rendered now and cached for a day.
     *
     * Only arrays of strings and ints, so the cached value survives Redis
     * (which is configured to refuse unserialising objects).
     *
     * @return array<string, mixed>
     */
    public function for(DailyPickSet $cove): array
    {
        $print = $this->fingerprint($cove);
        $stored = $cove->rendered_prose;

        if (is_array($stored) && ($stored['print'] ?? null) === $print) {
            return $stored;
        }

        return Cache::remember(
            'bc:cove-prose:'.$cove->id.':'.($cove->updated_at?->getTimestamp() ?? 0).':'.$print,
            self::FALLBACK_TTL,
            fn (): array => $this->render($cove)['prose'],
        );
    }

    /**
     * Render and store, for the builder. Never fails a build: the page can
     * always render the prose itself.
     */
    public function store(DailyPickSet $cove): void
    {
        try {
            // Read back from the database, so the fingerprint is taken from
            // the same values a page will later read (a `jsonb` FAQ comes back
            // with its keys re-sorted) and the picks are the ones just written.
            $fresh = DailyPickSet::query()->with('picks.group')->find($cove->id);

            if ($fresh === null) {
                return;
            }

            $result = $this->render($fresh);
            $value = $result['complete'] ? $result['prose'] : null;

            $fresh->forceFill(['rendered_prose' => $value])->saveQuietly();

            $cove->forceFill(['rendered_prose' => $value])->syncOriginalAttribute('rendered_prose');
        } catch (Throwable $e) {
            Log::warning('Cove prose not stored; the page renders it live', [
                'cove' => $cove->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Everything the rendering reads from the Cove itself, hashed.
     *
     * Not the allowlist's outside half (other guides, other brands): that is
     * the part accepted as frozen, see the class comment.
     */
    public function fingerprint(DailyPickSet $cove): string
    {
        // A brand Cove's links come from the brand, not from picks, and its
        // page loads none; asking would be a query for nothing.
        $picks = $cove->kind === CoveKind::Brand
            ? []
            : $cove->picks
                ->map(fn (DailyPick $pick): array => [$pick->id, $pick->group_id, $pick->blurb])
                ->all();

        return sha1((string) json_encode(self::sorted([
            self::VERSION,
            $cove->market->value,
            $cove->kind->value,
            (string) $cove->slug,
            $cove->theme_blurb,
            $cove->editorial,
            $cove->body,
            $cove->faq,
            $cove->source_queries,
            $cove->link_categories,
            $picks,
        ])));
    }

    /**
     * The prose, rendered live, and whether it may be stored.
     *
     * Each branch is the code its page ran before, moved here unchanged, so a
     * stored render and a live one are the same bytes.
     *
     * @return array{prose: array<string, mixed>, complete: bool}
     */
    public function render(DailyPickSet $cove): array
    {
        $prose = match (true) {
            $cove->kind === CoveKind::Brand => $this->brand($cove),
            $cove->kind === CoveKind::Shop, $cove->kind->isArticle() => $this->article($cove),
            default => $this->edition($cove),
        };

        $rejected = $prose['rejected'];
        unset($prose['rejected']);

        $complete = true;

        foreach ($rejected as $token) {
            if (in_array(strtok($token, ':'), self::MAY_RESOLVE_LATER, true)) {
                $complete = false;
                break;
            }
        }

        return [
            'prose' => ['print' => $this->fingerprint($cove)] + $prose,
            'complete' => $complete,
        ];
    }

    /**
     * A Daily or a persona: the editorial, block by block.
     *
     * @return array{editorial: list<array<string, mixed>>, rejected: list<string>}
     */
    private function edition(DailyPickSet $cove): array
    {
        if (blank($cove->editorial)) {
            return ['editorial' => [], 'rejected' => []];
        }

        $groups = $cove->picks
            ->map(fn (DailyPick $pick) => $pick->group)
            ->filter()
            ->values();

        // This Cove's finds, plus the guides this market has published: a Cove
        // that can point at the guide for the thing it just showed you is the
        // whole reason the two live on one page.
        $allowed = $this->allowlist->full($groups, $cove->market);

        // One document, so a product introduced in the first paragraph does
        // not get a second card further down. See ProseCards for why this is
        // constructed rather than injected.
        $cards = new ProseCards($this->markup, $cove->market, $allowed);
        $editorial = $cards->blocks($cove->editorial);

        return ['editorial' => $editorial, 'rejected' => $cards->rejected()];
    }

    /**
     * A guide, a seasonal Cove, an advice article or a Shop Cove.
     *
     * A Shop Cove renders on the entity page, which wants one intro string and
     * body paragraphs; the others want prose blocks, each product's copy and
     * the FAQ. See GuideController for why each is shaped as it is.
     *
     * @return array<string, mixed>
     */
    private function article(DailyPickSet $cove): array
    {
        $market = $cove->market;
        $isShop = $cove->kind === CoveKind::Shop;

        $shopLinks = $isShop
            ? $this->links->forShopCove($cove, $this->shops->shopFor($market, (string) $cove->slug))
            : [];

        // Its own items, plus every other published guide in this market, plus
        // the queries that justified it and (a Shop Cove) the categories the
        // shop sells in. GuideController::render() says why each is there.
        $allowed = $this->allowlist->full(
            $cove->picks->map(fn (DailyPick $pick) => $pick->group)->filter(),
            $market,
            excludeGuideId: $cove->id,
            extraSearches: [
                ...(array) $cove->source_queries,
                ...$shopLinks,
            ],
        );

        $plain = [
            'blurb' => $this->markup->plain($cove->theme_blurb),
            // For the FAQPage JSON-LD, which a crawler reads literally.
            'faq' => is_array($cove->faq) && $cove->faq !== []
                ? array_values(array_map(fn (array $pair) => [
                    'q' => $this->markup->plain($pair['q'] ?? ''),
                    'a' => $this->markup->plain($pair['a'] ?? ''),
                ], $cove->faq))
                : [],
        ];

        if ($isShop) {
            $intro = $this->markup->render((string) $cove->theme_blurb, $market, $allowed);
            $body = $this->markup->paragraphs((string) $cove->body, $market, $allowed);

            return [
                'entity' => ['intro' => $intro['html'], 'body' => $body['html']],
                'plain' => $plain,
                'rejected' => [...$intro['rejected'], ...$body['rejected']],
            ];
        }

        // Intro and body from one document: a product introduced in the intro
        // gets no second card halfway down the article.
        $cards = new ProseCards($this->markup, $market, $allowed);
        $intro = $cards->blocks($cove->theme_blurb);
        $body = $cards->blocks($cove->body);
        $rejected = $cards->rejected();

        // Each product's copy, by pick id. Only picks with a catalogue product:
        // the page drops the others (see GuideController).
        $copy = [];

        foreach ($cove->picks as $pick) {
            if ($pick->group === null) {
                continue;
            }

            $paragraphs = $this->paragraphs($pick->blurb, $cove, $allowed);
            $copy[$pick->id] = $paragraphs['html'];
            $rejected = [...$rejected, ...$paragraphs['rejected']];
        }

        $faq = null;

        if (is_array($cove->faq) && $cove->faq !== []) {
            $faq = [];

            // Questions stay plain; the answer is where a link belongs.
            foreach ($cove->faq as $pair) {
                $answer = $this->paragraphs((string) ($pair['a'] ?? ''), $cove, $allowed);
                $faq[] = ['q' => (string) ($pair['q'] ?? ''), 'a' => $answer['html']];
                $rejected = [...$rejected, ...$answer['rejected']];
            }
        }

        return [
            'intro' => $intro,
            'body' => $body,
            'copy' => $copy,
            'faq' => $faq,
            'plain' => $plain,
            'rejected' => $rejected,
        ];
    }

    /**
     * A Brand Cove, a section of the brand page.
     *
     * No products in the allowlist, on purpose: an entity Cove's prose is about
     * ranges, and the products under it are live rails (see BrandController).
     *
     * @return array<string, mixed>
     */
    private function brand(DailyPickSet $cove): array
    {
        $market = $cove->market;
        $stat = BrandStat::query()->forMarket($market)->where('slug', $cove->slug)->first();

        // The categories this brand sells in: stored at build, or worked out
        // once a day. A brand that has since lost its row keeps whatever list
        // was stored, which is the list its prose was written against.
        $links = $stat === null
            ? array_values(array_map('strval', (array) $cove->link_categories))
            : $this->links->forBrandCove($cove, $stat);

        $allowed = $this->allowlist->full(
            collect(),
            $market,
            excludeGuideId: $cove->id,
            extraSearches: $links,
        );

        $intro = $this->markup->render((string) $cove->theme_blurb, $market, $allowed);
        $body = $this->markup->paragraphs((string) $cove->body, $market, $allowed);

        return [
            'entity' => ['intro' => $intro['html'], 'body' => $body['html']],
            'plain' => ['blurb' => $this->markup->plain((string) $cove->theme_blurb), 'faq' => []],
            'rejected' => [...$intro['rejected'], ...$body['rejected']],
        ];
    }

    /**
     * A block of copy as paragraphs of safe HTML, with its links resolved.
     *
     * @param  array<string, mixed>  $allowed
     * @return array{html: list<string>, rejected: list<string>}
     */
    private function paragraphs(?string $text, DailyPickSet $cove, array $allowed): array
    {
        if (blank($text)) {
            return ['html' => [], 'rejected' => []];
        }

        $result = $this->markup->paragraphs((string) $text, $cove->market, $allowed);

        return ['html' => $result['html'], 'rejected' => $result['rejected']];
    }

    /** Objects with their keys sorted, so the hash does not depend on key order. */
    private static function sorted(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (! array_is_list($value)) {
            ksort($value);
        }

        return array_map(self::sorted(...), $value);
    }
}
