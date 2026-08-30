<?php

declare(strict_types=1);

namespace App\Services\Catalogue;

use App\Models\Product;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * The long description on a product page, taken from the shops that supply one.
 *
 * ## Where the text comes from
 *
 * Nowhere new. Feed ingestion already stores `products.description` for every
 * source that ships one — bol, Coolblue and the rest of Awin, eBay,
 * Tradedoubler — and the search vector has been weighting it all along. It was
 * simply never rendered. So this is a presentation decision, not an acquisition
 * one: no new request, no new column, no new job.
 *
 * That also settles the obvious alternative. Fetching a description live from
 * bol at render time would put a third party's latency inside our request
 * handler, on the most-crawled page type on the site, to produce a field we
 * already hold. A group whose offers carry no description gets no section, and
 * the fix for that is `bc:ingest`, not a fetch.
 *
 * ## Why it is attributed to a shop and never rewritten
 *
 * It is their marketing copy. Presented unattributed it reads as ours, which is
 * both a claim we cannot stand behind — "the best headphones you will ever own"
 * is not an editorial position of this site — and a page asserting original
 * prose it did not write. Named, it is a quotation, which is what it is.
 *
 * ## What is deliberately excluded
 *
 * **Amazon.** `Source::allowsCatalogueStorage()` is false there, so no Amazon
 * row should hold a description in the first place; filtering on it here means
 * a future bug upstream cannot surface one on a page. Invariant 6 covers
 * exactly this field among others.
 *
 * **Anything short.** A 40-character blurb under a heading that says
 * "Description" looks like a page that failed to load, and the offer titles
 * above already carry more. Below {@see self::MINIMUM} there is no section.
 *
 * **The title again.** Feeds routinely fill this column with the product name.
 * Repeating the `<h1>` under a heading is worse than an absent section.
 */
final readonly class ProductDescription
{
    /**
     * Below this it is a scrap, not a description.
     *
     * Higher than `Excerpt`'s 30 on purpose: that one guards a single line of
     * card copy, where a short phrase still helps. This one has to earn a
     * heading and a block of its own, and 30 characters cannot.
     */
    private const MINIMUM = 120;

    /**
     * Feed descriptions run long and repetitive past this point — shipping
     * blurbs, warranty boilerplate, the brand's own about-us paragraph. Cut on
     * a word boundary; a truncated word reads as corrupted data.
     */
    private const LIMIT = 1800;

    private function __construct(
        /** @var list<string> Paragraphs, already cleaned. Never HTML. */
        public array $paragraphs,
        /** The shop whose copy this is, for the attribution line. */
        public string $merchant,
    ) {}

    /**
     * The best description among a group's offers, or null if none qualifies.
     *
     * "Best" is the longest. Not a quality judgement — length is the only
     * signal available — but the failure it avoids is real: several merchants
     * carry the same product, one with a one-line summary and one with the
     * manufacturer's full text, and picking the first offer would show the
     * summary while the full description sat one row down.
     *
     * @param  Collection<int, Product>  $offers
     */
    public static function pick(Collection $offers, ?string $title = null): ?self
    {
        $best = $offers
            ->filter(fn (Product $offer) => $offer->source->allowsCatalogueStorage())
            ->map(fn (Product $offer) => [
                'paragraphs' => self::clean((string) $offer->description),
                'merchant' => $offer->merchant?->displayName() ?? $offer->source->label(),
                // Deterministic tie-break, so two equally long descriptions do
                // not swap places between requests and make the page look
                // unstable to a crawler.
                'id' => $offer->id,
            ])
            ->filter(fn (array $candidate) => $candidate['paragraphs'] !== [])
            ->reject(fn (array $candidate) => self::isJustTheTitle($candidate['paragraphs'], $title))
            ->sort(function (array $a, array $b) {
                $byLength = mb_strlen(implode(' ', $b['paragraphs'])) <=> mb_strlen(implode(' ', $a['paragraphs']));

                return $byLength !== 0 ? $byLength : $a['id'] <=> $b['id'];
            })
            ->first();

        return $best === null ? null : new self($best['paragraphs'], $best['merchant']);
    }

    /**
     * Feed HTML into plain paragraphs.
     *
     * The structure is worth keeping where the source had any: bol writes spec
     * bullets as `<ul>`, and collapsing those into one run of prose produces a
     * wall of clauses nobody reads. So block boundaries become paragraph breaks
     * and everything else collapses to single spaces.
     *
     * The output is plain text and stays that way. Rendering merchant HTML
     * would mean trusting a third-party feed with markup on our page, and
     * `Excerpt` already established that this column arrives as anything at all
     * — including, on at least one Awin advertiser, unbalanced tags.
     *
     * @return list<string>
     */
    private static function clean(string $raw): array
    {
        if (trim($raw) === '') {
            return [];
        }

        // Block boundaries first, while the tags are still there to see.
        $text = (string) preg_replace('/<\s*(br|\/p|\/li|\/div|\/h[1-6]|\/tr)[^>]*>/i', "\n", $raw);

        $text = strip_tags($text);

        // After the tags, not before: an entity can encode a bracket, and
        // decoding first would hand `strip_tags()` markup it never saw.
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = str_replace("\u{00A0}", ' ', $text);

        $paragraphs = [];

        foreach (preg_split('/\R+/u', $text) ?: [] as $line) {
            // Horizontal whitespace only — the newlines are the structure.
            $line = trim((string) preg_replace('/[^\S\n]+/u', ' ', $line));

            if ($line !== '') {
                $paragraphs[] = $line;
            }
        }

        if (mb_strlen(implode(' ', $paragraphs)) < self::MINIMUM) {
            return [];
        }

        return self::capped($paragraphs);
    }

    /**
     * Trim to LIMIT across the whole thing, dropping whole paragraphs first.
     *
     * @param  list<string>  $paragraphs
     * @return list<string>
     */
    private static function capped(array $paragraphs): array
    {
        $kept = [];
        $used = 0;

        foreach ($paragraphs as $paragraph) {
            $room = self::LIMIT - $used;

            if ($room <= 0) {
                break;
            }

            if (mb_strlen($paragraph) > $room) {
                $trimmed = Str::limit($paragraph, $room, '…', preserveWords: true);

                // A one-word remainder is not worth a paragraph of its own.
                if (mb_strlen($trimmed) > 20) {
                    $kept[] = $trimmed;
                }

                break;
            }

            $kept[] = $paragraph;
            $used += mb_strlen($paragraph);
        }

        return $kept;
    }

    /**
     * Feeds routinely fill the description column with the product name.
     *
     * Compared on a folded key rather than literally: the two differ by
     * punctuation and casing far more often than by content.
     *
     * @param  list<string>  $paragraphs
     */
    private static function isJustTheTitle(array $paragraphs, ?string $title): bool
    {
        if ($title === null || trim($title) === '') {
            return false;
        }

        return Str::slug(implode(' ', $paragraphs)) === Str::slug($title);
    }

    /** @return array{paragraphs: list<string>, merchant: string} */
    public function toArray(): array
    {
        return ['paragraphs' => $this->paragraphs, 'merchant' => $this->merchant];
    }
}
