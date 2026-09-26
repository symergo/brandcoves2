<?php

declare(strict_types=1);

namespace App\Services\Gift;

use App\Enums\Market;
use App\Models\ProductGroup;

/**
 * Choices as the page sends them, turned into choices about real products.
 *
 * The page sends only ids and what was pressed. The tags and prices are read
 * here, from the catalogue, within the market (invariant 2), so a request can
 * say "I picked 41" and never "41 is a cooking present". An id that is not a
 * product in this market is dropped with its round; a pick that is not one of
 * the cards shown counts as a skip.
 */
final class TasteChoiceReader
{
    /**
     * @param  list<array{shown?: array<mixed>, picked?: mixed, verdict?: mixed}>  $raw
     * @return list<TasteChoice>
     */
    public function read(array $raw, Market $market): array
    {
        $ids = [];

        foreach ($raw as $round) {
            foreach ((array) ($round['shown'] ?? []) as $id) {
                $ids[] = (int) $id;
            }
        }

        if ($ids === []) {
            return [];
        }

        $cards = ProductGroup::query()
            ->forMarket($market)
            ->whereIn('id', array_values(array_unique($ids)))
            ->get(['id', 'gift_tags', 'crowd_tags', 'min_price'])
            ->mapWithKeys(fn (ProductGroup $group) => [(int) $group->id => TasteCard::fromGroup($group)]);

        $choices = [];

        foreach ($raw as $round) {
            $shown = array_map('intval', array_values((array) ($round['shown'] ?? [])));
            $found = array_values(array_filter(array_map(fn (int $id) => $cards[$id] ?? null, $shown)));

            // A pair with a card gone is not the choice that was made.
            if ($found === [] || count($found) !== count($shown) || count($found) > 2) {
                continue;
            }

            $picked = isset($round['picked']) ? (int) $round['picked'] : null;
            $verdict = isset($round['verdict']) ? (string) $round['verdict'] : null;

            $choices[] = count($found) === 2
                ? TasteChoice::pair($found[0], $found[1], in_array($picked, $shown, true) ? $picked : null)
                : TasteChoice::single($found[0], $verdict);
        }

        return $choices;
    }
}
