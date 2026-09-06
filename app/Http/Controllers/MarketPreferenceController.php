<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\Market;
use App\Services\Seo\Alternates;
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
            // The page the switcher was pressed on. A path, never a URL: it is
            // only ever resolved through Alternates, never redirected to as
            // given, so it cannot send anybody off the site.
            'path' => ['nullable', 'string', 'max:2048', 'regex:#^/[^\s]*$#'],
        ]);

        $market = Market::from($validated['market']);

        return redirect($this->destination($market, $validated['path'] ?? null), 302)
            ->withCookie(MarketPreference::cookie($market));
    }

    /**
     * Where the switch lands: the same page in the new language, or the home.
     *
     * The market home used to be the only answer, on the reasoning that
     * product identity is market-scoped and swapping the prefix turns a
     * language change into a dead link. True across countries — a Dutch card
     * is not a Belgian one — and wrong within one: be-nl and be-fr are the
     * same catalogue, and a reader switching a product page to French lost the
     * product. `Alternates` already knows which pages have a twin, so the
     * question is asked of it: a twin in the chosen market means landing
     * there, and anything else means the home, as before.
     */
    private function destination(Market $market, ?string $path): string
    {
        $home = '/'.$market->value;

        if ($path === null) {
            return $home;
        }

        $from = Market::tryFrom((string) explode('/', trim($path, '/'))[0]);

        if ($from === null || $from === $market || $from->country() !== $market->country()) {
            return $home;
        }

        return app(Alternates::class)->for($path, $from)[$market->hrefLang()] ?? $home;
    }
}
