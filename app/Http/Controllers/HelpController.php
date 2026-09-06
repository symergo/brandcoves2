<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\Seo\PageMeta;
use App\Support\CurrentMarket;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * One page for "how does this work" and "this is broken".
 *
 * ## Why the two belong together
 *
 * Somebody who cannot make the site do what they want has one question and does
 * not know which half of it they are in. Before this, the how-to pages were
 * reachable only from the place they explain - the search box linked its own
 * tips, the lists page linked its own - so a visitor who had already given up on
 * that screen had nowhere to go, and the feedback form sat on a page of its own
 * that they had to know to look for.
 *
 * So: the guides first, because most people are stuck rather than reporting a
 * fault, and the form underneath for whoever the guides did not help. The form
 * is the same component `/feedback` renders, not a copy - two forms posting to
 * one endpoint is how a honeypot ends up on one of them and not the other.
 *
 * ## What is listed
 *
 * Only pages that explain how to *use* the site. The advice articles under
 * `/guides` are editorial - what a crossed-out price is worth, whether a shop
 * can be trusted - and they have their own index. Mixing them in would make this
 * a second table of contents for the magazine.
 */
class HelpController extends Controller
{
    public function __invoke(Request $request, CurrentMarket $current): Response
    {
        app(PageMeta::class)->set(
            title: __('site.help.seo_title'),
            description: __('site.help.seo_description'),
            canonical: url($current->url('help')),
            // Indexable: "how do I ..." is a real query, and this is the page
            // that answers the ones about using the site.
            robots: null,
        );

        return Inertia::render('Help', [
            'guides' => [
                [
                    'key' => 'search',
                    'url' => $current->url('search-help'),
                ],
                [
                    'key' => 'lists',
                    'url' => $current->url('lists-help'),
                ],
            ],
            /*
             * The page they came from, for the form. `referer` is a header a
             * browser may withhold, so this is a hint rather than a fact - the
             * field is editable and the form works with it empty.
             */
            'path' => $this->camefrom($request),
        ]);
    }

    private function camefrom(Request $request): ?string
    {
        $referer = (string) $request->headers->get('referer');

        if ($referer === '') {
            return null;
        }

        $path = parse_url($referer, PHP_URL_PATH);

        // Only our own paths: a referer from somewhere else is not a page we
        // can do anything about, and echoing it back into a form is repeating
        // an external URL to ourselves.
        return is_string($path) && str_starts_with($path, '/') ? $path : null;
    }
}
