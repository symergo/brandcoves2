<?php

declare(strict_types=1);

namespace App\Services\Gift;

use App\Enums\EventType;
use App\Enums\Market;
use App\Models\ProductGroup;
use App\Models\Recipient;

/**
 * Three ideas for somebody's birthday, to put in the reminder email.
 *
 * Matched to the person the way Find a gift would match them (their
 * saved taste, their budget, the occasion), with everything they were already
 * given left out. The first idea is the best "next step" after a past gift
 * when there is one, because "last year the moka pot, now the grinder" is the
 * idea a giver could not have had from the finder alone; the rest come from
 * the SuggestionEngine.
 *
 * Runs in the reminder job, never in a request, and never calls AI: the
 * engine is retrieval and arithmetic (docs/features/ai-invariant.md).
 */
final class ReminderIdeas
{
    public function __construct(
        private readonly SuggestionEngine $engine,
        private readonly GiftHistory $history,
        private readonly NextSteps $nextSteps,
    ) {}

    /**
     * @param  string|null  $occasion  an EventType value ("birthday"), or null for what the person carries
     * @return list<ProductGroup>
     */
    public function for(Recipient $recipient, Market $market, ?string $occasion = null, int $count = 3): array
    {
        $past = $this->history->for($recipient);
        $excluded = $this->history->excludedGroupIds($recipient, $past);

        $ideas = [];

        foreach ($this->nextSteps->forRecipient($recipient, $market, 1, $past) as $pick) {
            $ideas[] = $pick['group'];
        }

        $stored = TasteBrief::fromRecipient($recipient, $market, $count);

        /*
         * The brief Find a gift would build from the saved person, with
         * the occasion this reminder is about. Written out rather than
         * derived with a helper so TasteBrief, which other work shares, is
         * not changed for one caller.
         */
        $brief = new TasteBrief(
            market: $market,
            interests: $stored->interests,
            vibe: $stored->vibe,
            preferences: $stored->preferences,
            budgetMin: $stored->budgetMin,
            budgetMax: $stored->budgetMax,
            avoid: $stored->avoid,
            values: $stored->values,
            relationship: $stored->relationship,
            occasion: self::occasion($occasion) ?? $stored->occasion,
            ageBand: $stored->ageBand,
            excludeGroupIds: array_values(array_unique([
                ...$excluded,
                ...array_map(fn (ProductGroup $g) => $g->id, $ideas),
                ...$this->onTheirLists($recipient),
            ])),
            limit: $count,
            // The reminder goes to the owner: an idea they turned down for
            // this person stays out of it too (docs/features/find-a-gift.md).
            recipientId: $recipient->id,
        );

        foreach ($this->engine->suggest($brief) as $pick) {
            if (count($ideas) >= $count) {
                break;
            }

            $ideas[] = $pick->group;
        }

        return array_slice($ideas, 0, $count);
    }

    /** An occasion the engine knows, or null. "other" says nothing. */
    private static function occasion(?string $value): ?string
    {
        $type = $value === null ? null : EventType::tryFrom($value);

        return $type === null || $type === EventType::Other ? null : $type->value;
    }

    /**
     * Products already on the owner's lists for this person. They are the
     * owner's own research; suggesting them back as ideas would be noise.
     *
     * @return list<int>
     */
    private function onTheirLists(Recipient $recipient): array
    {
        return $recipient->wishlists()
            ->join('wishlist_items', 'wishlist_items.wishlist_id', '=', 'wishlists.id')
            ->whereNotNull('wishlist_items.group_id')
            ->pluck('wishlist_items.group_id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }
}
