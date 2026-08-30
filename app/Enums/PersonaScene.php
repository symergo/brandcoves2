<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The drawing on a gift persona.
 *
 * A persona is about a *person*, and until now the shelf at `/gift-ideas` used
 * the first buyable product's photograph as its cover. That is the wrong
 * picture for this page twice over: it makes a persona look like a product
 * category, and the cover changes whenever stock does — the same page wearing a
 * different face each week, for a reason no reader can see.
 *
 * So a persona names a scene instead, and the scene is drawn rather than
 * photographed. `PersonaIllustration` renders it in the same visual language as
 * `CoveIllustration` and `ListIllustration`: one stroke weight, `currentColor`
 * for every line, the accent used only as a translucent wash.
 *
 * ## Why a field and not a map from the slug
 *
 * Slugs are per market. `de-koffiefanaat`, `le-fanatique-de-cafe` and whatever
 * the Spanish one is eventually called are one persona wearing three addresses,
 * and a lookup table keyed on the slug would need every one of them — so the
 * drawing would be correct in the markets somebody remembered and blank in the
 * rest, with nothing to say which was which.
 *
 * A field also puts the choice where the writing happens. The person who
 * decides this Cove is about someone who cooks is the person best placed to say
 * so, and they are already in the curation screen.
 *
 * ## Why these, and why so few
 *
 * Nine scenes, not one per persona. A drawing per persona would mean
 * commissioning artwork before anybody could publish, which makes the picture a
 * gate on the writing. These are *kinds of person* — the categories gift
 * shopping actually falls into — so a new persona almost always finds one that
 * fits, and the ones that do not get {@see self::Someone}, which is a portrait
 * rather than an apology.
 *
 * Null is allowed and means the same as `Someone`. The column is nullable
 * because every persona written before this existed has no scene and none of
 * them should have been blocked from rendering.
 */
enum PersonaScene: string
{
    /** Grinder, cup, steam. The one hobby that colonises a kitchen counter. */
    case Coffee = 'coffee';

    /** Pan, knife, board. Someone who cooks for other people. */
    case Cooking = 'cooking';

    /** Wheel and pedals. The rig that never finishes being built. */
    case Racing = 'racing';

    /**
     * A parcel on a doorstep, already opened.
     *
     * The person who has everything is defined by *speed* — they own it before
     * you thought of it — so the drawing is the delivery rather than the thing
     * delivered.
     */
    case HasEverything = 'has_everything';

    /** A dog and a bowl. The gift is for them; the animal uses it. */
    case Dog = 'dog';

    /** Camera body and a lens beside it. */
    case Photography = 'photography';

    /** Drill and a spirit level. Someone who does it themselves. */
    case Diy = 'diy';

    /** Boot, pack and a peak. Outdoors under their own power. */
    case Outdoors = 'outdoors';

    /**
     * A figure, and nothing that says what they are into.
     *
     * The honest default. A persona whose scene has not been chosen gets a
     * portrait rather than an empty box, because a missing drawing on a shelf
     * of drawings reads as a page that failed to load.
     */
    case Someone = 'someone';

    /** The label a curator picks from. */
    public function label(): string
    {
        return match ($this) {
            self::Coffee => 'Coffee',
            self::Cooking => 'Cooking',
            self::Racing => 'Sim racing and gaming',
            self::HasEverything => 'Has everything',
            self::Dog => 'Dogs and pets',
            self::Photography => 'Photography',
            self::Diy => 'DIY and tools',
            self::Outdoors => 'Outdoors',
            self::Someone => 'No particular interest',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $s) => $s->value, self::cases());
    }

    /** @return array<string, string> value => label, for a select. */
    public static function options(): array
    {
        $out = [];

        foreach (self::cases() as $case) {
            $out[$case->value] = $case->label();
        }

        return $out;
    }
}
