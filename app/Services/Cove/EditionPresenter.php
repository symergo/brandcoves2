<?php

declare(strict_types=1);

namespace App\Services\Cove;

use App\Models\DailyPick;
use App\Models\DailyPickSet;
use App\Support\CurrentMarket;

/**
 * Turning a built Cove into the props a page renders.
 *
 * Extracted when gift personas arrived. A persona is the same object as a Daily
 * Cove — the same picks, the same prose, the same link tokens — served at a
 * permanent URL instead of a dated one, so the two pages must present it
 * identically. Two controllers each holding their own copy of "resolve the
 * tokens, pair the paragraphs with their products, drop what is out of stock"
 * is three months away from a bug that exists on one page and not the other.
 *
 * What is *not* here: the deals column, which is the daily column's own
 * furniture — a persona stands for a year and has no reason to sit beside this
 * fortnight's discounts. Nor the rail and the cards under the article, which
 * every Cove kind carries: see App\Services\Cove\CoveRail.
 */
class EditionPresenter
{
    public function __construct(
        private readonly CoveProse $prose,
    ) {}

    /**
     * The article, paragraph by paragraph, each carrying the products it names.
     *
     * The page used to be prose and then a grid: everything the writing was
     * about sat below everything the writing said, so a paragraph discussing a
     * kettle pointed at a card three screens down and the reader had to hold
     * the name in their head to find it. That is a catalogue with an
     * introduction, not an editorial.
     *
     * The pairing is already in the copy. A `[[product:12]]` token is the
     * writer saying "this paragraph is about that thing"; reading the ids back
     * out per paragraph is what lets the product appear where it is discussed.
     *
     * Rendered when the Cove was built and stored with it; see CoveProse for
     * when the page renders it itself instead. `$current` is the market the
     * page is read in, which is always the Cove's own.
     *
     * @return list<array{html: string, groupIds: list<int>}>
     */
    public function editorial(DailyPickSet $edition, CurrentMarket $current): array
    {
        if (blank($edition->editorial)) {
            return [];
        }

        return $this->prose->for($edition)['editorial'] ?? [];
    }

    /**
     * The finds, as cards.
     *
     * Filtered on `in_stock` at render rather than at build: a Cove is built
     * once and served all day — forever, for a persona — and a pick that sold
     * out at eleven would otherwise carry on being offered as an ordinary
     * buyable product for the rest of its life.
     *
     * @return list<array<string, mixed>>
     */
    public function finds(DailyPickSet $edition, CurrentMarket $current): array
    {
        return $edition->picks
            ->filter(fn (DailyPick $pick) => $pick->group !== null && $pick->group->in_stock)
            ->map(fn (DailyPick $pick) => [
                'id' => $pick->id,
                'groupId' => $pick->group->id,
                'title' => $pick->group->displayTitle(),
                'image' => $pick->group->image_url,
                'price' => $pick->group->min_price,
                'merchantCount' => $pick->group->merchant_count,
                /*
                 * A stored zero is not a discount.
                 *
                 * This reads the column the builder wrote rather than calling
                 * ProductGroup::discountPercent() live, so it still carries the
                 * zeros written before that method learned to return null for a
                 * saving under one percent — and "−0%" is a badge that claims
                 * nothing while looking exactly like one that claims something.
                 */
                'discountPercent' => $pick->discount_percent > 0 ? $pick->discount_percent : null,
                'blurb' => $pick->blurb,
                'url' => $current->url("p/{$pick->group->id}/{$pick->group->slug}"),
                'mindblown' => $pick->mindblown_count,
                'meh' => $pick->meh_count,
            ])
            ->values()
            ->all();
    }

    /** @return array<string, mixed>|null */
    public function guide(DailyPickSet $edition, CurrentMarket $current): ?array
    {
        // A self-reference since the fold: the guide a Daily points at is itself
        // a Cove. `featured_cove_id` replaced `guide_id`.
        $guide = $edition->featured;

        if ($guide === null || ! $guide->kind->isArticle()) {
            return null;
        }

        return [
            'title' => $guide->theme_title,
            'intro' => $guide->theme_blurb,
            'url' => $current->url($guide->kind->path((string) $guide->slug, $current->get())),
            // Counted by the query that loaded the Cove when it could be
            // (DailyCoveController eager-loads it), rather than one more
            // query per view here.
            'itemCount' => $guide->picks_count ?? $guide->picks()->count(),
            // The demand that justified writing it. Shown because it is the
            // honest answer to "why this guide" and because it is a fact only
            // this site has.
            'searchVolume' => $guide->source_volume,
        ];
    }
}
