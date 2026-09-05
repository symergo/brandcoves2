<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The drawing on a Cove.
 *
 * A shelf of Coves is a shelf of writing, and writing has no photograph of its
 * own. Both shelves this covers used to prove that in their own way:
 * `/gift-ideas` took the first buyable product's picture, so a page about a
 * *person* wore a picture of a *thing* and changed its face whenever stock did;
 * `/guides` took nothing at all, so eight articles about how to shop arrived as
 * eight identical rectangles of text.
 *
 * So a Cove names a scene, and the scene is drawn rather than photographed.
 * `SceneIllustration` renders it in the same visual language as
 * `CoveIllustration` and `ListIllustration`: one `160x116` viewBox, one stroke
 * weight, `currentColor` for every line, the accent only as a translucent wash.
 * That is what lets a card change colour on hover and take the drawing with it,
 * and it is why these survive a palette change without being redrawn.
 *
 * ## One enum, two vocabularies
 *
 * `cove_plans.scene` and `daily_pick_sets.scene` are one column each, so what
 * they hold has to be one type — a second enum on the same column would mean a
 * cast that throws the first time an advice Cove met the persona cast.
 *
 * But the two vocabularies genuinely do not overlap. A persona is *a kind of
 * person* and an advice article is *a subject*, and offering "coffee" to
 * somebody writing about customs duty is offering a wrong answer. So the cases
 * are grouped and {@see self::forKind()} decides which a kind may use: one
 * column, one cast, one component, and a curation screen that only ever shows
 * the choices that mean something.
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
 * ## Why so few, and why two of them are defaults
 *
 * A drawing per Cove makes artwork a gate on the writing. These are *kinds* of
 * person and *kinds* of subject, so a new Cove almost always finds one that
 * fits — and the ones that do not get {@see self::Someone} or
 * {@see self::Article}, which are portraits rather than apologies.
 *
 * Null is allowed and resolves to whichever default the kind uses. The column
 * is nullable because every Cove written before this existed has no scene, and
 * a missing drawing must never be a missing page.
 */
enum CoveScene: string
{
    /*
    |--------------------------------------------------------------------------
    | Personas — kinds of person
    |--------------------------------------------------------------------------
    */

    /** Cup, steam, two beans. The one hobby that colonises a kitchen counter. */
    case Coffee = 'coffee';

    /** Pan, spoon, heat. Someone who cooks for other people. */
    case Cooking = 'cooking';

    /** Wheel and a chequered flag. The rig that never finishes being built. */
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
     * Watering can and a trowel in the ground.
     *
     * Distinct from {@see self::Plants}: this one is a season and a plot, and
     * the gift is a tool that has to survive a winter in a shed.
     */
    case Gardening = 'gardening';

    /**
     * A potted plant on a sill.
     *
     * The indoor half, and a different shopper entirely — pots, misters and
     * grow lights rather than spades. Drawn as one plant rather than a row,
     * because a row reads as a garden centre.
     */
    case Plants = 'plants';

    /** Headphones over a record. Someone who listens on purpose. */
    case Music = 'music';

    /**
     * A stack of books and a reading lamp.
     *
     * Not an open book on a shelf — `CoveIllustration`'s `idea` scene is
     * already that, and it means "the archive of writing" on the homepage. Two
     * drawings meaning different things must not be the same drawing.
     */
    case Reading = 'reading';

    /**
     * A controller.
     *
     * Separate from {@see self::Racing}, which is a wheel and a flag: a sim rig
     * and a console are different shelves and different money, and a reader
     * recognises which one is theirs from the mark alone.
     */
    case Gaming = 'gaming';

    /** A dumbbell on a rolled mat. Training that happens at home. */
    case Fitness = 'fitness';

    /** A case with a luggage tag. Someone who is about to go somewhere. */
    case Travel = 'travel';

    /** Whisk, bowl and a rolling pin. The precise end of cooking. */
    case Baking = 'baking';

    /**
     * A figure, and nothing that says what they are into.
     *
     * The honest default for a persona. A missing drawing on a shelf of
     * drawings reads as a page that failed to load, so this is a portrait
     * rather than an empty box.
     */
    case Someone = 'someone';

    /*
    |--------------------------------------------------------------------------
    | Articles — kinds of subject
    |--------------------------------------------------------------------------
    |
    | One per shipped Advice Cove, because those are a fixed set that ships in
    | `resources/content/advice-coves.php` rather than a vocabulary anybody
    | picks from freely. Each draws the article's own subject: the thing the
    | reader is being warned about or told how to use, not a generic document
    | with a tick on it.
    */

    /** A receipt under a pair of scales. What the law gives you. */
    case Rights = 'rights';

    /** A price tag, and the line behind it saying what it used to cost. */
    case PriceHistory = 'price_history';

    /** A box with two labels on it. The shop you clicked, and who packed it. */
    case Seller = 'seller';

    /** A row of stars with a lens over it. */
    case Reviews = 'reviews';

    /** A device inside a box that has already been opened once. */
    case Refurbished = 'refurbished';

    /** A shopfront under a magnifier. Is this a business or a page? */
    case ShopCheck = 'shop_check';

    /** A phone showing a message, and the hook in it. */
    case Phishing = 'phishing';

    /** A parcel at a barrier, with a form attached. */
    case Customs = 'customs';

    /** A wrapped parcel and a receipt beside it. Whose purchase is this? */
    case GiftReturn = 'gift_return';

    /** A doorstep with nothing on it. */
    case MissingParcel = 'missing_parcel';

    /**
     * Pages of an article, and nothing saying which one.
     *
     * The default for an article kind. Guides and seasonal guides carry no
     * scene today — their substance is a shortlist of products — and the
     * `/guides` shelf they share with the advice articles must not be half
     * illustrated and half blank.
     */
    case Article = 'article';

    /** The label a curator picks from. */
    public function label(): string
    {
        return match ($this) {
            self::Coffee => 'Coffee',
            self::Cooking => 'Cooking',
            self::Racing => 'Sim racing',
            self::HasEverything => 'Has everything',
            self::Dog => 'Dogs and pets',
            self::Photography => 'Photography',
            self::Diy => 'DIY and tools',
            self::Outdoors => 'Outdoors',
            self::Gardening => 'Gardening',
            self::Plants => 'House plants',
            self::Music => 'Music and listening',
            self::Reading => 'Reading',
            self::Gaming => 'Gaming',
            self::Fitness => 'Fitness at home',
            self::Travel => 'Travel',
            self::Baking => 'Baking',
            self::Someone => 'No particular interest',

            self::Rights => 'Consumer rights',
            self::PriceHistory => 'Prices and discounts',
            self::Seller => 'Who is selling',
            self::Reviews => 'Reviews',
            self::Refurbished => 'Refurbished and second-hand',
            self::ShopCheck => 'Judging a shop',
            self::Phishing => 'Scam messages',
            self::Customs => 'Customs and importing',
            self::GiftReturn => 'Returning a gift',
            self::MissingParcel => 'Delivery gone wrong',
            self::Article => 'No particular subject',
        };
    }

    /**
     * The scene a Cove of this kind falls back to when it names none.
     *
     * Two defaults rather than one, because the two shelves are drawings of
     * different things: an unlabelled persona is still a person, and an
     * unlabelled article is still an article. Sharing one default would put a
     * portrait at the top of a piece about customs duty.
     */
    public static function defaultFor(CoveKind $kind): self
    {
        return $kind === CoveKind::Persona ? self::Someone : self::Article;
    }

    /**
     * The scenes a Cove of this kind may name.
     *
     * Asked rather than listed at each call site: the curation screen, the API
     * validation and the seeder all need the same answer, and three copies of
     * one list is a list that will be two places out of date.
     *
     * A kind with no vocabulary of its own — a Daily, a Shop Cove — gets an
     * empty list, which is how the planner knows not to offer the field at all.
     * Neither draws one: a Daily is addressed by its date and carries the day's
     * products, and a Shop Cove is about a named shop with a name to print.
     *
     * @return list<self>
     */
    public static function forKind(CoveKind $kind): array
    {
        return match ($kind) {
            CoveKind::Persona => [
                self::Coffee, self::Cooking, self::Racing, self::HasEverything,
                self::Dog, self::Photography, self::Diy, self::Outdoors,
                self::Gardening, self::Plants, self::Music, self::Reading,
                self::Gaming, self::Fitness, self::Travel, self::Baking,
                self::Someone,
            ],
            CoveKind::Guide, CoveKind::Seasonal, CoveKind::Advice => [
                self::Rights, self::PriceHistory, self::Seller, self::Reviews,
                self::Refurbished, self::ShopCheck, self::Phishing,
                self::Customs, self::GiftReturn, self::MissingParcel,
                self::Article,
            ],
            CoveKind::Daily, CoveKind::Shop => [],
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $s) => $s->value, self::cases());
    }

    /**
     * value => label, for a select.
     *
     * Scoped to a kind when one is given, which is what the planner passes —
     * see {@see self::forKind()}.
     *
     * @return array<string, string>
     */
    public static function options(?CoveKind $kind = null): array
    {
        $cases = $kind === null ? self::cases() : self::forKind($kind);

        $out = [];

        foreach ($cases as $case) {
            $out[$case->value] = $case->label();
        }

        return $out;
    }
}
