<?php

declare(strict_types=1);

namespace App\Http\Controllers;

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
 * No data. Every number worth showing here belongs to a Cove and is already on
 * that Cove's own page; a hub that counts things is the catalogue-counter
 * mistake from homepage.md in a new place.
 */
class DiscoverCoveController extends Controller
{
    public function __invoke(CurrentMarket $current): Response
    {
        return Inertia::render('DiscoverCove', [
            'urls' => [
                'daily' => $current->url('daily'),
                'surprise' => $current->url('surprise'),
                'guides' => $current->url('guides'),
            ],
        ]);
    }
}
