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
    // Proposed by the taggers, 2026-09-14: five products on be-fr had no
    // interest that fitted. The first value to arrive from the tagging pass
    // rather than from the wizard.
    case Hunting = 'hunting';
    // Split off from `tech`, which was carrying three different presents at
    // once (owner's call, 2026-09-14): a clever little thing, a computer, and
    // everything else with a plug.
    case Gadgets = 'gadgets';
    case It = 'it';
    // Four the gift-set tagging found missing (owner, 2026-09-30): chocolate
    // boxes, a good pen, a darts set and erotic gift sets had no interest
    // that fitted, so they could not be found by one.
    case Sweets = 'sweets';
    case Stationery = 'stationery';
    case PubGames = 'pubgames';
    // Erotic gifts. They appear like any other gift (owner, 2026-09-30),
    // except for a child: gift_landings.excluded_pairs.
    case Adult = 'adult';
    // Split off from `beauty` (owner, 2026-09-30, "waarom geen parfums?"): in
    // Swipe gifts an interest gets a card about once every forty, and a beauty
    // card was a hair dryer or mascara as often as a perfume, so perfume almost
    // never came up. A perfume keeps `beauty` too.
    case Perfume = 'perfume';
    // Two sports with a shelf of their own and nowhere to go (owner, 2026-11-26): be-nl
    // stocked 1,011 golf and 716 padel, tennis and badminton products under no interest.
    // Padel is big in Belgium and the Netherlands. Racket sports are one interest: a
    // padel player and a tennis player want much the same bag, balls and grip tape.
    case Golf = 'golf';
    case Racket = 'racket';
    // Split off from `boardgames` (owner, 2026-11-26: "bordspellen en puzzles zijn niet
    // hetzelfde"): a jigsaw is done alone at a table, a board game needs a table of people.
    // be-nl stocks 3,724 puzzles. A puzzle does not keep `boardgames`.
    case Puzzles = 'puzzles';
    // Knitting, crochet and sewing (owner, same day: "en breien en haken?"): 1,930 be-nl
    // products that "Zelf maken" hid. Yarn and a sewing machine keep `craft` too.
    case Needlework = 'needlework';
    // Two more the catalogue had no interest for (owner, 2026-11-26, after a count of the
    // largest untagged be-nl categories): the food itself, as distinct from `cooking`, which
    // is the gear (4,370 products: sauces, oil and vinegar, spices, delicatessen), and
    // motorcycles, which `cars` did not cover (1,334: helmets, jackets, gloves).
    case Delicacies = 'delicacies';
    case Motorcycles = 'motorcycles';

    /**
     * The wizard shows the interests under these headings (owner, 2026-11-26: "a more
     * compact way to select them"): 44 loose chips were fifteen rows on a phone with
     * nothing to scan by. Every case is in exactly one group, in the order shown;
     * InterestTest holds that.
     */
    public const GROUPS = [
        'food' => [self::Cooking, self::Baking, self::Delicacies, self::Coffee, self::Drinks, self::Sweets],
        'home' => [self::Home, self::Gardening, self::Diy, self::Pets],
        'tech' => [self::Tech, self::Gadgets, self::It, self::Gaming, self::Photography],
        'sport' => [self::Fitness, self::Running, self::Cycling, self::Yoga, self::Football, self::Racket, self::Golf, self::Water, self::WinterSports],
        'outdoors' => [self::Outdoors, self::Travel, self::Nature, self::Cars, self::Motorcycles, self::Horses, self::Fishing, self::Hunting],
        // Toys and puzzles first (owner, 2026-11-26: "ik mis spelen/speelgoed en puzzles"):
        // they were there, as "Kinderen" under Thuis and inside "Bordspellen", and not found.
        'culture' => [self::Kids, self::BoardGames, self::Puzzles, self::PubGames, self::Collecting, self::Reading, self::Music, self::Film, self::Science],
        'creative' => [self::Craft, self::Needlework, self::Art, self::Stationery],
        'care' => [self::Beauty, self::Perfume, self::Fashion, self::Wellness, self::Adult],
    ];

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
