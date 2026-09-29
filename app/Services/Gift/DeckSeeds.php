<?php

declare(strict_types=1);

namespace App\Services\Gift;

use App\Enums\Gender;
use App\Enums\Interest;
use App\Enums\Market;
use App\Enums\RecipientType;
use App\Models\Recipient;
use App\Models\User;

/**
 * What is already known about who the game is for, as a DeckSeed.
 *
 * Until 2026-09-29 both games started from the same random deck for anybody:
 * "for Mum" changed where a save went, never which cards came. Now:
 *
 * - **A saved person**: their stored taste, with their own "Mijn smaak" over
 *   it when they are a friend (TasteBrief::fromRecipient, OwnTaste), as
 *   `known`; their age; and the relationship they are, as below.
 * - **A relationship** ("mama"): the interests typical of it, as `explore`
 *   (`gift_landings.hub_interests_by_recipient`, the list the recipient pages
 *   use), its exclusions as `avoid` (`gift_landings.excluded_pairs`: no
 *   drinks, coffee or hunting for a child), and products tagged for somebody
 *   else left out.
 * - **Yourself, signed in**: your own "Mijn smaak", as `known`.
 *
 * Words typed as interests ("zuurdesem") are not in the vocabulary a card
 * carries, so only enum values are used; they still reach the engine through
 * the brief on the results page.
 */
final class DeckSeeds
{
    public function __construct(private readonly OwnTaste $ownTaste) {}

    public function for(Market $market, ?Recipient $person, ?RecipientType $relationship, ?User $me, ?Gender $gender = null): DeckSeed
    {
        if ($person !== null) {
            $brief = TasteBrief::fromRecipient($person, $market);
            $type = app(GiftResults::class)->relationshipType($person->relationship, $market) ?? $relationship;
            $typical = $this->typical($type);

            return new DeckSeed(
                known: self::interests($brief->interests),
                explore: $typical->explore,
                avoid: array_values(array_unique([...self::avoided($brief->avoid), ...$typical->avoid])),
                ageBand: $brief->ageBand,
                recipient: $type?->value,
                // Said now, else stored on the person, else what the relation says.
                gender: ($gender ?? Gender::tryFrom((string) $person->gender) ?? $type?->impliedGender())?->value,
            );
        }

        if ($relationship !== null) {
            $typical = $this->typical($relationship);

            return new DeckSeed(
                explore: $typical->explore,
                avoid: $typical->avoid,
                recipient: $typical->recipient,
                gender: ($gender ?? $relationship->impliedGender())?->value,
            );
        }

        if ($gender !== null) {
            return new DeckSeed(gender: $gender->value);
        }

        $own = $me === null ? null : $this->ownTaste->of($me);

        if ($own !== null) {
            return new DeckSeed(
                known: self::interests((array) $own->interests),
                avoid: self::avoided((array) $own->avoid),
                ageBand: $own->age_band,
            );
        }

        return DeckSeed::none();
    }

    private function typical(?RecipientType $type): DeckSeed
    {
        if ($type === null) {
            return DeckSeed::none();
        }

        return new DeckSeed(
            explore: self::interests((array) config("giftcoves.gift_landings.hub_interests_by_recipient.{$type->value}", [])),
            avoid: self::interests((array) config("giftcoves.gift_landings.excluded_pairs.{$type->value}", [])),
            recipient: $type->value,
        );
    }

    /**
     * @param  array<mixed>  $values
     * @return list<string>
     */
    private static function interests(array $values): array
    {
        return array_values(array_unique(array_filter(
            array_map('strval', $values),
            fn (string $v) => Interest::tryFrom($v) !== null,
        )));
    }

    /**
     * What to avoid, as interests: "interest:gaming" as This or that learns
     * it, or a word that is an interest's own value. Other words ("wol")
     * cannot be read off a card and are left to the engine.
     *
     * @param  array<mixed>  $entries
     * @return list<string>
     */
    private static function avoided(array $entries): array
    {
        $prefix = GiftTags::INTEREST.':';

        return self::interests(array_map(
            fn ($e) => str_starts_with((string) $e, $prefix) ? substr((string) $e, strlen($prefix)) : mb_strtolower(trim((string) $e)),
            $entries,
        ));
    }
}
