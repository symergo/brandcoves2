<?php

declare(strict_types=1);

namespace App\Support;

use App\Enums\Market;

/**
 * The two axes a visitor chooses on: where they buy, and what they read.
 *
 * ## Why this is not just a list of markets
 *
 * A market value is already a pair — `be-nl` is Belgium in Dutch — so one
 * dropdown of five of them makes the visitor pick a compound they never thought
 * in. Nobody has ever wanted "BE/FR"; they have wanted Belgium, in French.
 * Splitting the control means splitting the value, and that has to happen
 * somewhere both halves agree on.
 *
 * ## The matrix is sparse, so the country carries the languages
 *
 * There are three published countries and three languages, and only four of the
 * nine pairs exist. Two free-running dropdowns would let anyone ask for Dutch in
 * Europe or French in the Netherlands, neither of which is a place. So each
 * country carries **its own markets and no others**, which makes an impossible
 * pair unaskable rather than merely rejected.
 *
 * ## English is a flag, not an option under every country
 *
 * English is always one click away, because the visitor it exists for — someone
 * who reads neither Dutch nor French — is exactly the visitor who cannot read
 * their way out of a Dutch menu to find it. It is always reachable because the
 * European flag is always on screen, not because every country's language list
 * has been padded with it.
 *
 * That is the honest shape: there is one English market and its country is `EU`,
 * so English *is* a market choice here rather than a language choice. Listing it
 * under the Dutch flag would have offered a language by quietly moving the
 * visitor to another catalogue.
 */
final class MarketSwitcher
{
    /**
     * One entry per published country, each with the languages it is read in.
     *
     * @return list<array{
     *     country: string,
     *     name: string,
     *     languages: list<array{language: string, name: string, market: string}>,
     * }>
     */
    public function payload(): array
    {
        $byCountry = [];

        foreach (Market::published() as $market) {
            $byCountry[$market->country()][] = $market;
        }

        $out = [];

        foreach (Market::countries() as $country) {
            $markets = $byCountry[$country] ?? [];

            if ($markets === []) {
                continue;
            }

            $out[] = [
                'country' => $country,
                'name' => __('site.nav.countries.'.strtolower($country)),
                'languages' => array_map(fn (Market $m): array => [
                    'language' => $m->language(),
                    'name' => $m->languageName(),
                    'market' => $m->value,
                ], $markets),
            ];
        }

        return $out;
    }
}
