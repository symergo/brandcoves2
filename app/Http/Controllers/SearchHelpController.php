<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\Seo\PageMeta;
use App\Support\CurrentMarket;
use Inertia\Inertia;
use Inertia\Response;

/**
 * How the search box works, and how the scanner does.
 *
 * ## Why a page rather than hints around the field
 *
 * The search box accepts four different things — words, a barcode, an Amazon
 * URL, and a query it will forgive the spelling of — and it looks exactly like
 * every other search box on the internet. Placeholder text can carry one of
 * those, tooltips carry them to nobody on a phone, and a field that explains
 * itself in four lines has stopped being a field. So the box stays plain and
 * the explanation lives somewhere it can be linked to, read, and indexed.
 *
 * ## Copy in the language files, not markdown on disk
 *
 * Unlike about/privacy/terms (see `LegalController`), which are single long
 * documents reviewed as a whole in two languages. This page is a handful of
 * short strings that have to exist in **all four** market languages the moment
 * it ships — a market reading English instructions for a Dutch search box is a
 * worse failure here than on a legal page, because the reader is mid-task.
 * The per-language `site.php` files are where every other in-product sentence
 * already lives.
 *
 * ## No data, deliberately
 *
 * It would be easy to print how many products the market carries. That number
 * is the catalogue-counter mistake from homepage.md: it flatters us, it dates
 * instantly, and it answers a question nobody standing in a shop is asking.
 */
class SearchHelpController extends Controller
{
    public function __invoke(CurrentMarket $current): Response
    {
        app(PageMeta::class)->set(
            title: __('site.search_help.seo_title'),
            description: __('site.search_help.seo_description'),
            canonical: url($current->url('search-help')),
            // Indexable. "How do I scan a barcode to compare prices" is a real
            // query with real intent, and this is the page that answers it.
            robots: null,
        );

        return Inertia::render('SearchHelp', [
            'urls' => [
                'search' => $current->url('search'),
                'scan' => $current->url('scan'),
            ],
        ]);
    }
}
