<?php

declare(strict_types=1);

namespace App\Services\Cove;

use App\Enums\Market;

/**
 * A day worth building an edition around.
 *
 * "International Pet Day" is a better reason to open a shopping page than
 * "Tuesday". The theme comes from a translation key rather than the config, so
 * one calendar serves five markets.
 */
final readonly class Observance
{
    /** @param list<string> $queries */
    public function __construct(
        public string $key,
        public array $queries = [],
    ) {}

    public function title(Market $market): string
    {
        return __("site.daily.observances.{$this->key}.title", [], $market->language());
    }

    public function blurb(Market $market): ?string
    {
        $blurb = __("site.daily.observances.{$this->key}.blurb", [], $market->language());

        // A missing translation returns the key. Better to show no blurb than a
        // dotted path to a reader.
        return str_contains($blurb, 'site.daily.observances') ? null : $blurb;
    }

    public function slug(): string
    {
        return 'observance-'.$this->key;
    }
}
