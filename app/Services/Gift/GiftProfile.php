<?php

declare(strict_types=1);

namespace App\Services\Gift;

use App\Enums\Interest;
use App\Enums\Preference;

/**
 * The profile a gift profile card carries, and the two things made from it:
 * Find a gift's answers, and a line of words for the card and its title.
 *
 * A card keeps the conclusion (interests, a budget, what to leave out), never
 * the choices it came from. The conclusion is worked out on the server from
 * the choices at the moment the card is made, so what a card says is what the
 * catalogue said, not what a request claimed. Every value is checked again on
 * the way out, against the same lists Find a gift validates with, so a
 * card stored under yesterday's vocabulary cannot break today's form.
 * See docs/features/gift-profile-card.md.
 */
final class GiftProfile
{
    /** How many interests a card names. The profiler finds four at most. */
    public const MAX_INTERESTS = 4;

    /**
     * What to store from a profile: its conclusion, no scores, no round count.
     *
     * @return array{interests: list<string>, avoid: list<string>, budgetMin: int|null, budgetMax: int|null, preferences: list<string>}
     */
    public static function fromProfile(TasteProfile $profile): array
    {
        return self::clean([
            'interests' => $profile->interests,
            'avoid' => $profile->avoid,
            'budgetMin' => $profile->budgetMin,
            'budgetMax' => $profile->budgetMax,
            'preferences' => $profile->preferences,
        ]);
    }

    /**
     * Only values Find a gift accepts, within its caps.
     *
     * @param  array<string, mixed>  $stored
     * @return array{interests: list<string>, avoid: list<string>, budgetMin: int|null, budgetMax: int|null, preferences: list<string>}
     */
    public static function clean(array $stored): array
    {
        $interests = array_values(array_unique(array_filter(
            array_map('strval', (array) ($stored['interests'] ?? [])),
            fn (string $i) => Interest::tryFrom($i) !== null,
        )));

        $avoid = array_values(array_unique(array_filter(
            array_map('strval', (array) ($stored['avoid'] ?? [])),
            fn (string $i) => Interest::tryFrom($i) !== null && ! in_array($i, $interests, true),
        )));

        $min = is_numeric($stored['budgetMin'] ?? null) ? max(0, (int) $stored['budgetMin']) : null;
        $max = is_numeric($stored['budgetMax'] ?? null) ? max(0, (int) $stored['budgetMax']) : null;

        // A band is both ends, the right way round, or nothing.
        if ($min === null || $max === null || $max < $min) {
            [$min, $max] = [null, null];
        }

        $preferences = [];
        $axes = [];

        foreach ((array) ($stored['preferences'] ?? []) as $pole) {
            $preference = Preference::tryFrom((string) $pole);

            if ($preference !== null && ! in_array($preference->axis(), $axes, true)) {
                $preferences[] = $preference->value;
                $axes[] = $preference->axis();
            }
        }

        return [
            'interests' => array_slice($interests, 0, self::MAX_INTERESTS),
            'avoid' => array_slice($avoid, 0, TasteProfiler::MAX_AVOID),
            'budgetMin' => $min,
            'budgetMax' => $max,
            'preferences' => array_slice($preferences, 0, TasteProfile::MAX_PREFERENCES),
        ];
    }

    /** Anything on it worth sharing: an interest or a budget. */
    public static function isEmpty(array $stored): bool
    {
        $clean = self::clean($stored);

        return $clean['interests'] === [] && $clean['budgetMin'] === null;
    }

    /**
     * Find a gift's answers, in the shape its page keeps them.
     *
     * Euros for the budget, because the wizard's field is in euros (see
     * GiftController::validateBrief), and an avoided interest in the tag's
     * own spelling, `interest:gaming`, so the engine leaves it out by tag and
     * never by a word in a title (TasteBrief::avoidedInterests).
     *
     * @param  array<string, mixed>  $stored
     * @return array<string, mixed>
     */
    public static function brief(array $stored): array
    {
        $clean = self::clean($stored);

        return [
            'interests' => $clean['interests'],
            'preferences' => $clean['preferences'],
            'budget_min' => $clean['budgetMin'] === null ? null : intdiv($clean['budgetMin'], 100),
            'budget_max' => $clean['budgetMax'] === null ? null : intdiv($clean['budgetMax'], 100),
            'avoid' => array_map(fn (string $i) => GiftTags::interest($i), $clean['avoid']),
            'relationship' => null,
            'occasion' => null,
            'age_band' => null,
            'recipient_id' => null,
            'remember' => false,
        ];
    }

    /**
     * "coffee, walking, around €30 to €60", in the reader's language.
     *
     * @param  array<string, mixed>  $stored
     */
    public static function summary(array $stored): string
    {
        $clean = self::clean($stored);

        $parts = array_map(fn (string $i) => mb_strtolower(Interest::from($i)->label()), $clean['interests']);

        if ($clean['budgetMin'] !== null) {
            $parts[] = __('site.gift.taste.budget', [
                'min' => '€'.intdiv($clean['budgetMin'], 100),
                'max' => '€'.intdiv((int) $clean['budgetMax'], 100),
            ]);
        }

        return implode(', ', $parts);
    }
}
