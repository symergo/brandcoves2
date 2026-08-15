<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Guide;
use App\Services\Guides\CoveMarkup;
use App\Support\CurrentMarket;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The Discover Cove: one page explaining the three ways this site shows you
 * something you were not looking for.
 *
 * The same argument as the Gift Cove. Daily, Surprise and the Coves archive
 * were each reachable from the header and collectively unexplained — three
 * entries that read as three unrelated links rather than as one half of the
 * product. "Surprise me" in particular promises nothing a visitor can evaluate
 * before pressing it.
 *
 * `/discover-cove`, not `/discover`: `/discover/{mode?}` is the mode dial from
 * discovery-modes.md and is a different thing — a surface you operate, not a
 * page that explains. Following the `/gift-cove` precedent, which exists for
 * exactly this reason.
 *
 * No *numbers*. A hub that counts things is the catalogue-counter mistake from
 * homepage.md in a new place, and every total worth showing belongs to a Cove
 * and is already on that Cove's own page.
 *
 * The Coves themselves are a different matter and are listed here. Two of the
 * three cards describe something the visitor cannot see from this page — today's
 * edition and a Surprise both have to be opened — but the archive is the one
 * whose value *is* its contents. A card saying "long reads around a theme"
 * sends the reader one click away to find out whether any of them is about
 * anything they care about; a dozen titles answers that here.
 */
class DiscoverCoveController extends Controller
{
    /**
     * More than the front page's taste, fewer than the archive index's sixty.
     *
     * This page has to be a hub rather than a second copy of `/guides`: enough
     * titles that the range is obvious, then a link to the whole thing.
     */
    private const COVES = 12;

    public function __invoke(CurrentMarket $current): Response
    {
        return Inertia::render('DiscoverCove', [
            'urls' => [
                'daily' => $current->url('daily'),
                'surprise' => $current->url('surprise'),
                'guides' => $current->url('guides'),
            ],

            'coves' => Guide::query()
                ->forMarket($current->get())
                ->published()
                ->orderByDesc('published_at')
                ->limit(self::COVES)
                ->get(['slug', 'title', 'intro', 'source_volume'])
                ->map(fn (Guide $guide): array => [
                    'title' => $guide->title,
                    // A card blurb, not an article: tokens flattened to their
                    // labels, exactly as the archive index does it. A link
                    // inside a card whose whole surface is already a link is a
                    // target fighting its parent.
                    'intro' => app(CoveMarkup::class)->plain($guide->intro),
                    'url' => $current->url("guides/{$guide->slug}"),
                    // Why the Cove exists, and a fact no competitor has.
                    'searches' => $guide->source_volume,
                ])
                ->all(),
        ]);
    }
}
