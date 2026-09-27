<?php

declare(strict_types=1);

namespace App\Services\Contribute;

use App\Enums\Market;
use App\Enums\ModerationStatus;
use App\Models\FeatureIdea;
use App\Models\User;

/**
 * A visitor suggesting a feature, and the one rule that keeps it honest:
 * **nothing a visitor writes reaches the board until a person publishes it**
 * in the admin (Community > Feature ideas).
 *
 * No AI screens it, on purpose. The site's rule is that AI never runs inside
 * a web request (invariant 1), and a queued screen would be a second opinion
 * on a queue that sees a handful of rows a week. The owner reads every one
 * anyway: that is what the board is for.
 *
 * Stored in the visitor's language only. The admin fills in the others when
 * it is published; until then the public page falls back to the language it
 * was written in (FeatureIdea::text()).
 */
final class FeatureSuggestions
{
    /**
     * Suggestions per person per day. Enough for somebody with several real
     * ideas; too few to fill the moderation queue with one account. The
     * route's throttle stops bursts, this stops a steady drip.
     */
    public const PER_DAY = 5;

    public function canSuggest(User $user): bool
    {
        return FeatureIdea::query()
            ->where('suggested_by', $user->id)
            ->where('created_at', '>=', now()->subDay())
            ->count() < self::PER_DAY;
    }

    public function suggest(User $user, Market $market, string $title, ?string $body): FeatureIdea
    {
        $language = $market->language();

        return FeatureIdea::query()->create([
            'title' => [$language => trim($title)],
            'body' => ($body !== null && trim($body) !== '') ? [$language => trim($body)] : [],
            'language' => $language,
            'status' => 'considering',
            'moderation' => ModerationStatus::Pending->value,
            'source' => FeatureIdea::SOURCE_VISITOR,
            'suggested_by' => $user->id,
            'market' => $market->value,
        ]);
    }
}
