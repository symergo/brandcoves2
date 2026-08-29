<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\Reaction;
use App\Models\DailyPick;
use App\Models\PickReaction;
use App\Support\CurrentMarket;
use App\Support\Owner;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * 👍 / 👎 on a daily pick.
 *
 * A visitor write, so it stays the cheapest possible thing: one upsert and a
 * counter. No AI, no live API call, rate-limited at the route. The worst a
 * forged request can do is skew a count.
 *
 * These reactions are the only per-product feedback the site collects, and they
 * are what a future ranking loop would learn from — "what does *this* audience
 * find surprising" is a question no generic model can answer.
 */
class PickReactionController extends Controller
{
    public function __invoke(Request $request, CurrentMarket $current, string $market, string $pick): JsonResponse
    {
        $validated = $request->validate([
            'reaction' => ['required', 'string', 'in:'.implode(',', Reaction::values())],
        ]);

        $dailyPick = DailyPick::query()->findOrFail($pick);
        $owner = Owner::fromRequest($request);

        abort_unless($owner->exists(), 403);

        $reaction = Reaction::from($validated['reaction']);
        // Purpose-scoped, so a reaction hash cannot be joined against a gift
        // claim hash to link the same visitor across two features.
        $identity = $owner->identityHash('pick-reaction');

        /*
         * One reaction per visitor per pick, and changing your mind moves the
         * count rather than adding to it. Done in a transaction because the
         * counter and the row have to agree — a double-tap that increments
         * twice makes the number a lie, and the number is the whole feedback.
         */
        DB::transaction(function () use ($dailyPick, $identity, $reaction): void {
            $existing = PickReaction::query()
                ->where('pick_id', $dailyPick->id)
                ->where('identity_hash', $identity)
                ->lockForUpdate()
                ->first();

            // The model casts this column, so it comes back as an enum. Comparing
            // it to ->value silently never matches, and every re-tap would then
            // decrement a reaction the visitor still holds.
            $previous = $existing?->reaction instanceof Reaction
                ? $existing->reaction
                : ($existing === null ? null : Reaction::tryFrom((string) $existing->reaction));

            if ($previous === $reaction) {
                return;
            }

            if ($existing !== null) {
                if ($previous !== null) {
                    $this->adjust($dailyPick, $previous, -1);
                }

                $existing->update(['reaction' => $reaction->value]);
            } else {
                PickReaction::create([
                    'pick_id' => $dailyPick->id,
                    'identity_hash' => $identity,
                    'reaction' => $reaction->value,
                ]);
            }

            $this->adjust($dailyPick, $reaction, 1);
        });

        $fresh = $dailyPick->fresh();

        return response()->json([
            'mindblown' => $fresh->mindblown_count,
            'meh' => $fresh->meh_count,
            'mine' => $reaction->value,
        ]);
    }

    private function adjust(DailyPick $pick, Reaction $reaction, int $delta): void
    {
        $column = $reaction === Reaction::Mindblown ? 'mindblown_count' : 'meh_count';

        DailyPick::query()->whereKey($pick->id)->update([
            // Clamped at zero: a counter that can go negative because of one
            // bad migration is a counter nobody believes again.
            $column => DB::raw("GREATEST(0, {$column} + {$delta})"),
        ]);
    }
}
