<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\Seo\PageMeta;
use App\Support\CurrentMarket;
use Inertia\Inertia;
use Inertia\Response;

/**
 * "How each one works" — nine tools, three steps each, on a page of its own.
 *
 * ## Why it moved off the hub
 *
 * It was the bottom half of `/gift-cove`, and that page has two readers who
 * want opposite things. Somebody arriving to *use* a tool wants the grid and
 * their own lists; somebody arriving to *understand* one wants the steps. Nine
 * entries of three steps is a long page either way, and it sat underneath the
 * thing most visits came for — so the hub scrolled past what you already knew
 * to reach what you already had.
 *
 * Splitting them also gives the explanation an address. A section behind a
 * `#manual` anchor cannot be linked to from an email, a support reply or a
 * search result; a page can, and this is the page somebody is looking for when
 * they type "how does the secret friend draw work".
 *
 * ## Public, and deliberately data-free
 *
 * The hub personalises — your lists, your counts, your groups — and needs an
 * owner to do it. This does not: it explains the tools to somebody who may have
 * none of them yet, which is exactly who reads it. No queries, no identity, and
 * nothing to differ between two visitors.
 *
 * The tool order is fixed here rather than shared with the hub. They are the
 * same nine in the same order and that is worth keeping, but the hub's list
 * carries `href` and `badge` computed from a visitor's own data — importing it
 * would drag that here for no reason, and the two lists are checked against
 * each other by `every_tool_on_the_gift_cove_has_its_three_steps`.
 */
class GiftCoveManualController extends Controller
{
    public function __invoke(CurrentMarket $current): Response
    {
        app(PageMeta::class)->set(
            title: __('site.gift_cove.manual'),
            description: __('site.gift_cove.manual_intro'),
            canonical: url($current->url('gift-cove/how-it-works')),
        );

        return Inertia::render('GiftCove/HowItWorks', [
            'backUrl' => $current->url('gift-cove'),
        ]);
    }
}
