<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * What sort of Cove this is — and, because of that, how it is addressed, how
 * many products it needs, and which budget its prose is written from.
 *
 * Every kind is planned the same way. A person decides what the page is for,
 * curates a shortlist with a note against each product, briefs the writer, and
 * approves. One planner, one curation screen, one builder. What differs between
 * the kinds is small enough to live in this enum, and putting it here is what
 * stops it from living as a `match` in nine other files.
 *
 * Three things differ, and only three:
 *
 * **The address.** A Daily is one morning's edition and is reached by its date.
 * Everything else is permanent and is reached by a slug — a persona at
 * `/gift-ideas/{slug}`, an article at `/guides/{slug}`. A seasonal *plan* also
 * carries a date, and it is a due date rather than an address: see
 * `isDated()`.
 *
 * **The shape of the page.** A Daily or a persona is a column: prose with
 * products inside it. A guide is a ranked shortlist and an argument about it. An
 * advice article has no shortlist at all — the prose *is* the substance, which
 * is why it is the one kind that may publish with no products.
 *
 * **The budget.** Column prose and guide prose are written by different prompts
 * against different daily caps, both registered in `config/giftcoves.ai.caps`.
 *
 * Everything downstream that means "the daily column" therefore has to say so.
 * See `DailyPickSet::scopeDaily()` and the migration that made `drop_date`
 * nullable for what goes wrong when it does not — Postgres sorts
 * `ORDER BY drop_date DESC` NULLS FIRST, and five of these six kinds are
 * dateless.
 */
enum CoveKind: string
{
    /** One morning's edition, addressed by its date. */
    case Daily = 'daily';

    /**
     * A gift persona — "the cottagecore herbalist", "the dad who has
     * everything". Undated, permanent, and listed on the gift-ideas page.
     */
    case Persona = 'persona';

    /**
     * A buying guide: a ranked shortlist, "the five best X and the one actually
     * worth it". Its substance is the products and the prose is presentation,
     * which is why it refuses to publish without enough of them.
     */
    case Guide = 'guide';

    /**
     * A guide whose demand has a window — Halloween, Black Friday, Mother's Day.
     *
     * The same page as a buying guide in every respect except that it carries a
     * season, because the search log cannot know about a season before the
     * season arrives: barbecue searches peak in June, so a miner reading June's
     * log commissions the barbecue guide in July.
     *
     * A season is published as a **series**: one part per subject the calendar
     * names it with, each due on a date inside the window. That is why this is
     * the one non-Daily kind whose plans may hold a `drop_date`. See
     * docs/features/seasonal-series.md.
     */
    case Seasonal = 'seasonal';

    /**
     * An article with no shortlist. "How to tell a real review from a paid one",
     * "what a good returns policy looks like".
     *
     * Demanding products would either block it or pad it with things the writing
     * is not about, so it is the only kind whose minimum is zero.
     */
    case Advice = 'advice';

    /**
     * A piece about a **shop** rather than about a thing to buy.
     *
     * "How Coolblue's returns actually work", "which Belgian shops deliver on a
     * Sunday", "what bol.com's Plus subscription is for". Every offer on this
     * site names the shop it came from and nothing here ever said a word about
     * those shops — which is the half of a buying decision that a price
     * comparison cannot answer.
     *
     * Prose, like Advice: its substance is the writing, so it publishes with no
     * products. Unlike Advice it does **not** live in the `/guides` space — it
     * is read at `/shops/{slug}`, above the directory of shops it is about, and
     * `isArticle()` stays false for exactly that reason.
     */
    case Shop = 'shop';

    /**
     * A piece about a **brand**, read above that brand's own page.
     *
     * The same shape as a Shop Cove — prose about an entity, then that entity's
     * products — and the two were broken in opposite directions. The shop page
     * was a real Cove with authored prose and no products; the brand page had
     * the products and nowhere to put bespoke prose, because its copy came from
     * `copy_templates` slots that read the same for every brand.
     *
     * It renders at `/{market}/brand/{slug}`, above the grid that is already
     * there. `docs/features/brand-pages.md` exists to argue for one canonical
     * indexable URL per brand per market — every brand mention on the site
     * points at it — so a second address would split the link equity that page
     * was built to consolidate.
     *
     * Prose, like Advice and Shop: its substance is the writing. The prose is
     * about **sub-brands and product categories**, never individual products, so
     * an entity Cove carries no shortlist at all — nothing to freeze, nothing to
     * go stale, and no floor it can fail. The products come from live rails
     * underneath. See docs/features/cove-entities.md.
     */
    case Brand = 'brand';

    /**
     * Is this kind **addressed** by its date?
     *
     * Only the Daily, and the word matters now that it is not the only kind that
     * can hold one. A seasonal plan carries a `drop_date` too — the day that
     * part of the season is *due* to be built — while the page it produces is
     * still slug-addressed and evergreen like every other article. So this
     * answers "is the date where the page lives", which is the question every
     * caller is actually asking, and the answer is still Daily alone. See
     * `App\Services\Cove\SeasonalSeries` and docs/features/seasonal-series.md.
     */
    public function isDated(): bool
    {
        return $this === self::Daily;
    }

    /**
     * Does this kind live in the `/guides` URL space?
     *
     * Asked rather than listed, because "guide, seasonal or advice" appears in
     * the controller, the sitemap, the hreflang pairing, the allowlist and two
     * admin screens, and a list repeated six times is a list that will be five
     * places out of date.
     *
     * **Shop is prose and is still not an article here.** This method answers a
     * question about *URL space*, not about page shape: a Shop Cove is read at
     * `/shops/{slug}`, so answering true would sweep it into the `/guides`
     * index, the guides sitemap and the guides hreflang pairing. Page shape is
     * `expectsShortlist()`.
     */
    public function isArticle(): bool
    {
        return in_array($this, [self::Guide, self::Seasonal, self::Advice], true);
    }

    /**
     * The path under `/{market}/` where a Cove of this kind is read.
     *
     * `$address` is whatever addresses it: a `Y-m-d` date for a Daily, a slug for
     * everything else. The enum takes the string rather than the model so that
     * nothing in `app/Enums` has to know about Eloquent.
     *
     * `$market` is required rather than optional because the Daily segment is
     * localised — `cadeau-van-de-dag`, `cadeau-du-jour` — and a default would
     * mean one market's word silently appearing in another's URL. Every caller
     * already holds the market; making them say so is cheaper than the bug.
     */
    public function path(string $address, Market $market): string
    {
        return match ($this) {
            self::Daily => $market->coveSegment().'/'.$address,
            self::Persona => 'gift-ideas/'.$address,
            self::Guide, self::Seasonal, self::Advice => 'guides/'.$address,
            self::Shop => 'shops/'.$address,
            // The page that already exists, not a second one beside it.
            self::Brand => 'brand/'.$address,
        };
    }

    /**
     * Is this a Cove about an **entity** — a shop, or a brand?
     *
     * Both are prose about a thing that sells or makes products, followed by
     * live rails of that thing's products. What separates them from every other
     * kind is that they carry **no shortlist**: the prose is about sub-brands
     * and categories rather than individual items, so there is nothing to freeze
     * at build time and nothing that can go stale.
     *
     * Their slug is also not ours to choose — it names a real merchant domain or
     * a real brand — which is the one validation rule no other kind has.
     */
    public function isEntity(): bool
    {
        return in_array($this, [self::Shop, self::Brand], true);
    }

    /**
     * Does this kind's page carry a long-form `body`, written as an article?
     *
     * The build question, and deliberately not `isArticle()`, which asks whether
     * a kind lives in the `/guides` URL space. A Shop Cove answers **false**
     * there on purpose — it is read at `/shops/{slug}` and must stay out of the
     * guides index, sitemap and hreflang pairing — and it is nonetheless written
     * exactly like an advice article, by the same writer, into the same column,
     * against the same budget.
     *
     * Asking the URL-space question at the top of `buildArticle()` is what left
     * `CoveKind::Shop` with no build path at all: `BuildCove` fell through to the
     * Daily arm, found no `drop_date`, and returned null. A Shop plan could be
     * planned, curated and approved, and then quietly did nothing — while
     * `Defaults::SHOP_SYSTEM` sat in the prompt bank with no caller.
     */
    public function writesBody(): bool
    {
        return in_array($this, [self::Guide, self::Seasonal, self::Advice, self::Shop, self::Brand], true);
    }

    /**
     * Does the page expect a ranked shortlist under the prose?
     *
     * The one thing the React page branches on. An advice article or a Shop
     * Cove rendering an empty `<ol>` reads as a broken buying guide rather than
     * as a finished piece of writing — and a seasonal Cove answers **true**,
     * because a season is a scheduling fact and not a layout one.
     */
    public function expectsShortlist(): bool
    {
        return match ($this) {
            // An entity Cove's products are a live rail below the prose, not a
            // ranked shortlist the prose argues about.
            self::Advice, self::Shop, self::Brand => false,
            default => true,
        };
    }

    /**
     * Below this many products the page does not publish at all.
     *
     * A thin Daily is a quiet morning and is skipped. A buying guide with three
     * entries is not a shortlist, it is a list with gaps, and it reads as one.
     * An advice article is exempt: its substance is the prose.
     */
    public function minimumItems(): int
    {
        return match ($this) {
            self::Daily, self::Persona => (int) config('giftcoves.picks.minimum'),
            self::Guide, self::Seasonal => (int) config('giftcoves.guides.min_products'),
            self::Advice, self::Shop, self::Brand => 0,
        };
    }

    /** How many products the builder aims to put on the page. */
    public function targetItems(): int
    {
        return match ($this) {
            self::Daily, self::Persona => (int) config('giftcoves.picks.per_day'),
            self::Guide, self::Seasonal => (int) config('giftcoves.guides.items_per_guide'),
            self::Advice, self::Shop, self::Brand => 0,
        };
    }

    /**
     * The AI cap this kind's prose is written against.
     *
     * Every AI caller registers a feature key in `config/giftcoves.php` and is
     * capped per day — invariant 1. Column prose and guide prose are separate
     * budgets because they fail differently: a day with no editorial is a quiet
     * Cove, a month with no guides is a dead section.
     */
    public function aiFeature(): string
    {
        /*
         * Not `isArticle()`. That asks about the /guides URL space and a Shop
         * Cove is not in it — but it is prose written by the same prompt shape
         * against the same budget, and billing it to `daily_picks` would spend
         * the column's daily cap on something that is not the column.
         */
        return match ($this) {
            self::Daily, self::Persona => 'daily_picks',
            default => 'guide_copy',
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Daily => 'Daily Cove',
            self::Persona => 'Gift persona',
            self::Guide => 'Buying guide',
            self::Seasonal => 'Seasonal guide',
            self::Advice => 'Advice article',
            self::Shop => 'Shop Cove',
            self::Brand => 'Brand Cove',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $k) => $k->value, self::cases());
    }
}
