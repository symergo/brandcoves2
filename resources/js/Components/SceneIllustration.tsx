import type { ReactNode } from 'react'

/**
 * Every scene `App\Enums\CoveScene` can name. Kept in the enum's order.
 *
 * A union of string literals rather than `string`, so a scene the server sends
 * and this file does not draw is a compile error rather than a blank card.
 */
export type SceneKey =
    // Personas — kinds of person.
    | 'coffee'
    | 'cooking'
    | 'racing'
    | 'has_everything'
    | 'dog'
    | 'photography'
    | 'diy'
    | 'outdoors'
    | 'gardening'
    | 'plants'
    | 'music'
    | 'reading'
    | 'gaming'
    | 'fitness'
    | 'travel'
    | 'baking'
    | 'someone'
    // Articles — kinds of subject.
    | 'rights'
    | 'price_history'
    | 'seller'
    | 'reviews'
    | 'refurbished'
    | 'shop_check'
    | 'phishing'
    | 'customs'
    | 'gift_return'
    | 'missing_parcel'
    | 'article'

/**
 * The drawing on a Cove — a gift persona, or an article about how to shop.
 *
 * Was `PersonaIllustration`, and covered only the first of those. Both shelves
 * had the same problem from opposite ends: `/gift-ideas` used the first buyable
 * product's photograph, so a page about a *person* wore a picture of a *thing*
 * and changed its face whenever stock did; `/guides` used nothing at all, so
 * eight articles about consumer law arrived as eight rectangles of text. A
 * shelf of writing has no photograph of its own, so it gets a drawing.
 *
 * One component and one visual language, because they sit on sibling shelves
 * and are reached from the same menu: one `160x116` viewBox, one stroke weight,
 * `currentColor` for every line, and the single accent used only as a
 * translucent fill. That is what lets a card change its text colour on hover
 * and take the drawing with it, and it is why these survive a palette change
 * without being redrawn.
 *
 * Not `CoveIcon` scaled up. A 24px glyph enlarged to 160px is a hairline
 * outline in a lot of empty space, which reads as a rendering fault rather than
 * a drawing. These are compositions with a foreground shape, a supporting one
 * and a tinted wash, sized for the space they actually occupy.
 *
 * **The article scenes draw the subject, not a document.** A page about customs
 * duty illustrated with a generic sheet of paper is the same picture as a page
 * about reviews, which is no picture at all — the point of a mark on a card is
 * that a reader who has been here before recognises it before reading the
 * title. So the parcel is at a barrier, the stars are under a lens, and the
 * doorstep is empty.
 *
 * Decorative throughout: `aria-hidden`, because the Cove's title and its
 * sentence sit directly beside every one of these.
 */
const scenes: Record<SceneKey, ReactNode> = {
    /*
     * A cup, steam, and two beans.
     *
     * The first attempt drew a grinder — a tall rounded body with a hopper
     * inside it — and at 112px it read as a phone with a cup next to it. The
     * apparatus is what a coffee person cares about and not what makes the
     * drawing legible; a mug and beans are unmistakable at any size.
     */
    coffee: (
        <>
            {/* Steam, above the cup. */}
            <path d="M56 40c5-5 0-10 5-15M74 40c5-5 0-10 5-15" />
            {/* The cup. */}
            <path d="M40 52h48v20a24 24 0 0 1-24 24 24 24 0 0 1-24-24z" className="fill-accent/10" />
            <path d="M40 52h48v20a24 24 0 0 1-24 24 24 24 0 0 1-24-24z" />
            <path d="M88 60h8a12 12 0 0 1 0 24h-4" />
            <path d="M30 100h68" />
            {/* Two beans, with the seam that makes them beans. */}
            <ellipse cx="118" cy="74" rx="10" ry="7" className="fill-accent/10" />
            <ellipse cx="118" cy="74" rx="10" ry="7" />
            <path d="M118 67v14" />
            <ellipse cx="132" cy="90" rx="8" ry="6" />
            <path d="M132 84v12" />
        </>
    ),

    /*
     * A pan seen from the side, steam, and a spoon leaning out of it.
     *
     * Drawn from above first — an ellipse with a handle and some circles in it —
     * which read as an artist's palette, and the knife beside it as a flag. From
     * the side there is no ambiguity: a vessel on a surface with heat coming off
     * it is the whole idea.
     */
    cooking: (
        <>
            <path d="M54 44c5-6 0-11 5-17M74 44c5-6 0-11 5-17" />
            {/* The pan. */}
            <path d="M36 56h60v20a14 14 0 0 1-14 14H50a14 14 0 0 1-14-14z" className="fill-accent/10" />
            <path d="M36 56h60v20a14 14 0 0 1-14 14H50a14 14 0 0 1-14-14z" />
            <path d="M30 56h72" />
            <path d="M102 60h24" />
            {/* The spoon, leaning out of it. */}
            <path d="M72 56l26-26" />
            <ellipse cx="102" cy="25" rx="7" ry="5" className="fill-accent/10" />
            <ellipse cx="102" cy="25" rx="7" ry="5" />
            <path d="M26 100h84" />
        </>
    ),

    /*
     * A wheel and a chequered flag.
     *
     * The wheel was paired with a pedal set, which at this size was a small
     * detached parallelogram that read as a stray shape rather than as pedals —
     * and the wheel alone is a wheel, not a racing one. The flag says the sport
     * in one mark, which is what the second object is for.
     */
    racing: (
        <>
            <circle cx="56" cy="58" r="30" />
            <circle cx="56" cy="58" r="21" />
            <circle cx="56" cy="58" r="8" className="fill-accent/10" />
            <circle cx="56" cy="58" r="8" />
            {/* Three spokes: one up, two down. A wheel, not a ring. */}
            <path d="M56 50V37M50 63L38 75M62 63l12 12" />
            {/* The flag. */}
            <path d="M124 24v70" />
            <path d="M98 24h26v22H98z" />
            <path d="M98 46h26v22H98z" />
            <path d="M98 24h13v11H98zM111 35h13v11h-13z" className="fill-accent/20" />
            <path d="M98 46h13v11H98zM111 57h13v11h-13z" className="fill-accent/20" />
        </>
    ),

    /*
     * A parcel on a doorstep, already open.
     *
     * The person who has everything is defined by speed rather than by taste —
     * they own it before you thought of it — so this draws the delivery, not
     * the thing delivered.
     */
    has_everything: (
        <>
            <path d="M40 46h56v42H40z" className="fill-accent/10" />
            <path d="M40 46h56v42H40z" />
            {/* Flaps, open. */}
            <path d="M40 46l-14-12 20-4 12 16M96 46l14-12-20-4-12 16" />
            <path d="M58 46h20v12H58z" />
            {/* The doorstep it is standing on. */}
            <path d="M22 88h92" />
            <path d="M30 96h76" />
        </>
    ),

    /* A dog and its bowl. The gift is for the owner; the animal uses it. */
    dog: (
        <>
            {/* Body. */}
            <path d="M34 62c0-10 8-16 20-16h20c12 0 20 6 20 16v12H34z" className="fill-accent/10" />
            <path d="M34 62c0-10 8-16 20-16h20c12 0 20 6 20 16v12H34z" />
            <path d="M44 74v12M84 74v12" />
            {/* Head and one folded ear. */}
            <circle cx="98" cy="42" r="16" className="fill-accent/10" />
            <circle cx="98" cy="42" r="16" />
            <path d="M86 32c-6-2-10 2-9 8" />
            <circle cx="104" cy="40" r="2" />
            <path d="M110 50h6" />
            {/* Tail, and the bowl. */}
            <path d="M34 60c-8-2-10-8-6-14" />
            <path d="M20 82h26l-4 10H24z" className="fill-accent/10" />
            <path d="M20 82h26l-4 10H24z" />
        </>
    ),

    /* Body and a lens beside it — the accessory half is the gift half. */
    photography: (
        <>
            <path d="M28 44h58v42H28z" className="fill-accent/10" />
            <path d="M28 44h58v42H28z" />
            <path d="M44 44l6-8h16l6 8" />
            <circle cx="57" cy="65" r="14" />
            <circle cx="57" cy="65" r="6" />
            <circle cx="76" cy="52" r="2" />
            {/* A second lens, standing on its own. */}
            <rect x="98" y="54" width="26" height="32" rx="4" className="fill-accent/10" />
            <rect x="98" y="54" width="26" height="32" rx="4" />
            <path d="M98 62h26M98 78h26" />
        </>
    ),

    /* Drill and spirit level. Someone who does it themselves. */
    diy: (
        <>
            {/* The drill: body, grip, battery, bit. */}
            <path d="M30 38h44v24H30z" className="fill-accent/10" />
            <path d="M30 38h44v24H30z" />
            <path d="M46 62h18v22H46z" />
            <path d="M42 84h26" />
            <path d="M74 46h14v8H74z" />
            <path d="M88 50h16" />
            {/* The level, in front, with its bubble. */}
            <rect x="26" y="90" width="98" height="14" rx="4" className="fill-accent/10" />
            <rect x="26" y="90" width="98" height="14" rx="4" />
            <rect x="66" y="93" width="18" height="8" rx="4" />
        </>
    ),

    /* Boot, pack, and the reason for both. */
    outdoors: (
        <>
            {/* The peak, behind. */}
            <path d="M18 78l30-44 20 28 12-16 22 32z" className="fill-accent/10" />
            <path d="M18 78l30-44 20 28 12-16 22 32z" />
            {/* The pack. */}
            <rect x="30" y="60" width="30" height="34" rx="8" className="fill-accent/10" />
            <rect x="30" y="60" width="30" height="34" rx="8" />
            <path d="M38 60v-6a7 7 0 0 1 14 0v6" />
            <path d="M30 76h30" />
            {/* The boot. */}
            <path d="M78 70h14v14l18 6v8H78z" className="fill-accent/10" />
            <path d="M78 70h14v14l18 6v8H78z" />
            <path d="M78 92h32" />
        </>
    ),

    /*
     * A watering can, and a trowel standing in the ground beside it.
     *
     * The trowel is planted rather than lying flat: on its side it read as a
     * spoon next to a kettle, which is `cooking` with extra steps. Standing in
     * a line of soil there is a ground plane, and the two objects are outdoors.
     */
    gardening: (
        <>
            {/*
              The spout is a closed tapering shape with a flared rose on the
              end. Drawn as a single line it read as an arrow leaving a bucket,
              which made the whole scene a diagram of something being thrown
              away.
            */}
            <path d="M86 60l24-20 10 12-30 18z" className="fill-accent/15" />
            <path d="M86 60l24-20 10 12-30 18z" />
            <path d="M104 32l22 12-8 12z" className="fill-accent/20" />
            <path d="M104 32l22 12-8 12z" />
            {/* The can, and the handle that stops it being a bucket. */}
            <path d="M34 52h52v36a10 10 0 0 1-10 10H44a10 10 0 0 1-10-10z" className="fill-accent/10" />
            <path d="M34 52h52v36a10 10 0 0 1-10 10H44a10 10 0 0 1-10-10z" />
            <path d="M28 52h64" />
            <path d="M44 52c0-16 10-24 22-24" />
            {/*
              The trowel, planted in the ground beside it. A pointed blade,
              because a rounded scoop on a short stem read as a wine glass.
            */}
            <path d="M126 62V48" />
            <rect x="118" y="30" width="16" height="18" rx="7" className="fill-accent/20" />
            <rect x="118" y="30" width="16" height="18" rx="7" />
            <path d="M112 62h28l-14 34z" className="fill-accent/10" />
            <path d="M112 62h28l-14 34z" />
            {/* The ground. */}
            <path d="M16 98h128" />
        </>
    ),

    /*
     * One plant in one pot, on a sill.
     *
     * A row of three read as a garden centre — which is the shop, not the
     * person. One plant with a saucer under it and a window edge behind is
     * somebody's flat.
     */
    plants: (
        <>
            {/* The sill, behind. */}
            <path d="M24 96h112" />
            <path d="M110 20v58" />
            {/* Leaves, alternating off one stem. */}
            <path d="M80 62V22" />
            <path d="M80 34c-14 0-22-6-24-16 14-2 22 4 24 16z" className="fill-accent/15" />
            <path d="M80 34c-14 0-22-6-24-16 14-2 22 4 24 16z" />
            <path d="M80 48c14 0 22-6 24-16-14-2-22 4-24 16z" className="fill-accent/15" />
            <path d="M80 48c14 0 22-6 24-16-14-2-22 4-24 16z" />
            <path d="M80 60c-12 0-19-5-21-14 12-2 19 4 21 14z" className="fill-accent/15" />
            <path d="M80 60c-12 0-19-5-21-14 12-2 19 4 21 14z" />
            {/* The pot, and the saucer under it. */}
            <path d="M56 62h48l-6 26H62z" className="fill-accent/10" />
            <path d="M56 62h48l-6 26H62z" />
            <path d="M52 88h56" />
        </>
    ),

    /*
     * Headphones over a record.
     *
     * Not a note or a treble clef: those mean "music" as a category and this
     * has to mean somebody who *listens*, which is a person with equipment. The
     * record behind supplies the second object without adding a second idea.
     */
    music: (
        <>
            {/* The record, behind. */}
            <circle cx="104" cy="62" r="30" className="fill-accent/10" />
            <circle cx="104" cy="62" r="30" />
            <circle cx="104" cy="62" r="20" />
            <circle cx="104" cy="62" r="6" />
            {/* The headphones, in front. */}
            <path d="M26 66V54a26 26 0 0 1 52 0v12" />
            <path d="M20 66h16v26H20a4 4 0 0 1-4-4V70a4 4 0 0 1 4-4z" className="fill-accent/20" />
            <path d="M20 66h16v26H20a4 4 0 0 1-4-4V70a4 4 0 0 1 4-4z" />
            <path d="M68 66h16a4 4 0 0 1 4 4v18a4 4 0 0 1-4 4H68z" className="fill-accent/20" />
            <path d="M68 66h16a4 4 0 0 1 4 4v18a4 4 0 0 1-4 4H68z" />
        </>
    ),

    /*
     * A stack of books with a pair of reading glasses on top.
     *
     * Deliberately not an open book against a shelf: `CoveIllustration`'s
     * `idea` scene is exactly that and means "the archive of writing" on the
     * homepage. Two drawings that mean different things must not be the same
     * drawing.
     *
     * The glasses were the fix. Three stacked rectangles and a lamp read as a
     * stack of boxes under a light - the books were only books because the
     * caption said so. Glasses are unmistakable at any size and they are the
     * one object that means the *activity* rather than the objects.
     */
    reading: (
        <>
            {/* The glasses, on top. */}
            <circle cx="54" cy="38" r="14" className="fill-accent/10" />
            <circle cx="54" cy="38" r="14" />
            <circle cx="98" cy="38" r="14" className="fill-accent/10" />
            <circle cx="98" cy="38" r="14" />
            <path d="M68 36h16" />
            <path d="M40 32l-14-8M112 32l14-8" />
            {/* The stack, each with its page block showing. */}
            <path d="M28 86h84v14H28z" className="fill-accent/10" />
            <path d="M28 86h84v14H28z" />
            <path d="M38 86v14" />
            <path d="M34 72h84v14H34z" className="fill-accent/15" />
            <path d="M34 72h84v14H34z" />
            <path d="M44 72v14" />
            <path d="M24 58h84v14H24z" className="fill-accent/10" />
            <path d="M24 58h84v14H24z" />
            <path d="M34 58v14" />
        </>
    ),

    /*
     * A controller, front on.
     *
     * Separate from `racing` — a wheel and a flag — because a sim rig and a
     * console are different shelves and different money, and the mark is how a
     * reader tells which shelf is theirs before reading the title.
     */
    gaming: (
        <>
            <path d="M46 40h68a30 30 0 0 1 28 40l-6 16a14 14 0 0 1-25 3l-8-13H57l-8 13a14 14 0 0 1-25-3l-6-16a30 30 0 0 1 28-40z" className="fill-accent/10" />
            <path d="M46 40h68a30 30 0 0 1 28 40l-6 16a14 14 0 0 1-25 3l-8-13H57l-8 13a14 14 0 0 1-25-3l-6-16a30 30 0 0 1 28-40z" />
            {/* D-pad left, two buttons right. */}
            <path d="M44 62v18M35 71h18" />
            <circle cx="112" cy="62" r="5" className="fill-accent/25" />
            <circle cx="112" cy="62" r="5" />
            <circle cx="126" cy="76" r="5" className="fill-accent/25" />
            <circle cx="126" cy="76" r="5" />
        </>
    ),

    /*
     * A kettlebell, with a dumbbell behind it.
     *
     * The first attempt paired the dumbbell with a rolled mat, which at this
     * size is a long pill with a circle in it - a second dumbbell, or a rolling
     * pin, and either way not a mat. A kettlebell has a silhouette nothing else
     * has, so it carries the whole idea on its own and the dumbbell is only
     * there to say the scene is about weight rather than about one object.
     */
    fitness: (
        <>
            {/* The dumbbell, behind. */}
            <path d="M104 34h34" />
            <rect x="98" y="24" width="10" height="20" rx="3" className="fill-accent/15" />
            <rect x="98" y="24" width="10" height="20" rx="3" />
            <rect x="134" y="24" width="10" height="20" rx="3" className="fill-accent/15" />
            <rect x="134" y="24" width="10" height="20" rx="3" />
            {/* The kettlebell: handle, neck, bell. */}
            <path d="M44 52a22 20 0 0 1 44 0" />
            <path d="M54 52a12 11 0 0 1 24 0" />
            <path d="M50 52h32" />
            <circle cx="66" cy="76" r="26" className="fill-accent/15" />
            <circle cx="66" cy="76" r="26" />
        </>
    ),

    /*
     * A hard case, standing, with a tag hanging off the handle.
     *
     * The tag is the whole reason this is not a box: a rectangle with a handle
     * is a briefcase, and a rectangle with a handle and a label on a string is
     * luggage.
     */
    travel: (
        <>
            {/* Handle. */}
            <path d="M62 30h32v14H62z" />
            {/* Body. */}
            <rect x="42" y="44" width="72" height="52" rx="8" className="fill-accent/10" />
            <rect x="42" y="44" width="72" height="52" rx="8" />
            <path d="M60 44v52M96 44v52" />
            {/* Wheels. */}
            <circle cx="56" cy="102" r="5" />
            <circle cx="100" cy="102" r="5" />
            {/* The tag, on its string. */}
            <path d="M94 37c14 0 20 6 20 14" />
            <path d="M114 51h20v18h-20z" className="fill-accent/20" />
            <path d="M114 51h20v18h-20z" />
            <path d="M120 57h8" />
        </>
    ),

    /*
     * A bowl with a whisk in it, and a rolling pin lying in front.
     *
     * The split from `cooking` is measuring against improvising: a pan on heat
     * is dinner, a bowl and a pin is a recipe somebody is following exactly.
     */
    baking: (
        <>
            {/*
              The whisk has to be three wires meeting at a point. Drawn as one
              closed teardrop it read as a leaf, which put a plant in the bowl
              and made this `plants` with a different caption.
            */}
            <path d="M128 14l-8 26" />
            <path d="M120 40c-10 6-12 18-6 26" />
            <path d="M120 40c10 6 12 18 6 26" />
            <path d="M120 40v26" />
            <path d="M112 66h16" />
            {/* The bowl. */}
            <path d="M22 48h72a36 32 0 0 1-36 30 36 32 0 0 1-36-30z" className="fill-accent/10" />
            <path d="M22 48h72a36 32 0 0 1-36 30 36 32 0 0 1-36-30z" />
            <path d="M16 48h84" />
            {/* The pin, in front. */}
            <rect x="30" y="88" width="82" height="12" rx="6" className="fill-accent/10" />
            <rect x="30" y="88" width="82" height="12" rx="6" />
            <path d="M18 94h12M112 94h12" />
        </>
    ),

    /*
     * A figure, and nothing saying what they are into.
     *
     * No features. This is the default for a persona whose scene has not been
     * chosen, so it has to read as "a person" rather than as a particular one —
     * and a blank card on a shelf of drawings reads as a page that failed.
     */
    someone: (
        <>
            <circle cx="80" cy="40" r="18" className="fill-accent/10" />
            <circle cx="80" cy="40" r="18" />
            <path d="M44 96c0-20 16-32 36-32s36 12 36 32" className="fill-accent/10" />
            <path d="M44 96c0-20 16-32 36-32s36 12 36 32" />
        </>
    ),

    /*
     * A receipt, and a pair of scales standing on it.
     *
     * The scales alone are law in the abstract — a courtroom. Standing on a
     * till receipt they are law about *this purchase*, which is what the
     * article is: fourteen days, two years, and who has to prove what.
     */
    rights: (
        <>
            {/* The receipt, with its torn foot. */}
            <path d="M18 22h54v74l-6-6-7 6-7-6-7 6-7-6-7 6-6-6z" className="fill-accent/10" />
            <path d="M18 22h54v74l-6-6-7 6-7-6-7 6-7-6-7 6-6-6z" />
            <path d="M28 38h34M28 52h34M28 66h20" />
            {/*
              The pans are shallow bowls hanging from the beam, not triangles.
              Drawn as a V they read as two tents, or as mountains, which put
              `outdoors` in the middle of a page about consumer law.
            */}
            <path d="M118 32v56" />
            <path d="M106 90h24" />
            <path d="M92 44h52" />
            <circle cx="118" cy="36" r="4" className="fill-accent/25" />
            <path d="M94 44v10" />
            <path d="M82 54h24a12 12 0 0 1-24 0z" className="fill-accent/15" />
            <path d="M82 54h24a12 12 0 0 1-24 0z" />
            <path d="M142 44v10" />
            <path d="M130 54h24a12 12 0 0 1-24 0z" className="fill-accent/15" />
            <path d="M130 54h24a12 12 0 0 1-24 0z" />
        </>
    ),

    /*
     * A price tag, and behind it the line of what the thing has actually cost.
     *
     * The subject is not "a discount" — it is the thirty days before it. A tag
     * on its own says sale; a tag in front of a flat line that dips only at the
     * end says what the article says, which is that the crossed-out number is a
     * claim about history and history is checkable.
     */
    price_history: (
        <>
            {/*
              The history sits above the tag rather than across it. Drawn behind
              it, the dashed line crossed the tag's own strike-through and the
              two became one piece of scribble.
            */}
            <path d="M18 38h30V24h28v8h30v14h30" className="stroke-accent" strokeDasharray="5 5" />
            {/* The tag, pointed end to the left. */}
            <path d="M46 58h68a10 10 0 0 1 10 10v26a10 10 0 0 1-10 10H46L22 81z" className="fill-accent/10" />
            <path d="M46 58h68a10 10 0 0 1 10 10v26a10 10 0 0 1-10 10H46L22 81z" />
            <circle cx="46" cy="81" r="6" />
            {/* The old number on it, and the line through it. */}
            <path d="M64 72h44M64 92h28" />
            <path d="M58 98l58-34" />
        </>
    ),

    /*
     * One box wearing two labels.
     *
     * The article's whole point is that the shop you clicked and the party you
     * are buying from are often not the same, and that only one of them owes
     * you anything. Two shopfronts side by side would say "choose a shop";
     * two labels on one parcel says "these are the same parcel".
     */
    seller: (
        <>
            <path d="M34 44h92v52H34z" className="fill-accent/10" />
            <path d="M34 44h92v52H34z" />
            <path d="M34 44l14-18h64l14 18" />
            <path d="M80 26v70" />
            {/* The label you saw. */}
            <path d="M42 54h30v16H42z" className="fill-accent/25" />
            <path d="M42 54h30v16H42z" />
            <path d="M48 62h18" />
            {/* The one on the underside. */}
            <path d="M88 74h30v16H88z" />
            <path d="M94 82h18" />
        </>
    ),

    /*
     * Five stars, and a lens over the last of them.
     *
     * The lens is on one star rather than over all five on purpose: the article
     * is not "reviews are fake", it is that a rating is an average and the
     * useful information is in the individual review nobody reads.
     */
    reviews: (
        <>
            <path d="M28 34l6 13 14 2-10 10 2 14-12-7-12 7 2-14-10-10 14-2z" className="fill-accent/15" />
            <path d="M28 34l6 13 14 2-10 10 2 14-12-7-12 7 2-14-10-10 14-2z" />
            <path d="M66 34l6 13 14 2-10 10 2 14-12-7-12 7 2-14-10-10 14-2z" className="fill-accent/15" />
            <path d="M66 34l6 13 14 2-10 10 2 14-12-7-12 7 2-14-10-10 14-2z" />
            <path d="M104 34l6 13 14 2-10 10 2 14-12-7-12 7 2-14-10-10 14-2z" />
            {/* The lens, over the last one. */}
            <circle cx="112" cy="76" r="24" className="fill-accent/10" />
            <circle cx="112" cy="76" r="24" />
            <path d="M130 94l16 16" />
        </>
    ),

    /*
     * A box that has been opened before, with the device still in it.
     *
     * The flaps are cut rather than sealed and the tape line is broken — that
     * is the entire distinction the article draws between open box, second-hand
     * and refurbished, and it is a distinction about the packaging as much as
     * about the thing inside.
     */
    refurbished: (
        <>
            {/* The box, flaps folded outward. */}
            <path d="M36 52h88v46H36z" className="fill-accent/10" />
            <path d="M36 52h88v46H36z" />
            <path d="M36 52L20 40l18-6 14 18M124 52l16-12-18-6-14 18" />
            {/* The device standing in it. */}
            <rect x="60" y="26" width="40" height="30" rx="4" className="fill-accent/20" />
            <rect x="60" y="26" width="40" height="30" rx="4" />
            {/* The turn-around arrow across the front. */}
            <path d="M56 78a24 24 0 0 1 48 0" />
            <path d="M96 70l8 8-8 8" />
        </>
    ),

    /*
     * A shopfront under a magnifier.
     *
     * An awning, a door and a window is unambiguously a business at this size,
     * which is what the reader is trying to decide they are looking at. The
     * lens is the checking; the article is a list of things to check.
     */
    shop_check: (
        <>
            {/* The building. */}
            <path d="M26 46h84v52H26z" className="fill-accent/10" />
            <path d="M26 46h84v52H26z" />
            <path d="M20 46l10-20h76l10 20z" />
            <path d="M38 46v10M56 46v10M74 46v10M92 46v10" className="stroke-accent" />
            <path d="M36 66h26v32H36z" />
            <path d="M74 66h24v18H74z" />
            {/* The lens, over the door. */}
            <circle cx="106" cy="74" r="22" />
            <path d="M122 90l16 16" />
        </>
    ),

    /*
     * A phone showing a short message, with a fish hook coming out of it.
     *
     * The hook is doing the work. A phone with a message is a notification; a
     * phone with a hook in it is the article's actual subject, and it needs no
     * language to read — which matters on a mark that ships in four of them.
     */
    phishing: (
        <>
            <rect x="30" y="18" width="62" height="88" rx="8" className="fill-accent/10" />
            <rect x="30" y="18" width="62" height="88" rx="8" />
            <path d="M52 26h18" />
            {/* The message. */}
            <rect x="40" y="44" width="42" height="30" rx="6" className="fill-accent/20" />
            <rect x="40" y="44" width="42" height="30" rx="6" />
            <path d="M48 54h26M48 64h16" />
            {/* The hook, out of the screen. */}
            <path d="M126 20v40a18 18 0 0 1-36 0" />
            <path d="M90 60l-8 8 8 8" />
            <path d="M118 20h16" />
        </>
    ),

    /*
     * A parcel with a declaration taped to it, and a stamp across the corner.
     *
     * It was a parcel held at a striped barrier, and the barrier - a banded bar
     * spanning the full width at a constant height - read as a shelf, so the
     * parcel and the form beside it read as goods on it. The subject is not the
     * border anyway: it is that the parcel is stopped *because of what is
     * written on it*, which is the paperwork and the stamp.
     */
    customs: (
        <>
            {/* The parcel. */}
            <path d="M26 42h70v58H26z" className="fill-accent/10" />
            <path d="M26 42h70v58H26z" />
            <path d="M26 42l12-18h46l12 18" />
            <path d="M61 24v76" />
            {/* The declaration, taped to the front. */}
            <path d="M38 54h46v34H38z" className="fill-accent/25" />
            <path d="M38 54h46v34H38z" />
            <path d="M46 64h30M46 74h20" />
            {/* The stamp, over the corner. */}
            <circle cx="118" cy="72" r="22" className="stroke-accent" />
            <path d="M104 64h28M104 80h28" className="stroke-accent" />
        </>
    ),

    /*
     * A wrapped parcel, and the receipt lying beside it face down.
     *
     * The receipt is next to the gift rather than in it, which is the article:
     * the right to return belongs to whoever paid, the recipient is holding the
     * object and not the contract, and the gap between those two is the whole
     * piece.
     */
    gift_return: (
        <>
            {/* The gift. */}
            <path d="M28 52h64v46H28z" className="fill-accent/10" />
            <path d="M28 52h64v46H28z" />
            <path d="M24 40h72v12H24z" className="fill-accent/20" />
            <path d="M24 40h72v12H24z" />
            <path d="M60 40v58" />
            <path d="M60 40c-8-14-24-12-22-2 1 6 12 4 22 2zM60 40c8-14 24-12 22-2-1 6-12 4-22 2z" />
            {/* The receipt, beside it, with the return arrow on it. */}
            <path d="M106 44h36v54l-6-5-6 5-6-5-6 5-6-5-6 5z" className="fill-accent/10" />
            <path d="M106 44h36v54l-6-5-6 5-6-5-6 5-6-5-6 5z" />
            <path d="M114 60h20M114 72h12" />
            <path d="M136 84a10 10 0 0 0-20 0" />
            <path d="M110 78l6 6 6-6" />
        </>
    ),

    /*
     * A doorstep with nothing on it.
     *
     * The absence is the drawing. Every other scene here puts an object in the
     * middle; this one puts the step, the door and the empty space where the
     * parcel was said to be, because "delivered" and "delivered to you" are the
     * two things the article separates.
     */
    missing_parcel: (
        <>
            {/* The door, and the mat in front of it. */}
            <path d="M34 12h58v78H34z" className="fill-accent/10" />
            <path d="M34 12h58v78H34z" />
            <path d="M44 22h38v34H44z" />
            <circle cx="84" cy="72" r="3" />
            <path d="M20 90h124" />
            <path d="M10 102h140" />
            <path d="M40 92h48v8H40z" />
            {/*
              The parcel that is not there, dashed, standing on the step.

              This was three short accent strokes to the side of the door,
              meant as an absence and reading as motion lines. An outline in the
              shape of the missing thing is the only version of "nothing here"
              that says what is missing.
            */}
            <path d="M102 56h36v34h-36z" className="stroke-accent" strokeDasharray="6 5" />
            <path d="M120 56v34" className="stroke-accent" strokeDasharray="6 5" />
        </>
    ),

    /*
     * Pages of an article, and nothing saying which one.
     *
     * The default for an article kind, and the counterpart to `someone`: a
     * guide that has named no subject is still a piece of writing, and a blank
     * card in a grid of drawn ones reads as a page that failed to load.
     */
    article: (
        <>
            <path d="M38 20h60l24 24v72H38z" className="fill-accent/10" />
            <path d="M38 20h60l24 24v72H38z" />
            <path d="M98 20v24h24" />
            <path d="M52 60h54M52 74h54M52 88h34" />
        </>
    ),
}

/**
 * `scene` is nullable on the model. The server sends the kind's default rather
 * than null — `someone` for a persona, `article` for a guide or an advice piece
 * — so this takes a bare null only as a last resort, and answers it with the
 * one that is safe on either shelf.
 */
export default function SceneIllustration({
    name,
    className,
}: {
    name: SceneKey | null
    className?: string
}) {
    return (
        <svg
            viewBox="0 0 160 116"
            className={className}
            fill="none"
            stroke="currentColor"
            strokeWidth={2}
            strokeLinecap="round"
            strokeLinejoin="round"
            aria-hidden="true"
            focusable="false"
        >
            {scenes[name ?? 'someone']}
        </svg>
    )
}
