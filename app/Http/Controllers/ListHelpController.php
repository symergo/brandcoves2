<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\Seo\PageMeta;
use App\Support\CurrentMarket;
use Inertia\Inertia;
use Inertia\Response;

/**
 * How to make a list, and how to put something on it.
 *
 * ## Why a page and not a tooltip
 *
 * Saving a product is one tap on a bookmark, and the bookmark sits on a card
 * among a dozen other things to tap. People do not find it, and the ones who do
 * find it do not always realise a list has to exist first — so the question
 * arrives as "how do I use this" rather than as a question about any one
 * control, and there was nowhere to send it.
 *
 * Next to `/lists` rather than with the legal pages, for the same reason
 * `/search-help` sits next to `/search`: it documents a tool, not the company.
 *
 * ## The screenshots are per language, and taken by a script
 *
 * `scripts/help-screenshots.mjs`, committed alongside, drives the real
 * interface and photographs it. Instructions with pictures of last year's
 * buttons are worse than instructions with none, and a folder of images nobody
 * can regenerate becomes exactly that within two releases.
 *
 * They are per language because the interface is: a Dutch panel does not teach
 * a French reader where "nouvelle liste" is. Dutch, French and English are
 * captured. **Spanish falls back to the English images** — that market has no
 * catalogue, so there is no product page to photograph in it, and inventing one
 * would put a screenshot on the site of something that does not exist.
 */
class ListHelpController extends Controller
{
    /**
     * Languages there are real screenshots for.
     *
     * Anything else falls back to English rather than to nothing: a step with
     * no picture beside the two that have one reads as a page that failed to
     * load.
     */
    private const SHOT_LANGUAGES = ['nl', 'fr', 'en'];

    public function __invoke(CurrentMarket $current): Response
    {
        app(PageMeta::class)->set(
            title: __('site.lists_help.seo_title'),
            description: __('site.lists_help.seo_description'),
            canonical: url($current->url('lists-help')),
            // Indexable. "How do I make a wish list" is a real question with
            // real intent, and this is the page that answers it.
            robots: null,
        );

        $language = in_array($current->get()->language(), self::SHOT_LANGUAGES, true)
            ? $current->get()->language()
            : 'en';

        return Inertia::render('Lists/Help', [
            'shots' => [
                'find' => "/help/lists/{$language}/1-find.png",
                'choose' => "/help/lists/{$language}/2-choose-list.png",
                'lists' => "/help/lists/{$language}/3-your-lists.png",
            ],
            'urls' => [
                'search' => $current->url('search'),
                'lists' => $current->url('lists'),
                'gift' => $current->url('gift'),
            ],
        ]);
    }
}
