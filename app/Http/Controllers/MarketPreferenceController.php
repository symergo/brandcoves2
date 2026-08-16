<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\Market;
use App\Support\MarketPreference;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Records the market a visitor picked in the switcher, then sends them to it.
 *
 * The switcher used to navigate straight to `/{market}`, which changed the page
 * and remembered nothing. This is the same navigation with the choice written
 * down on the way through — see {@see MarketPreference} for why the write has to
 * happen on a request only the switcher makes, and cannot simply be bolted onto
 * `SetMarket`.
 *
 * ## Why POST
 *
 * Not squeamishness about verbs: a GET here would be a URL that silently
 * rewrites the recipient's market preference, which is exactly the shape of
 * thing that gets pasted into a chat and clicked. As a POST it needs a CSRF
 * token, so only this site's own switcher can spend it. The cost is that the
 * switcher submits a form instead of setting `location.href` — and it was
 * already doing a full page load, so nothing about how it feels changes.
 *
 * Unprefixed, unlike every other public route: the market is the *payload*
 * here, so taking it from a `/{market}/` prefix as well would mean two sources
 * for one fact and a rule about which wins.
 */
class MarketPreferenceController extends Controller
{
    public function __invoke(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            // Published only, and enforced server-side rather than trusted from
            // the form. The switcher never renders an unpublished market, so a
            // request naming one did not come from the switcher.
            'market' => ['required', 'string', Rule::in(
                array_map(fn (Market $m): string => $m->value, Market::published()),
            )],
        ]);

        $market = Market::from($validated['market']);

        // The market home, not the equivalent of the current page. Product
        // identity is market-scoped, so the same path under another market is
        // usually a 404 — swapping the prefix would turn a language change into
        // a dead link. App\Services\Seo\Alternates does resolve equivalents,
        // but only for pages that genuinely have one, and only for crawlers.
        return redirect('/'.$market->value, 302)
            ->withCookie(MarketPreference::cookie($market));
    }
}
