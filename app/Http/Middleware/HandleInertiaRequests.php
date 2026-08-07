<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Enums\Market;
use App\Support\CurrentMarket;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Lang;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    protected $rootView = 'app';

    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Bust the client cache when translations change.
     *
     * Without this a visitor who has the page cached keeps the old strings
     * after a copy fix, and the Inertia asset version alone would not notice
     * because no JS or CSS changed.
     */
    private function translationVersion(): string
    {
        return (string) filemtime(lang_path(app()->getLocale().'/site.php'));
    }

    /**
     * Shared with every Inertia page.
     *
     * Kept deliberately small — this payload is serialised into every single
     * response, including partial reloads.
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        $market = app(CurrentMarket::class)->get();

        return [
            ...parent::share($request),

            'auth' => [
                'user' => $request->user() === null ? null : [
                    'id' => $request->user()->id,
                    'name' => $request->user()->name,
                    'email' => $request->user()->email,
                    'isAdmin' => $request->user()->is_admin,
                ],
            ],

            'market' => [
                'key' => $market->value,
                'label' => $market->label(),
                'language' => $market->language(),
                'hrefLang' => $market->hrefLang(),
                'currency' => $market->currency(),
            ],

            // The switcher needs every market, and it is a fixed five — cheaper
            // to ship than to fetch.
            'markets' => array_map(fn (Market $m) => [
                'key' => $m->value,
                'label' => $m->label(),
                'nativeName' => $m->nativeName(),
            ], Market::cases()),

            /*
             * Site copy for the current market's language.
             *
             * Shipped whole rather than fetched: it is a few kilobytes, and a
             * separate request would mean the first paint shows translation
             * keys. Keyed by language, so be-nl and nl-nl share one file —
             * they are two markets, not two languages.
             */
            'translations' => Lang::get('site'),
            'translationVersion' => $this->translationVersion(),

            'flash' => [
                'success' => fn () => $request->session()->get('success'),
                'error' => fn () => $request->session()->get('error'),
            ],
        ];
    }
}
