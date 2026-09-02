<?php

declare(strict_types=1);

namespace App\Services\Cove;

use App\Enums\IdentityKind;
use App\Enums\Market;
use App\Enums\Source;
use App\Models\DailyPickSet;
use App\Models\Product;
use App\Models\ProductGroup;

/**
 * What goes in the Daily Cove email.
 *
 * ## This is a teaser, not the edition
 *
 * The email carries our own words, a few finds, and one link. The edition itself
 * — the puzzle, the full set, the Amazon offers — lives on the page.
 *
 * That is a compliance decision before it is an editorial one. Two separate
 * Amazon rules apply to email and dropping the link clears only one of them:
 *
 * | Rule | Restricts | Does linking to our own page help? |
 * |---|---|---|
 * | Associates Operating Agreement | Special Links in email | Yes |
 * | PA-API licence | *Product Advertising Content* — titles, images, prices — displayed anywhere but your own site | **No.** The restriction is on the content, not the destination |
 *
 * So an email carrying an Amazon title breaches the second rule even when every
 * link points at giftcoves.com. A digest with **nothing to filter cannot be got
 * wrong later** — the alternative, a full edition with Amazon items stripped,
 * makes every future template inherit a filter someone has to remember.
 *
 * See docs/features/amazon-compliance.md.
 *
 * ## Links go to a barcode search
 *
 *     /{market}/search?q={ean}
 *
 * Not to a product page, and certainly not to a merchant. `SearchService` treats
 * a GTIN as an exact identity *and* queries the live sources, so the reader lands
 * on the full comparison — Amazon included, fetched live, on our page where it is
 * licensed to appear. The email itself carries a number and our own words.
 *
 * ## The rule that makes it safe
 *
 * > A product may be named in the email only when we hold that name from a
 * > **non-Amazon** source. An Amazon-only item is left out.
 *
 * A title lifted from PA-API is Product Advertising Content wherever it appears,
 * and putting it next to a compliant link does not launder it. `hasNonAmazonSource()`
 * is the check, and it asks about the *offers behind the group*, not about the
 * group — a group whose only live offer is Amazon has an Amazon title, whatever
 * else is recorded against it.
 */
class DigestBuilder
{
    /** Four. A teaser that lists everything is not a teaser. */
    private const MAX_FINDS = 4;

    /**
     * @return array{
     *     theme: string,
     *     blurb: string|null,
     *     lead: string|null,
     *     date: string,
     *     url: string,
     *     hasPuzzle: bool,
     *     finds: list<array{title: string, brand: string|null, price: int|null, url: string, shops: int}>,
     *     omitted: int,
     * }|null
     */
    public function forEdition(DailyPickSet $edition, Market $market): ?array
    {
        $base = '/'.$market->value;

        $eligible = [];
        $omitted = 0;

        foreach ($edition->picks as $pick) {
            $group = $pick->group;

            if ($group === null) {
                continue;
            }

            if (! $group->in_stock) {
                /*
                 * Counted, not named. An email is written once and read hours
                 * later, so it is the surface most likely to send somebody to a
                 * product that has since sold out — and the one where they
                 * cannot see the page has moved on.
                 */
                $omitted++;

                continue;
            }

            if (! $this->mayName($group)) {
                // Counted, not silently dropped: "and three more on the page" is
                // both true and a reason to click, and it means an edition that
                // is mostly Amazon still produces a sendable email.
                $omitted++;

                continue;
            }

            if (count($eligible) >= self::MAX_FINDS) {
                $omitted++;

                continue;
            }

            $eligible[] = [
                'title' => $group->title,
                'brand' => $group->brand,
                'price' => $group->min_price,
                'url' => $this->linkFor($group, $base),
                'shops' => max(1, (int) $group->merchant_count),
            ];
        }

        /*
         * An email with no finds is a notification that a page exists. Sending
         * one teaches people the digest is not worth opening, which is the only
         * irreversible thing a daily email can do.
         */
        if ($eligible === []) {
            return null;
        }

        return [
            'theme' => $edition->theme_title,
            'blurb' => $edition->theme_blurb,
            // The first paragraph of the editorial, plain — the email is not the
            // place to resolve [[product:…]] tokens into links.
            'lead' => $this->lead($edition),
            'date' => $edition->drop_date->toDateString(),
            // covePath() carries the market itself, so it replaces $base rather
            // than appending to it — the localised segment is only correct next
            // to the market it belongs to.
            'url' => $market->covePath($edition->slug),
            'finds' => $eligible,
            'omitted' => $omitted,
        ];
    }

    /**
     * May this product be named in an email?
     *
     * True only when at least one live offer comes from a source that permits its
     * product data in email. Asked of the offers rather than the group because
     * the group's denormalised title came from whichever offer won, and if that
     * was Amazon then the title is Product Advertising Content.
     */
    public function mayName(ProductGroup $group): bool
    {
        return $group->offers()
            ->where('status', 'active')
            ->get(['source'])
            ->contains(fn (Product $offer) => $offer->source->allowsEmail());
    }

    /**
     * Where a find in the email points.
     *
     * A barcode search when we have a GTIN, so the reader lands on the live
     * comparison across every source. The product page otherwise — for a group
     * identified by "brand|title" there is no barcode to search for, and sending
     * someone to a text search of a product title is a worse page than the
     * product.
     */
    private function linkFor(ProductGroup $group, string $base): string
    {
        return $group->identity_kind === IdentityKind::Ean
            ? $base.'/search?'.http_build_query(['q' => $group->identity_key])
            : $base.'/p/'.$group->id.'/'.$group->slug;
    }

    private function lead(DailyPickSet $edition): ?string
    {
        if (blank($edition->editorial)) {
            return null;
        }

        $first = preg_split('/\R{2,}/u', trim((string) $edition->editorial))[0] ?? '';

        // Tokens are a rendering syntax for the web page. In an email they would
        // read as literal `[[product:412]]`, so they degrade to their labels —
        // the same fallback CoveMarkup applies to a rejected token.
        $plain = preg_replace('/\[\[(?:brand|search|product):([^\]|]{1,120})(?:\|([^\]]{1,160}))?\]\]/u', '$2$1', $first) ?? $first;

        return trim($plain) === '' ? null : trim($plain);
    }

    /**
     * Sources whose products may appear. Exposed for the compliance test, which
     * asserts the list rather than trusting a comment.
     *
     * @return list<Source>
     */
    public static function emailableSources(): array
    {
        return array_values(array_filter(Source::cases(), fn (Source $s) => $s->allowsEmail()));
    }
}
