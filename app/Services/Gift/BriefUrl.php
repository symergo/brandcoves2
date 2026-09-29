<?php

declare(strict_types=1);

namespace App\Services\Gift;

use App\Enums\Interest;
use App\Enums\Market;
use App\Enums\RecipientType;

/**
 * A gift brief as an address, and an address back as a brief.
 *
 * `/be-nl/gift-ideas/for/papa/koken` is "a present for a father who loves
 * cooking" in words a person reads and a search engine indexes. Roadmap step
 * 4, part 1 (docs/features/gift-landing-pages.md).
 *
 * ## What goes in the path, and what does not
 *
 * Only the two things a page is about: who it is for and what they love.
 * They are the words people search with ("cadeau voor papa die van koken
 * houdt"), and they are closed vocabularies (`RecipientType`, `Interest`), so
 * every path this can produce is one a page can answer. The budget is a query
 * parameter (`?budget=50-100`, in euros): it narrows the same page rather than
 * making a new one, and the page's canonical drops it. Everything else in a
 * brief (taste, age, things to avoid) is Find a gift's job, not an address.
 *
 * ## Why `/gift-ideas/for/` in every market
 *
 * The section word stays `gift-ideas` everywhere, like the persona shelf it
 * sits under: the strategy keeps URLs as they are (docs/strategy.md, "Naming
 * and SEO"), and a localised section word would mean a second route and a
 * redirect table for one word. The `for` segment is what keeps these clear of
 * the persona slugs at `/gift-ideas/{slug}`: a persona address has one
 * segment after `gift-ideas`, a landing page two or three.
 *
 * The recipient and interest words are the market's own language, from
 * `lang/{language}/site.php` (`gift_landing.recipients.*.slug`,
 * `gift_landing.interests.*.slug`), the way `SearchUrl` localises its word:
 * the Dutch reader types "papa", not "father".
 */
final class BriefUrl
{
    public const SECTION = 'gift-ideas/for';

    /**
     * The budget bands a landing page offers, in cents. Four, because a
     * giver thinks in round amounts and a row of eight buttons is a form.
     *
     * @var list<array{0: int|null, 1: int|null}>
     */
    public const BUDGETS = [
        [null, 2500],
        [2500, 5000],
        [5000, 10000],
        [10000, null],
    ];

    /** @var array<string, array<string, array<string, string>>> language => kind => value => slug */
    private static array $slugs = [];

    public static function recipientSlug(Market $market, RecipientType $recipient): string
    {
        return self::slugs($market->language(), 'recipients')[$recipient->value] ?? $recipient->value;
    }

    public static function interestSlug(Market $market, Interest $interest): string
    {
        return self::slugs($market->language(), 'interests')[$interest->value] ?? $interest->value;
    }

    /**
     * The recipient a path word names.
     *
     * The market's own word first. Any other language's word is accepted too,
     * so a hand-built `/be-fr/gift-ideas/for/papa/koken` lands rather than
     * 404s; the controller then redirects it to the French address.
     */
    public static function recipient(Market $market, string $slug): ?RecipientType
    {
        $value = self::lookup($market, 'recipients', $slug);

        return $value === null ? null : RecipientType::tryFrom($value);
    }

    public static function interest(Market $market, string $slug): ?Interest
    {
        $value = self::lookup($market, 'interests', $slug);

        return $value === null ? null : Interest::tryFrom($value);
    }

    /**
     * The path of a landing page, without the host.
     *
     * @param  array{0: int|null, 1: int|null}|null  $budget  cents
     */
    public static function path(Market $market, RecipientType $recipient, ?Interest $interest = null, ?array $budget = null): string
    {
        $path = '/'.$market->value.'/'.self::SECTION.'/'.self::recipientSlug($market, $recipient);

        if ($interest !== null) {
            $path .= '/'.self::interestSlug($market, $interest);
        }

        $param = $budget === null ? null : self::budgetParam($budget[0], $budget[1]);

        return $param === null ? $path : $path.'?'.http_build_query(['budget' => $param]);
    }

    /**
     * The landing page a brief would be, if it can be one.
     *
     * Needs a recipient the vocabulary knows; the first interest the
     * vocabulary knows goes in the path, and the budget in the query. Null
     * when there is no recipient: "gift ideas for cooking" is a search, not a
     * page about a person. Whether the page *exists* is `GiftLanding`'s
     * question, not this one's.
     */
    public static function forBrief(TasteBrief $brief): ?string
    {
        $recipient = $brief->relationship === null ? null : RecipientType::tryFrom(mb_strtolower(trim($brief->relationship)));

        if ($recipient === null) {
            return null;
        }

        $interest = null;

        foreach ($brief->interests as $candidate) {
            if (($interest = Interest::tryFrom(mb_strtolower(trim($candidate)))) !== null) {
                break;
            }
        }

        $budget = $brief->budgetMin === null && $brief->budgetMax === null ? null : [$brief->budgetMin, $brief->budgetMax];

        return self::path($brief->market, $recipient, $interest, $budget);
    }

    /**
     * A brief from a landing address: what the page is about, and nothing the
     * address does not say.
     */
    public static function toBrief(Market $market, RecipientType $recipient, ?Interest $interest, ?string $budget = null, int $limit = 4): TasteBrief
    {
        [$min, $max] = self::budget($budget) ?? [null, null];

        return new TasteBrief(
            market: $market,
            interests: $interest === null ? [] : [$interest->value],
            budgetMin: $min,
            budgetMax: $max,
            relationship: $recipient->value,
            limit: $limit,
        );
    }

    /**
     * `?budget=` in euros, as cents: "50-100", "-25" (under), "100-" (over).
     *
     * Null for anything else, so a mangled parameter shows the whole page
     * rather than an error. Whole euros only: a budget is a band, not a price.
     *
     * @return array{0: int|null, 1: int|null}|null
     */
    public static function budget(?string $param): ?array
    {
        if ($param === null || preg_match('/^(\d{1,5})?-(\d{1,5})?$/', trim($param), $m) !== 1) {
            return null;
        }

        $min = isset($m[1]) && $m[1] !== '' ? (int) $m[1] * 100 : null;
        $max = isset($m[2]) && $m[2] !== '' ? (int) $m[2] * 100 : null;

        if ($min === null && $max === null) {
            return null;
        }

        if ($min !== null && $max !== null && $min > $max) {
            [$min, $max] = [$max, $min];
        }

        return [$min, $max];
    }

    /** The other way: cents to the `?budget=` value. */
    public static function budgetParam(?int $min, ?int $max): ?string
    {
        if ($min === null && $max === null) {
            return null;
        }

        return ($min === null ? '' : (string) intdiv($min, 100)).'-'.($max === null ? '' : (string) intdiv($max, 100));
    }

    private static function lookup(Market $market, string $kind, string $slug): ?string
    {
        $slug = mb_strtolower(trim($slug));

        $own = array_search($slug, self::slugs($market->language(), $kind), true);

        if ($own !== false) {
            return (string) $own;
        }

        foreach (Market::languages() as $language) {
            $found = array_search($slug, self::slugs($language, $kind), true);

            if ($found !== false) {
                return (string) $found;
            }
        }

        return null;
    }

    /** @return array<string, string> value => slug */
    private static function slugs(string $language, string $kind): array
    {
        if (! isset(self::$slugs[$language][$kind])) {
            $entries = trans("site.gift_landing.{$kind}", [], $language);

            self::$slugs[$language][$kind] = is_array($entries)
                ? array_map(fn ($entry) => is_array($entry) ? (string) ($entry['slug'] ?? '') : '', $entries)
                : [];
        }

        return self::$slugs[$language][$kind];
    }
}
