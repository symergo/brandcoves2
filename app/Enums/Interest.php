<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The interests the gift wizard offers.
 *
 * A closed vocabulary rather than free text, for three reasons: the labels have
 * to be translated into four languages, the angle map is keyed on them, and a
 * person describing someone they love does better picking from a list than
 * facing an empty box. Free text is still accepted alongside these — see
 * `Recipient::interests` — it just does not get a curated angle.
 */
enum Interest: string
{
    case Cooking = 'cooking';
    case Coffee = 'coffee';
    case Photography = 'photography';
    case Music = 'music';
    case Gaming = 'gaming';
    case Reading = 'reading';
    case Fitness = 'fitness';
    case Outdoors = 'outdoors';
    case Travel = 'travel';
    case Gardening = 'gardening';
    case Diy = 'diy';
    case Beauty = 'beauty';
    case Fashion = 'fashion';
    case Tech = 'tech';
    case Home = 'home';
    case Craft = 'craft';
    case Film = 'film';
    case Pets = 'pets';
    case Wellness = 'wellness';
    case Kids = 'kids';

    /*
     * Sixteen more (2026-09-14, owner's call). Each is a hobby with products
     * of its own: a chip on the wizard, a seed of Dutch product nouns in
     * AngleMap, a label in four languages, and a tag an editor can give a
     * product (`interest:cycling`). The first twenty were chosen for
     * breadth; these are chosen because a market stocks them, and a person
     * who cycles is not well served by "fitness".
     */
    case Art = 'art';
    case Cycling = 'cycling';
    case BoardGames = 'boardgames';
    case Drinks = 'drinks';
    case Baking = 'baking';
    case Running = 'running';
    case Yoga = 'yoga';
    case Cars = 'cars';
    case Science = 'science';
    case Water = 'water';
    case WinterSports = 'wintersports';
    case Football = 'football';
    case Collecting = 'collecting';
    case Nature = 'nature';
    case Fishing = 'fishing';
    case Horses = 'horses';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $i) => $i->value, self::cases());
    }

    /** Translated wizard label. The copy itself lives in the site lang files. */
    public function label(): string
    {
        return __('site.gift.interests.'.$this->value);
    }
}
