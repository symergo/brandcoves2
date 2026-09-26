<?php

declare(strict_types=1);

namespace App\Services\Gift;

use App\Enums\Interest;
use App\Enums\Market;
use App\Enums\RecipientType;
use App\Models\GiftLanding;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Which gift landing pages deserve to exist tonight.
 *
 * Walks every recipient × interest, asks the suggestion engine what it would
 * show, and records the pairs that answer with at least
 * `gift_landings.min_products` distinct products that fit the interest. A
 * pair that falls under it loses its row, and its page answers 404 from then
 * on. Then each recipient with at least one page gets a page of their own,
 * built from their best interests.
 *
 * "Fit the interest" is the engine's own verdict (`Suggestion::
 * matchedInterests`), not "the engine returned something": with nothing
 * matching, the engine falls back to a budget browse so Find a gift is
 * never empty, and a page of that would be "gifts for dad who loves fishing"
 * showing a candle.
 *
 * No AI anywhere: the engine is retrieval and arithmetic. Recipient ×
 * occasion is not walked yet (owner's plan, recipient × interest first).
 */
class GiftLandingPlanner
{
    public function __construct(private readonly SuggestionEngine $engine) {}

    /** @return array{recorded: int, removed: int} */
    public function plan(Market $market): array
    {
        // Whole seconds, because the column stores whole seconds: a row
        // checked in the same second the run began must not read as older.
        $started = Carbon::now()->startOfSecond();
        $counts = [];

        foreach (RecipientType::cases() as $recipient) {
            foreach ($this->interests() as $interest) {
                $brief = BriefUrl::toBrief($market, $recipient, $interest, limit: $this->pageSize());
                $count = $this->count($brief);

                if ($count >= $this->minimum()) {
                    $this->record($market, $recipient, $interest, $brief, $count);
                    $counts[$recipient->value][$interest->value] = $count;
                }
            }

            if (! empty($counts[$recipient->value])) {
                $this->recordHub($market, $recipient, $counts[$recipient->value]);
            }
        }

        // Whatever was not recorded this run no longer earns its page.
        $removed = GiftLanding::query()
            ->forMarket($market)
            ->where('checked_at', '<', $started)
            ->delete();

        return [
            'recorded' => GiftLanding::query()->forMarket($market)->count(),
            'removed' => $removed,
        ];
    }

    /**
     * How many distinct products answer this brief well enough for a page.
     */
    public function count(TasteBrief $brief): int
    {
        $recipientTag = $brief->relationship === null ? null : GiftTags::recipient($brief->relationship);
        $needRecipient = (int) config('giftcoves.gift_landings.min_recipient_matches', 0);

        $fitting = 0;
        $forRecipient = 0;
        $seen = [];

        foreach ($this->engine->suggest($brief) as $suggestion) {
            if ($suggestion->matchedInterests === [] || isset($seen[$suggestion->group->id])) {
                continue;
            }

            $seen[$suggestion->group->id] = true;
            $fitting++;

            if ($recipientTag !== null && (
                in_array($recipientTag, $suggestion->group->giftTags(), true)
                || in_array($recipientTag, $suggestion->group->crowdTags(), true)
            )) {
                $forRecipient++;
            }
        }

        return $forRecipient >= $needRecipient ? $fitting : 0;
    }

    /**
     * @param  array<string, int>  $byInterest  interest => products, for this recipient
     */
    private function recordHub(Market $market, RecipientType $recipient, array $byInterest): void
    {
        arsort($byInterest);

        $best = array_slice(array_keys($byInterest), 0, (int) config('giftcoves.gift_landings.hub_interests', 3));

        $brief = new TasteBrief(
            market: $market,
            interests: $best,
            relationship: $recipient->value,
            limit: $this->pageSize(),
        );

        $count = $this->count($brief);

        if ($count >= $this->minimum()) {
            $this->record($market, $recipient, null, $brief, $count);
        }
    }

    private function record(Market $market, RecipientType $recipient, ?Interest $interest, TasteBrief $brief, int $count): void
    {
        $now = Carbon::now();
        $path = BriefUrl::path($market, $recipient, $interest);

        // A URL word changed in the lang file: the page moves, so the row
        // under the old address goes rather than colliding on the pair.
        GiftLanding::query()
            ->forMarket($market)
            ->where('recipient', $recipient->value)
            ->when($interest === null, fn ($q) => $q->whereNull('interest'), fn ($q) => $q->where('interest', $interest?->value))
            ->where('path', '!=', $path)
            ->delete();

        // One statement per page, keyed as the unique index is. updateOrCreate
        // cannot say "interest IS NULL", so the hub row would be inserted
        // again every night.
        DB::table('gift_landings')->upsert(
            [[
                'market' => $market->value,
                'recipient' => $recipient->value,
                'interest' => $interest?->value,
                'path' => $path,
                'brief' => json_encode($brief->toArray(), JSON_THROW_ON_ERROR),
                'product_count' => min($count, 65535),
                'checked_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ]],
            ['market', 'path'],
            ['recipient', 'interest', 'brief', 'product_count', 'checked_at', 'updated_at'],
        );
    }

    /** @return list<Interest> */
    private function interests(): array
    {
        $excluded = (array) config('giftcoves.gift_landings.excluded_interests', []);

        return array_values(array_filter(
            Interest::cases(),
            fn (Interest $interest) => ! in_array($interest->value, $excluded, true),
        ));
    }

    private function minimum(): int
    {
        return (int) config('giftcoves.gift_landings.min_products', 8);
    }

    private function pageSize(): int
    {
        return (int) config('giftcoves.gift_landings.page_size', 24);
    }
}
