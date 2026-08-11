<?php

declare(strict_types=1);

namespace App\Services\Search;

use App\Enums\IdentityKind;
use App\Enums\Market;
use App\Services\Connectors\Offer;
use App\Services\Identity\Gtin;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Working out the brand of an offer whose source did not name one.
 *
 * ## Why this has to exist
 *
 * Neither bol nor Amazon offers a brand filter on the endpoints we use, so a
 * brand page asks them the only question they answer: a keyword search for the
 * brand's name. What comes back is a list of products, and bol's catalogue API
 * returns **no brand field at all** — `BolConnector::normalise()` sets it null on
 * purpose, because guessing a brand off a title in the general case is wrong
 * often enough that a wrong brand would poison grouping and the brand facet.
 *
 * Left null, those offers are stored, grouped, and then never seen: a brand page
 * filters on `product_groups.brand`, so an offer with no brand is invisible on
 * the one page that just paid to fetch it. The live query would cost requests and
 * change nothing.
 *
 * ## Two kinds of evidence, in that order
 *
 * **1. Another source already named it.** The catalogue holds tens of thousands
 * of Awin rows carrying both a barcode and a brand, and a barcode is a physical
 * product. If any feed says the thing behind 4548736112513 is a Sony, then bol's
 * unbranded listing of that same product is a Sony — that is a lookup, not an
 * inference, and it is right regardless of how the title happens to be worded.
 * This is also the half that works on the ordinary search page, where there is no
 * brand to compare against at all.
 *
 * The join is on `identity_key` where `identity_kind` is `ean`, **not** on the
 * raw `products.ean` column. `ean` holds whatever the feed wrote — a UPC-A, a
 * GTIN-8, an ITF-14, the same number with spaces or hyphens in it — while
 * `identity_key` is what `Gtin::normalise()` made of it, checksum-validated and
 * widened to GTIN-13. Two shops listing one product agree on the second and
 * routinely disagree on the first. It is also the column the catalogue is indexed
 * on, which is what keeps this to one index scan on a request path.
 *
 * The lookup deliberately spans **every market**, unlike almost everything else
 * here. Market scoping exists because tax, shipping and stock differ per market,
 * so offers must not be merged across them (see the product-identity invariant).
 * A brand name is none of those things: it is a property of the physical object,
 * identical in every market, and scoping the lookup would only make it fail more
 * often for no gain in correctness. Enumerating the markets rather than dropping
 * the column keeps `products_identity_idx` usable — it leads on `market`, so an
 * unqualified query would be a sequential scan of the whole catalogue.
 *
 * **2. The title leads with the brand we asked for.** Only on a brand page, and
 * only for offers the first step could not settle — a bol listing with no EAN, or
 * one for a product no feed carries.
 *
 * The connector refuses this inference because it has a title and nothing else.
 * Here there is a second piece of evidence: we asked for this brand by name, and
 * the spellings come from `brand_stats`, which was built from the catalogue.
 *
 * **Beginning**, not containing, and that is the whole safety margin. bol titles
 * are written "Brand Model — description" almost without exception, while the
 * accessories that pollute a brand search are written the other way round:
 * "Hoesje voor Sony WH-1000XM5" is a case made by somebody else, and a
 * contains-match would file it under Sony. Anchoring at the start costs a few
 * genuine matches whose title leads with the category, and refuses the entire
 * class of third-party accessory that a brand page must not claim.
 *
 * Comparison is done on `Str::ascii()`-folded text, for the same reason
 * `brand_stats.slug` is: "Kärcher" and "Karcher" are one brand, and Postgres
 * cannot fold them the way PHP does.
 *
 * An offer that already carries a brand is never touched by either step. A source
 * that stated one knows better than this does.
 */
final class BrandAttribution
{
    /**
     * Fill in the brand from what another source has already recorded for the
     * same physical product.
     *
     * One query for the whole batch. Where sources disagree about the spelling —
     * "Audio-Technica" against "Audio Technica" — the most-used one wins, which
     * is the same rule `RefreshBrandStats` uses to pick a brand page's display
     * name, so a card and the page it links to cannot disagree.
     *
     * @param  list<Offer>  $offers
     * @return list<Offer>
     */
    public function fromCatalogue(array $offers): array
    {
        /** @var array<int, string> $gtins offer index => normalised GTIN */
        $gtins = [];

        foreach ($offers as $index => $offer) {
            $gtin = $offer->brand === null ? Gtin::normalise($offer->ean) : null;

            if ($gtin !== null) {
                $gtins[$index] = $gtin;
            }
        }

        if ($gtins === []) {
            return $offers;
        }

        /** @var array<string, string> $known gtin => brand */
        $known = [];

        DB::table('products')
            ->select('identity_key', 'brand', DB::raw('count(*) as total'))
            // See the class docblock: every market, but named, so the composite
            // index still applies.
            ->whereIn('market', Market::values())
            ->whereIn('identity_key', array_values(array_unique($gtins)))
            ->where('identity_kind', IdentityKind::Ean->value)
            ->whereNotNull('brand')
            ->where('brand', '!=', '')
            ->groupBy('identity_key', 'brand')
            // Alphabetical tie-break, so a brand seen the same number of times
            // under two spellings does not change spelling between two requests
            // and make the page look unstable.
            ->orderByDesc('total')
            ->orderBy('brand')
            ->get()
            ->each(function (object $row) use (&$known): void {
                $known[(string) $row->identity_key] ??= (string) $row->brand;
            });

        if ($known === []) {
            return $offers;
        }

        foreach ($gtins as $index => $gtin) {
            if (isset($known[$gtin])) {
                $offers[$index] = $offers[$index]->withBrand($known[$gtin]);
            }
        }

        return array_values($offers);
    }

    /**
     * Fill in the brand on offers whose title leads with one of its spellings.
     *
     * Runs after `fromCatalogue()` and only touches what that left null, so an
     * offer the catalogue could identify is never re-decided from its wording.
     *
     * @param  list<Offer>  $offers
     * @param  list<string>  $spellings  Every way this brand is written, from `brand_stats.aliases`.
     * @param  string  $display  The spelling to store — the brand page's own heading.
     * @return list<Offer>
     */
    public function attribute(array $offers, array $spellings, string $display): array
    {
        $prefixes = $this->prefixes($spellings);

        if ($prefixes === []) {
            return $offers;
        }

        return array_map(
            fn (Offer $offer) => $offer->brand === null && $this->leadsWith($offer->title, $prefixes)
                ? $offer->withBrand($display)
                : $offer,
            $offers,
        );
    }

    /**
     * Only the offers this brand can actually claim.
     *
     * Used for the sources we may not store, which never pass through the brand
     * filter a stored offer meets in SQL. Without it an Amazon lane on a Sony
     * page would show whatever a keyword search for "Sony" returned, accessories
     * included, under a heading promising Sony products.
     *
     * @param  list<Offer>  $offers
     * @param  list<string>  $spellings
     * @return list<Offer>
     */
    public function matching(array $offers, array $spellings): array
    {
        $prefixes = $this->prefixes($spellings);

        if ($prefixes === []) {
            return $offers;
        }

        return array_values(array_filter(
            $offers,
            fn (Offer $offer) => $this->leadsWith($offer->title, $prefixes)
                // A source that named the brand itself is taken at its word, even
                // when it words the title category-first.
                || ($offer->brand !== null && $this->isSpelling($offer->brand, $spellings)),
        ));
    }

    /**
     * @param  list<string>  $spellings
     * @return list<string> Folded, longest first.
     */
    private function prefixes(array $spellings): array
    {
        $prefixes = [];

        foreach ($spellings as $spelling) {
            $folded = $this->fold($spelling);

            // Two characters is too short to anchor on: "LG" would be defensible,
            // but a two-letter prefix match against a foreign-language title
            // catches far more than it should, and the brands it protects are
            // few. Below three characters the brand page simply gets no live
            // offers, which is the safe failure.
            if (mb_strlen($folded) >= 3) {
                $prefixes[] = $folded;
            }
        }

        // Longest first so "audio technica" is tested before a hypothetical
        // "audio", and the more specific spelling wins.
        usort($prefixes, fn (string $a, string $b) => mb_strlen($b) <=> mb_strlen($a));

        return array_values(array_unique($prefixes));
    }

    /** @param list<string> $prefixes */
    private function leadsWith(string $title, array $prefixes): bool
    {
        $folded = $this->fold($title);

        foreach ($prefixes as $prefix) {
            if (! str_starts_with($folded, $prefix)) {
                continue;
            }

            $next = mb_substr($folded, mb_strlen($prefix), 1);

            // A word boundary, or the end of the title. Without this "sony" would
            // match "sonystereo" and, more plausibly, "philip" would match
            // "philips".
            if ($next === '' || preg_match('/[a-z0-9]/', $next) !== 1) {
                return true;
            }
        }

        return false;
    }

    /** @param list<string> $spellings */
    private function isSpelling(string $brand, array $spellings): bool
    {
        $slug = Str::slug($brand);

        foreach ($spellings as $spelling) {
            if ($slug === Str::slug($spelling)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Punctuation collapses to a single space, so "Audio-Technica" and
     * "Audio Technica" fold together the way `Str::slug()` folds them for the
     * page's own URL.
     */
    private function fold(string $value): string
    {
        $ascii = mb_strtolower(Str::ascii($value));

        return trim((string) preg_replace('/[^a-z0-9]+/', ' ', $ascii));
    }
}
