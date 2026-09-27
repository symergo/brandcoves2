<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\Thumb;
use App\Models\ProductGroup;
use App\Models\Recipient;
use App\Services\Gift\GiftFeedback;
use App\Support\CurrentMarket;
use App\Support\Owner;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * A thumb on one of Find a gift's ideas, without leaving the page.
 *
 * Thumbs up, and taking a thumb back, from every results page; thumbs down
 * from the pages that cannot re-rank on the spot (This or that's result, a gift
 * landing page), which hide the card themselves. On the questions' board a
 * thumb down is the swap (GiftController::swap), which records it and draws
 * the replacement in one request. See docs/features/find-a-gift.md.
 *
 * A visitor write, so it stays cheap: an upsert or two, no AI, throttled at
 * the route. The worst a forged request can do is one vote per visitor
 * identity, which is exactly what the crowd signal already assumes.
 */
class GiftFeedbackController extends Controller
{
    public function __invoke(Request $request, CurrentMarket $current, GiftFeedback $feedback): JsonResponse
    {
        $validated = $request->validate([
            'group_id' => ['required', 'integer', 'min:1'],
            // Empty takes the thumb back.
            'vote' => ['nullable', 'string', 'in:'.implode(',', Thumb::values())],
            'recipient_id' => ['nullable', 'uuid'],
            'relationship' => ['nullable', 'string', 'max:40'],
        ]);

        $owner = Owner::fromRequest($request);

        // Nobody to count once: no cookie identity and no account.
        abort_unless($owner->exists(), 403);

        // Only a product of this market (invariant 2).
        abort_unless(
            ProductGroup::query()->forMarket($current->get())->whereKey($validated['group_id'])->exists(),
            404,
        );

        // Owner-scoped: somebody else's person is ignored, as if not sent.
        $recipient = Str::isUuid((string) ($validated['recipient_id'] ?? ''))
            ? $owner->scope(Recipient::query())->find($validated['recipient_id'])
            : null;

        $vote = Thumb::tryFrom((string) ($validated['vote'] ?? ''));

        $feedback->record(
            $owner,
            (int) $validated['group_id'],
            $vote,
            $recipient,
            $validated['relationship'] ?? null,
            $current->get(),
        );

        return response()->json(['vote' => $vote?->value]);
    }
}
