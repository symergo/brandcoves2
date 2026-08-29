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
 * `/gift-ideas/{slug}`, an article at `/guides/{slug}`.
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
 * `ORDER BY drop_date DESC` NULLS FIRST, and four of these five kinds are
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
     */
    public function path(string $address): string
    {
        return match ($this) {
            self::Daily => 'daily/'.$address,
            self::Persona => 'gift-ideas/'.$address,
            self::Guide, self::Seasonal, self::Advice => 'guides/'.$address,
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
            self::Advice => 0,
        };
    }

    /** How many products the builder aims to put on the page. */
    public function targetItems(): int
    {
        return match ($this) {
            self::Daily, self::Persona => (int) config('giftcoves.picks.per_day'),
            self::Guide, self::Seasonal => (int) config('giftcoves.guides.items_per_guide'),
            self::Advice => 0,
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
        return $this->isArticle() ? 'guide_copy' : 'daily_picks';
    }

    public function label(): string
    {
        return match ($this) {
            self::Daily => 'Daily Cove',
            self::Persona => 'Gift persona',
            self::Guide => 'Buying guide',
            self::Seasonal => 'Seasonal guide',
            self::Advice => 'Advice article',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $k) => $k->value, self::cases());
    }
}
