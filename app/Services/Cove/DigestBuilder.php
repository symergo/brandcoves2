<?php

declare(strict_types=1);

namespace App\Services\Cove;

use App\Enums\Market;
use App\Enums\Source;
use App\Models\DailyPickSet;
use App\Models\Product;
use App\Models\ProductGroup;
use App\Services\Editorial\Allowlist;
use App\Services\Guides\CoveMarkup;

/**
 * What goes in the Daily Cove email.
 *
 * ## The whole article, minus what may not be in an email
 *
 * The email carries the edition's own words in full, a link on every product
 * we may name, and the price list under it. Until 2026-09-09 it was a teaser:
 * the first paragraph, four bare titles and a button. Read next to the page it
 * pointed at, that looked like a mail that had been cut off, and the owner said
 * so. The prose is ours, so there is no reason to withhold it.
 *
 * What still stays out is Amazon. Two separate Amazon rules apply to email and
 * dropping the link clears only one of them:
 *
 * | Rule | Restricts | Does linking to our own page help? |
 * |---|---|---|
 * | Associates Operating Agreement | Special Links in email | Yes |
 * | PA-API licence | *Product Advertising Content* — titles, images, prices — displayed anywhere but your own site | **No.** The restriction is on the content, not the destination |
 *
 * So an email carrying an Amazon title breaches the second rule even when every
 * link points at giftcoves.com. See docs/features/amazon-compliance.md.
 *
 * ## The rule that makes it safe
 *
 * > A product may be named in the email only when we hold that name from a
 * > **non-Amazon** source. An Amazon-only item is left out of the price list,
 * > and its token in the prose is reduced to the writer's own words, unlinked.
 *
 * A title lifted from PA-API is Product Advertising Content wherever it appears,
 * and putting it next to a compliant link does not launder it. `mayName()` is
 * the check, and it asks about the *offers behind the group*, not about the
 * group — a group whose only live offer is Amazon has an Amazon title, whatever
 * else is recorded against it.
 *
 * The prose about an Amazon-only pick is still sent: those are our sentences,
 * not Amazon's data, and the page renders the same paragraph.
 *
 * ## Links go to the product page
 *
 *     /{market}/p/{id}/{slug}
 *
 * They used to go to `/search?q={ean}`, on the reasoning that the search page
 * queried Amazon live and so showed the fuller comparison. It does not: Amazon
 * is not a live search connector, so a barcode search shows one result under a
 * heading that reads "results for 6977728941431". The product page holds every
 * offer we have, re-checks bol at render, and carries the Amazon search
 * hand-off for a group with a barcode. It is the page the editorial API already
 * reports as the product's URL. Changed 2026-09-09.
 */
class DigestBuilder
{
    public function __construct(
        private readonly Allowlist $allowlist,
        private readonly CoveMarkup $markup,
    ) {}

    /**
     * @return array{
     *     theme: string,
     *     blurb: string|null,
     *     body: list<string>,
     *     date: string,
     *     url: string,
     *     finds: list<array{title: string, brand: string|null, price: int|null, url: string, shops: int}>,
     *     omitted: int,
     * }|null
     */
    public function forEdition(DailyPickSet $edition, Market $market): ?array
    {
        $base = '/'.$market->value;

        $eligible = [];
        /** @var list<ProductGroup> $nameable */
        $nameable = [];
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

            $nameable[] = $group;

            $eligible[] = [
                'title' => $group->title,
                'brand' => $group->brand,
                'price' => $group->min_price,
                'url' => $base.'/p/'.$group->id.'/'.$group->slug,
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
            'body' => $this->body($edition, $market, $nameable),
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
     * The editorial as HTML paragraphs, tokens resolved the way the page does.
     *
     * The same renderer and the same allowlist as the Cove page, so a link that
     * works there works here — with one narrowing: the product allowlist holds
     * only the groups the email may name. A token for an Amazon-only pick is
     * therefore rejected by the renderer and degrades to the writer's own label,
     * exactly as a hallucinated brand does on the page. Nothing in this class
     * has to know the token grammar.
     *
     * The paragraphs are HTML, escaped by the renderer, for the template to
     * print raw. Emitting Markdown instead would mean escaping our own labels
     * against a second parser.
     *
     * @param  list<ProductGroup>  $nameable
     * @return list<string>
     */
    private function body(DailyPickSet $edition, Market $market, array $nameable): array
    {
        if (blank($edition->editorial)) {
            return [];
        }

        $allowed = $this->allowlist->full($nameable, $market);

        $paragraphs = $this->markup->paragraphs((string) $edition->editorial, $market, $allowed)['html'];

        // An email has no origin to resolve a path against, and the renderer
        // writes site-relative ones for the page. Pinned to APP_URL here; only
        // paths are touched, so an Amazon hand-off, already absolute, is not.
        $origin = rtrim(url('/'), '/');

        return array_map(
            fn (string $html): string => str_replace('href="/', 'href="'.$origin.'/', $html),
            $paragraphs,
        );
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
