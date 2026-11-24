<?php

declare(strict_types=1);

namespace App\Services\Search;

use App\Enums\AlertState;
use App\Enums\Market;
use App\Models\SearchAlert;
use App\Models\User;

/**
 * "Hou me op de hoogte": whether the visitor already follows a search term, for
 * the bell beside the title (WatchSearch.tsx). Moved out of SearchController on
 * 2026-11-24 so the brand page has the same bell, on the brand plus its words.
 */
final class SearchWatch
{
    /**
     * @return array{watching: bool, id: int|null, maxPrice: int|null, requiresAccount: bool}|null
     */
    public static function for(?User $user, Market $market, string $term): ?array
    {
        if (trim($term) === '') {
            return null;
        }

        $alert = $user === null ? null : SearchAlert::query()
            ->where('user_id', $user->id)
            ->where('market', $market->value)
            ->where('term', SearchAlert::normalise($term))
            ->where('state', AlertState::Active->value)
            ->first(['id', 'max_price']);

        return [
            'watching' => $alert !== null,
            'id' => $alert?->id,
            'maxPrice' => $alert?->max_price,
            'requiresAccount' => $user === null,
        ];
    }
}
