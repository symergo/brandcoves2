<?php

declare(strict_types=1);

namespace App\Support;

use App\Enums\Market;

/**
 * The address of a search, in the market's own language.
 *
 * `/be-nl/zoek/draadloze-koptelefoon` rather than `/be-nl/search?q=draadloze+koptelefoon`.
 * Asked for on 2026-09-12, with the bstore precedent in mind (`/amazon/<term>/`):
 * a path segment is read by a person deciding whether to click and by a search
 * engine deciding what the page is about, and `?q=` does neither job. The
 * segment is the market's word for it, the way the Daily Cove's is
 * (`Market::coveSegment()`), and for the same reason: nobody in Leuven types
 * "search".
 *
 * **The old form keeps working and is not redirected.** `/search?q=term` still
 * answers 200; its canonical points at the pretty URL, every internal link and
 * client navigation mints the pretty URL, and that is what consolidates the
 * ranking. A 301 would have been marginally cleaner and would have broken sixty
 * requests across the test suite and every bookmark for one hop's worth of
 * gain; the canonical does the same job without the churn.
 *
 * **Only a term that survives the round trip gets a pretty path.** The slug
 * turns spaces into hyphens and nothing else, so it can be turned back without
 * guessing. A term with anything beyond ASCII letters, digits and single spaces
 * (`6.1`, `café`, `wh-1000xm5`, `50%`) stays on `?q=`, exact, rather than being
 * flattened into a different search. Cases are folded: the search is
 * case-insensitive and `/zoek/Philips` and `/zoek/philips` must be one page.
 */
final class SearchUrl
{
    /** Per language, not per market: be-nl and nl-nl share a word. */
    private const SEGMENTS = [
        'nl' => 'zoek',
        'fr' => 'recherche',
        'en' => 'search',
        'es' => 'buscar',
    ];

    /** The bare search page, the form with nothing typed yet. */
    public static function landing(Market $market): string
    {
        return '/'.$market->value.'/search';
    }

    public static function segment(Market $market): string
    {
        return self::SEGMENTS[$market->language()] ?? 'search';
    }

    /**
     * Every segment any market uses, for the route constraint. A request for a
     * segment in the wrong language still serves; its canonical says the right one.
     *
     * @return list<string>
     */
    public static function segments(): array
    {
        return array_values(array_unique(array_values(self::SEGMENTS)));
    }

    /**
     * The URL for a term, with any further query parameters.
     *
     * @param  array<string, mixed>  $params  filters, sort, page, max: whatever else the search carries
     */
    public static function for(Market $market, string $term, array $params = []): string
    {
        $term = self::normalise($term);
        unset($params['q']);

        if ($term === '') {
            return self::landing($market).self::query($params);
        }

        if (self::isClean($term)) {
            return '/'.$market->value.'/'.self::segment($market).'/'.self::slug($term).self::query($params);
        }

        return self::landing($market).self::query(['q' => $term] + $params);
    }

    /** Whether the term reads back from its slug exactly. */
    public static function isClean(string $term): bool
    {
        return preg_match('/^[a-z0-9]+(?: [a-z0-9]+)*$/', self::normalise($term)) === 1;
    }

    /** Spaces to hyphens, lowercase. Only ever called on a clean term. */
    public static function slug(string $term): string
    {
        return str_replace(' ', '-', self::normalise($term));
    }

    /** The term a path segment stands for. */
    public static function term(string $slug): string
    {
        return trim(str_replace('-', ' ', mb_strtolower($slug)));
    }

    private static function normalise(string $term): string
    {
        return mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $term)));
    }

    /** @param  array<string, mixed>  $params */
    private static function query(array $params): string
    {
        $params = array_filter($params, fn ($v) => $v !== null && $v !== '' && $v !== []);

        return $params === [] ? '' : '?'.http_build_query($params);
    }
}
