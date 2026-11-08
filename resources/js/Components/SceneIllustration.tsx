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
    | 'money_gift'
    | 'handmade'
    | 'money_tree'
    | 'folded_note'
    | 'coin_jar'
    | 'treasure_map'
    | 'coupon_book'
    | 'letter'
    | 'plant_cutting'
    | 'calendar_day'
    // Added 2026-11-08: people and occasions.
    | 'cat'
    | 'partner'
    | 'mum'
    | 'dad'
    | 'child'
    | 'baby'
    | 'grandparents'
    | 'siblings'
    | 'friend'
    | 'colleague'
    | 'host'
    | 'teacher'
    | 'home_office'
    | 'building_blocks'
    | 'crafts'
    | 'student'
    | 'graduation'
    | 'smart_home'
    // Added 2026-11-08: subjects.
    | 'sinterklaas'
    | 'gift_card'
    | 'halloween'
    | 'costume'
    | 'christmas_market'
    | 'wish_list'
    | 'repair'
    | 'private_sale'
    | 'subscription'
    | 'late_delivery'
    | 'payment'
    | 'trust_mark'
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
            <path d="M40 52h48v20a24 24 0 0 1-24 24 24 24 0 0 1-24-24z" className="fill-ill-red" />
            <path d="M88 60h8a12 12 0 0 1 0 24h-4" />
            <path d="M30 100h68" />
            {/* Two beans, with the seam that makes them beans. */}
            <ellipse cx="118" cy="74" rx="10" ry="7" className="fill-ill-cocoa" />
            <path d="M118 67v14" />
            <ellipse cx="132" cy="90" rx="8" ry="6" className="fill-ill-cocoa" />
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
            <path d="M36 56h60v20a14 14 0 0 1-14 14H50a14 14 0 0 1-14-14z" className="fill-ill-red" />
            <path d="M30 56h72" />
            <path d="M102 60h24" />
            {/* The spoon, leaning out of it. */}
            <path d="M72 56l26-26" />
            <ellipse cx="102" cy="25" rx="7" ry="5" className="fill-ill-kraft" />
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
            <circle cx="56" cy="58" r="8" className="fill-ill-red" />
            {/* Three spokes: one up, two down. A wheel, not a ring. */}
            <path d="M56 50V37M50 63L38 75M62 63l12 12" />
            {/* The flag. */}
            <path d="M124 24v70" />
            <path d="M98 24h26v22H98z" />
            <path d="M98 46h26v22H98z" />
            <path d="M98 24h13v11H98zM111 35h13v11h-13z" className="fill-ill-ink stroke-none" />
            <path d="M98 46h13v11H98zM111 57h13v11h-13z" className="fill-ill-ink stroke-none" />
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
            <path d="M40 46h56v42H40z" className="fill-ill-kraft" />
            {/* Flaps, open. */}
            <path d="M40 46l-14-12 20-4 12 16M96 46l14-12-20-4-12 16" />
            <path d="M58 46h20v12H58z" className="fill-ill-gold" />
            {/* The doorstep it is standing on. */}
            <path d="M22 88h92" />
            <path d="M30 96h76" />
        </>
    ),

    /* A dog and its bowl. The gift is for the owner; the animal uses it. */
    dog: (
        <>
            {/* Body. */}
            <path d="M34 62c0-10 8-16 20-16h20c12 0 20 6 20 16v12H34z" className="fill-ill-gold" />
            <path d="M44 74v12M84 74v12" />
            {/* Head and one folded ear. */}
            <circle cx="98" cy="42" r="16" className="fill-ill-gold" />
            <path d="M86 32c-6-2-10 2-9 8" />
            <circle cx="104" cy="40" r="2" className="fill-ill-ink" />
            <path d="M110 50h6" />
            {/* Tail, and the bowl. */}
            <path d="M34 60c-8-2-10-8-6-14" />
            <path d="M20 82h26l-4 10H24z" className="fill-ill-sky" />
        </>
    ),

    /* Body and a lens beside it — the accessory half is the gift half. */
    photography: (
        <>
            <path d="M28 44h58v42H28z" className="fill-ill-sky" />
            <path d="M44 44l6-8h16l6 8" />
            <circle cx="57" cy="65" r="14" className="fill-ill-cream" />
            <circle cx="57" cy="65" r="6" className="fill-ill-ink" />
            <circle cx="76" cy="52" r="2" />
            {/* A second lens, standing on its own. */}
            <rect x="98" y="54" width="26" height="32" rx="4" className="fill-ill-apricot" />
            <path d="M98 62h26M98 78h26" />
        </>
    ),

    /* Drill and spirit level. Someone who does it themselves. */
    diy: (
        <>
            {/* The drill: body, grip, battery, bit. */}
            <path d="M30 38h44v24H30z" className="fill-ill-red" />
            <path d="M46 62h18v22H46z" className="fill-ill-cocoa" />
            <path d="M42 84h26" />
            <path d="M74 46h14v8H74z" className="fill-ill-apricot" />
            <path d="M88 50h16" />
            {/* The level, in front, with its bubble. */}
            <rect x="26" y="90" width="98" height="14" rx="4" className="fill-ill-gold" />
            <rect x="66" y="93" width="18" height="8" rx="4" className="fill-ill-mint" />
        </>
    ),

    /* Boot, pack, and the reason for both. */
    outdoors: (
        <>
            {/* The peak, behind. */}
            <path d="M18 78l30-44 20 28 12-16 22 32z" className="fill-ill-sky" />
            {/* The pack. */}
            <rect x="30" y="60" width="30" height="34" rx="8" className="fill-ill-red" />
            <path d="M38 60v-6a7 7 0 0 1 14 0v6" />
            <path d="M30 76h30" />
            {/* The boot. */}
            <path d="M78 70h14v14l18 6v8H78z" className="fill-ill-cocoa" />
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
            <path d="M86 60l24-20 10 12-30 18z" className="fill-ill-mint" />
            <path d="M104 32l22 12-8 12z" className="fill-ill-mint" />
            {/* The can, and the handle that stops it being a bucket. */}
            <path d="M34 52h52v36a10 10 0 0 1-10 10H44a10 10 0 0 1-10-10z" className="fill-ill-mint" />
            <path d="M28 52h64" />
            <path d="M44 52c0-16 10-24 22-24" />
            {/*
              The trowel, planted in the ground beside it. A pointed blade,
              because a rounded scoop on a short stem read as a wine glass.
            */}
            <path d="M126 62V48" />
            <rect x="118" y="30" width="16" height="18" rx="7" className="fill-ill-kraft" />
            <path d="M112 62h28l-14 34z" className="fill-ill-cream" />
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
            <path d="M80 34c-14 0-22-6-24-16 14-2 22 4 24 16z" className="fill-ill-mint" />
            <path d="M80 48c14 0 22-6 24-16-14-2-22 4-24 16z" className="fill-ill-mint" />
            <path d="M80 60c-12 0-19-5-21-14 12-2 19 4 21 14z" className="fill-ill-mint" />
            {/* The pot, and the saucer under it. */}
            <path d="M56 62h48l-6 26H62z" className="fill-ill-orange" />
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
            <circle cx="104" cy="62" r="30" className="fill-ill-sky" />
            <circle cx="104" cy="62" r="20" />
            <circle cx="104" cy="62" r="6" className="fill-ill-red" />
            {/* The headphones, in front. */}
            <path d="M26 66V54a26 26 0 0 1 52 0v12" />
            <path d="M20 66h16v26H20a4 4 0 0 1-4-4V70a4 4 0 0 1 4-4z" className="fill-ill-gold" />
            <path d="M68 66h16a4 4 0 0 1 4 4v18a4 4 0 0 1-4 4H68z" className="fill-ill-gold" />
        </>
    ),

    /*
     * A stack of books with a pair of reading glasses on top.
     *
     * Deliberately not an open book against a shelf: `CoveIllustration`'s
     * `idea` scene was exactly that and meant "the archive of writing" on the
     * homepage (that component was removed 2026-09-26, unused). Two drawings
     * that mean different things must not be the same drawing.
     *
     * The glasses were the fix. Three stacked rectangles and a lamp read as a
     * stack of boxes under a light - the books were only books because the
     * caption said so. Glasses are unmistakable at any size and they are the
     * one object that means the *activity* rather than the objects.
     */
    reading: (
        <>
            {/* The glasses, on top. */}
            <circle cx="54" cy="38" r="14" className="fill-ill-sky/50" />
            <circle cx="98" cy="38" r="14" className="fill-ill-sky/50" />
            <path d="M68 36h16" />
            <path d="M40 32l-14-8M112 32l14-8" />
            {/* The stack, each with its page block showing. */}
            <path d="M28 86h84v14H28z" className="fill-ill-red" />
            <path d="M38 86v14" />
            <path d="M34 72h84v14H34z" className="fill-ill-gold" />
            <path d="M44 72v14" />
            <path d="M24 58h84v14H24z" className="fill-ill-mint" />
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
            <path d="M46 40h68a30 30 0 0 1 28 40l-6 16a14 14 0 0 1-25 3l-8-13H57l-8 13a14 14 0 0 1-25-3l-6-16a30 30 0 0 1 28-40z" className="fill-ill-sky" />
            {/* D-pad left, two buttons right. */}
            <path d="M44 62v18M35 71h18" />
            <circle cx="112" cy="62" r="5" className="fill-ill-red" />
            <circle cx="126" cy="76" r="5" className="fill-ill-gold" />
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
            <rect x="98" y="24" width="10" height="20" rx="3" className="fill-ill-red" />
            <rect x="134" y="24" width="10" height="20" rx="3" className="fill-ill-red" />
            {/* The kettlebell: handle, neck, bell. */}
            <path d="M44 52a22 20 0 0 1 44 0" />
            <path d="M54 52a12 11 0 0 1 24 0" />
            <path d="M50 52h32" />
            <circle cx="66" cy="76" r="26" className="fill-ill-orange" />
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
            <path d="M62 30h32v14H62z" className="fill-ill-cocoa" />
            {/* Body. */}
            <rect x="42" y="44" width="72" height="52" rx="8" className="fill-ill-red" />
            <path d="M60 44v52M96 44v52" />
            {/* Wheels. */}
            <circle cx="56" cy="102" r="5" className="fill-ill-ink" />
            <circle cx="100" cy="102" r="5" className="fill-ill-ink" />
            {/* The tag, on its string. */}
            <path d="M94 37c14 0 20 6 20 14" />
            <path d="M114 51h20v18h-20z" className="fill-ill-gold" />
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
            <path d="M22 48h72a36 32 0 0 1-36 30 36 32 0 0 1-36-30z" className="fill-ill-sky" />
            <path d="M16 48h84" />
            {/* The pin, in front. */}
            <rect x="30" y="88" width="82" height="12" rx="6" className="fill-ill-kraft" />
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
            <circle cx="80" cy="40" r="18" className="fill-ill-apricot" />
            <path d="M44 96c0-20 16-32 36-32s36 12 36 32" className="fill-ill-orange" />
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
            <path d="M18 22h54v74l-6-6-7 6-7-6-7 6-7-6-7 6-6-6z" className="fill-ill-cream" />
            <path d="M28 38h34M28 52h34M28 66h20" />
            {/*
              The pans are shallow bowls hanging from the beam, not triangles.
              Drawn as a V they read as two tents, or as mountains, which put
              `outdoors` in the middle of a page about consumer law.
            */}
            <path d="M118 32v56" />
            <path d="M106 90h24" />
            <path d="M92 44h52" />
            <circle cx="118" cy="36" r="4" className="fill-ill-gold stroke-none" />
            <path d="M94 44v10" />
            <path d="M82 54h24a12 12 0 0 1-24 0z" className="fill-ill-gold" />
            <path d="M142 44v10" />
            <path d="M130 54h24a12 12 0 0 1-24 0z" className="fill-ill-gold" />
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
            <path d="M46 58h68a10 10 0 0 1 10 10v26a10 10 0 0 1-10 10H46L22 81z" className="fill-ill-gold" />
            <circle cx="46" cy="81" r="6" className="fill-ill-cream" />
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
            <path d="M34 44h92v52H34z" className="fill-ill-kraft" />
            <path d="M34 44l14-18h64l14 18" />
            <path d="M80 26v70" />
            {/* The label you saw. */}
            <path d="M42 54h30v16H42z" className="fill-ill-cream" />
            <path d="M48 62h18" />
            {/* The one on the underside. */}
            <path d="M88 74h30v16H88z" className="fill-ill-sky" />
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
            <path d="M28 34l6 13 14 2-10 10 2 14-12-7-12 7 2-14-10-10 14-2z" className="fill-ill-gold" />
            <path d="M66 34l6 13 14 2-10 10 2 14-12-7-12 7 2-14-10-10 14-2z" className="fill-ill-gold" />
            <path d="M104 34l6 13 14 2-10 10 2 14-12-7-12 7 2-14-10-10 14-2z" className="fill-ill-gold" />
            {/* The lens, over the last one. */}
            <circle cx="112" cy="76" r="24" className="fill-ill-sky/40" />
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
            <path d="M36 52h88v46H36z" className="fill-ill-kraft" />
            <path d="M36 52L20 40l18-6 14 18M124 52l16-12-18-6-14 18" />
            {/* The device standing in it. */}
            <rect x="60" y="26" width="40" height="30" rx="4" className="fill-ill-sky" />
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
            <path d="M26 46h84v52H26z" className="fill-ill-apricot" />
            <path d="M20 46l10-20h76l10 20z" className="fill-ill-red" />
            <path d="M38 46v10M56 46v10M74 46v10M92 46v10" className="stroke-accent" />
            <path d="M36 66h26v32H36z" className="fill-ill-cocoa" />
            <path d="M74 66h24v18H74z" className="fill-ill-sky" />
            {/* The lens, over the door. */}
            <circle cx="106" cy="74" r="22" className="fill-ill-sky/40" />
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
            <rect x="30" y="18" width="62" height="88" rx="8" className="fill-ill-sky" />
            <path d="M52 26h18" />
            {/* The message. */}
            <rect x="40" y="44" width="42" height="30" rx="6" className="fill-ill-cream" />
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
            <path d="M26 42h70v58H26z" className="fill-ill-kraft" />
            <path d="M26 42l12-18h46l12 18" />
            <path d="M61 24v76" />
            {/* The declaration, taped to the front. */}
            <path d="M38 54h46v34H38z" className="fill-ill-cream" />
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
            <path d="M28 52h64v46H28z" className="fill-ill-red" />
            <path d="M24 40h72v12H24z" className="fill-ill-orange" />
            <path d="M60 40v58" />
            <path d="M60 40c-8-14-24-12-22-2 1 6 12 4 22 2zM60 40c8-14 24-12 22-2-1 6-12 4-22 2z" className="fill-ill-gold" />
            {/* The receipt, beside it, with the return arrow on it. */}
            <path d="M106 44h36v54l-6-5-6 5-6-5-6 5-6-5-6 5z" className="fill-ill-cream" />
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
            <path d="M34 12h58v78H34z" className="fill-ill-sky" />
            <path d="M44 22h38v34H44z" className="fill-ill-cream" />
            <circle cx="84" cy="72" r="3" />
            <path d="M20 90h124" />
            <path d="M10 102h140" />
            <path d="M40 92h48v8H40z" className="fill-ill-kraft" />
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
     * An open envelope with a banknote standing in it, and a bow.
     *
     * The cover of the article about giving money. The note is the subject
     * and stays plain; the bow is the one accent, because the article is about
     * the wrapping and not the amount.
     */
    money_gift: (
        <>
            {/* The envelope, and the note standing in it. */}
            <path d="M30 56h100v48H30z" className="fill-ill-apricot" />
            <path d="M30 56l50 30 50-30" />
            <path d="M48 32h64v24H48z" className="fill-ill-mint" />
            <circle cx="80" cy="44" r="7" />
            <path d="M56 38v12M104 38v12" />
            {/* The bow, on the corner. */}
            <ellipse cx="122" cy="20" rx="7" ry="4" className="stroke-accent" transform="rotate(-25 122 20)" />
            <ellipse cx="136" cy="20" rx="7" ry="4" className="stroke-accent" transform="rotate(25 136 20)" />
            <path d="M129 22l-4 12M129 22l4 12" className="stroke-accent" />
        </>
    ),

    /*
     * A jar with a ribbon round its neck and a handwritten label.
     *
     * The cover of the article about presents that are made rather than
     * bought. A jar is what most of them arrive in, and the label with two
     * lines of writing is what says somebody's hand was involved.
     */
    handmade: (
        <>
            <path d="M58 26h44v8H58z" className="fill-ill-red" />
            <path d="M54 34h52v60a8 8 0 0 1-8 8H62a8 8 0 0 1-8-8z" className="fill-ill-sky" />
            {/* The label, and the handwriting on it. */}
            <path d="M64 56h32v22H64z" className="fill-ill-cream" />
            <path d="M70 64h20M70 71h14" />
            {/* The ribbon, tied at the side. */}
            <path d="M54 44h52" className="stroke-accent" />
            <ellipse cx="114" cy="38" rx="6" ry="4" className="stroke-accent" transform="rotate(-35 114 38)" />
            <ellipse cx="114" cy="50" rx="6" ry="4" className="stroke-accent" transform="rotate(35 114 50)" />
        </>
    ),

    /*
     * A vase of bare branches with a folded note pegged to each.
     *
     * The money tree as it is actually made at a kitchen table: twigs, not a
     * canopy. Three notes because one reads as a price tag and five as a
     * hedge.
     */
    money_tree: (
        <>
            {/* The vase and the branches. */}
            <path d="M66 78h28l4 30H62z" className="fill-ill-orange" />
            <path d="M80 78V28M80 62c-10-4-18-12-22-24M80 56c10-4 18-12 22-24" />
            {/* A note on the end of each branch. */}
            <path d="M46 32h18v12H46z" className="fill-ill-mint" />
            <path d="M96 26h18v12H96z" className="fill-ill-mint" />
            <path d="M71 16h18v12H71z" className="fill-ill-mint" />
        </>
    ),

    /*
     * A banknote folded into a shirt.
     *
     * Sleeves, a collar, and the note's own oval still showing through the
     * front, so it reads as money folded rather than a shirt drawn.
     */
    folded_note: (
        <>
            <path d="M56 34l-20 10 6 16 12-4v50h52V56l12 4 6-16-20-10-8 8H64z" className="fill-ill-mint" />
            <path d="M68 34l12 14 12-14" />
            <ellipse cx="80" cy="78" rx="9" ry="12" className="stroke-accent" />
            <path d="M62 64v30M98 64v30" className="stroke-accent" strokeDasharray="3 4" />
        </>
    ),

    /*
     * A jar filling with coins, one still on its way in.
     *
     * The label is blank but for one line: what the jar is for is the
     * article's point, and the reader supplies it.
     */
    coin_jar: (
        <>
            {/* The lid, with the slot in it, and the coin above. */}
            <path d="M58 24h44v8H58z" className="fill-ill-red" />
            <path d="M72 28h16" />
            <circle cx="80" cy="10" r="6" className="stroke-accent" />
            <path d="M54 32h52v64a10 10 0 0 1-10 10H64a10 10 0 0 1-10-10z" className="fill-ill-sky" />
            {/* The label. */}
            <path d="M64 44h32v18H64z" className="fill-ill-cream" />
            <path d="M70 53h20" />
            {/* The coins, in uneven stacks. */}
            <ellipse cx="80" cy="92" rx="20" ry="4" className="fill-ill-gold" />
            <ellipse cx="72" cy="84" rx="14" ry="4" className="fill-ill-gold" />
            <ellipse cx="86" cy="76" rx="14" ry="4" className="fill-ill-gold" />
        </>
    ),

    /*
     * A map with a dotted route and a cross at the end of it.
     *
     * The curled edges are what make a sheet a map rather than a letter; the
     * tree and the house on the way are the clues.
     */
    treasure_map: (
        <>
            <path d="M28 24c8-6 8 6 16 0h72c8 6 8-6 16 0v68c-8 6-8-6-16 0H44c-8-6-8 6-16 0z" className="fill-ill-apricot" />
            {/* The route, and where it ends. */}
            <path d="M42 78c14-4 12-24 26-26s10 18 26 12 8-16 18-14" strokeDasharray="4 5" />
            <path d="M104 44l12 12M116 44l-12 12" className="stroke-accent" strokeWidth={3} />
            {/* A tree and a house on the way. */}
            <path d="M56 46l6-10 6 10zM62 46v6" />
            <path d="M86 74h12v10H86zM84 74l8-8 8 8" />
        </>
    ),

    /*
     * A booklet of vouchers, one torn off along the perforation.
     *
     * The torn one is the point: a voucher nobody redeems is a card, and the
     * article is about ones that get used.
     */
    coupon_book: (
        <>
            {/* The stack, and the perforation across it. */}
            <path d="M52 28h72v60" />
            <path d="M46 34h72v60" />
            <path d="M40 40h72v60H40z" className="fill-ill-gold" />
            <path d="M40 62h72" strokeDasharray="3 4" />
            <path d="M52 50h30M52 74h48M52 86h36" />
            <circle cx="98" cy="50" r="6" className="stroke-accent" />
            {/* The one that has been torn off. */}
            <path d="M118 70l26 6-4 18-26-6z" className="fill-ill-gold" />
            <path d="M124 80l14 3" className="stroke-accent" />
        </>
    ),

    /*
     * A letter beside the envelope it came in.
     *
     * Lines of handwriting, not print, and the seal on the envelope in the
     * accent: the two things that say it was written for one person.
     */
    letter: (
        <>
            <path d="M30 28h56v72H30z" className="fill-ill-cream" />
            <path d="M40 44h36M40 54h36M40 64h26M40 80h20" />
            {/* The envelope, seal side up. */}
            <path d="M92 50h44v36H92z" className="fill-ill-apricot" />
            <path d="M92 50l22 18 22-18" />
            <circle cx="114" cy="68" r="5" className="fill-ill-red" />
        </>
    ),

    /*
     * A cutting in a glass of water, roots showing.
     *
     * The roots are in the accent because they are the gift: the leaves came
     * off somebody else's plant, the roots grew for this one.
     */
    plant_cutting: (
        <>
            {/* The glass, and the water in it. */}
            <path d="M58 60l3 44h38l3-44z" className="fill-ill-sky/60 stroke-none" />
            <path d="M56 40h48l-4 64H60z" />
            <path d="M58 60h44" />
            {/* The stem, two leaves, and the roots under the water line. */}
            <path d="M80 96V26" />
            <path d="M80 42c-10 0-18-6-20-16 10 0 18 6 20 16zM80 34c10 0 18-6 20-16-10 0-18 6-20 16z" className="fill-ill-mint" />
            <path d="M80 70c-8 4-14 10-18 18M80 78c6 6 10 12 12 16M80 84c-4 6-6 10-6 14" className="stroke-accent" />
        </>
    ),

    /*
     * A month on the wall with one day ringed.
     *
     * Time given is a date kept, and the ring is the only thing on the page
     * that was put there by hand.
     */
    calendar_day: (
        <>
            <path d="M34 30h92v70H34z" className="fill-ill-cream" />
            <path d="M34 46h92" />
            <path d="M52 22v14M108 22v14" />
            {/* The days. */}
            <path d="M48 60h8M64 60h8M80 60h8M96 60h8M112 60h8M48 74h8M64 74h8M96 74h8M112 74h8M48 88h8M64 88h8M80 88h8" />
            <path d="M80 74h8" />
            <circle cx="84" cy="74" r="9" className="stroke-accent" />
        </>
    ),

    /*
    |--------------------------------------------------------------------------
    | Added 2026-11-08, with the colour: the personas and articles that fell
    | back to `someone` or `article` because nothing drawn fitted them.
    |--------------------------------------------------------------------------
    */

    /* A tabby sitting up, and a ball of wool. */
    cat: (
        <>
            <path d="M30 104h104" />
            <path d="M94 98c16 2 22-10 12-20" strokeWidth={8} />
            <path d="M94 98c16 2 22-10 12-20" className="stroke-ill-orange" strokeWidth={4} />
            <path d="M52 102c-4-24 4-42 22-42s26 18 22 42z" className="fill-ill-orange" />
            <path d="M58 82h8M82 82h8M60 92h6M82 92h6" className="stroke-ill-cocoa" strokeWidth={2.5} />
            <path d="M60 44l-3-17 13 9zM88 44l3-17-13 9z" className="fill-ill-orange" />
            <circle cx="74" cy="48" r="16" className="fill-ill-orange" />
            <ellipse cx="68" cy="48" rx="2" ry="3" className="fill-ill-ink" />
            <ellipse cx="80" cy="48" rx="2" ry="3" className="fill-ill-ink" />
            <path d="M72 54h4l-2 2.4z" className="fill-ill-red" />
            <path d="M58 54h-8M58 57l-7 2M90 54h8M90 57l7 2" strokeWidth={1.4} />
            <circle cx="124" cy="92" r="12" className="fill-ill-sky" />
            <path d="M114 87c6 1 14 7 18 13M116 99c4-6 11-11 18-11M120 81c-1 6 2 15 8 21" strokeWidth={1.5} />
            <path d="M112 97c-6 5-12 3-16 6" strokeWidth={1.5} />
        </>
    ),

    /* Two people and a heart. For a partner. */
    partner: (
        <>
            <path d="M30 108c0-22 12-32 28-32s28 10 28 32z" className="fill-ill-sky" />
            <path d="M74 108c0-22 12-32 28-32s28 10 28 32z" className="fill-ill-orange" />
            <circle cx="58" cy="60" r="14" className="fill-ill-apricot" />
            <path d="M44 60c-2-12 6-18 14-18s16 6 14 16c-8-6-18-4-28 2z" className="fill-ill-cocoa" />
            <circle cx="102" cy="60" r="14" className="fill-ill-apricot" />
            <path d="M88 62c-3-14 5-22 14-22 10 0 17 8 15 22-3-8-9-12-15-10-6-3-11 2-14 10z" className="fill-ill-gold" />
            <path d="M53 64c2 2 6 2 8 0M97 64c2 2 6 2 8 0" strokeWidth={1.6} />
            <path d="M80 34c-4-9-17-7-15 3 2 7 15 13 15 13s13-6 15-13c2-10-11-12-15-3z" className="fill-ill-red" />
        </>
    ),

    /* A bunch of tulips in paper, and a heart on a string. For mum. */
    mum: (
        <>
            <path d="M80 70V40M80 70L64 46M80 70l16-24" className="stroke-ill-green" />
            <path d="M80 64c-8-2-14-8-14-16 8 2 13 8 14 16z" className="fill-ill-mint" />
            <path d="M56 30c0 10 4 16 8 16s8-6 8-16l-4 4-4-6-4 6z" className="fill-ill-red" />
            <path d="M88 30c0 10 4 16 8 16s8-6 8-16l-4 4-4-6-4 6z" className="fill-ill-red" />
            <path d="M72 24c0 10 4 16 8 16s8-6 8-16l-4 4-4-6-4 6z" className="fill-ill-orange" />
            <path d="M54 58h52l-18 46H72z" className="fill-ill-apricot" />
            <path d="M68 78h24" className="stroke-ill-red" strokeWidth={4} />
            <path d="M92 78c8 0 14-2 18-6" strokeWidth={1.5} />
            <path d="M120 66c-3-6-12-5-11 2 1 5 11 10 11 10s10-5 11-10c1-7-8-8-11-2z" className="fill-ill-red" />
        </>
    ),

    /* A tie and a wristwatch. For dad. */
    dad: (
        <>
            <path d="M58 14l14 10-6 10zM102 14l-14 10 6 10z" className="fill-ill-cream" />
            <path d="M72 24h16l-3 12H75z" className="fill-ill-sky" />
            <path d="M75 36h10l8 52-13 14-13-14z" className="fill-ill-sky" />
            <path d="M77 48l8-5M74 64l12-8M71 80l16-10" />
            <rect x="118" y="40" width="14" height="56" rx="4" className="fill-ill-cocoa" />
            <circle cx="125" cy="68" r="12" className="fill-ill-gold" />
            <circle cx="125" cy="68" r="8" className="fill-ill-cream" />
            <path d="M125 68v-5M125 68h4" />
        </>
    ),

    /* A teddy bear and a ball. For a child. */
    child: (
        <>
            <path d="M24 106h120" />
            <ellipse cx="76" cy="84" rx="20" ry="18" className="fill-ill-kraft" />
            <circle cx="56" cy="76" r="7" className="fill-ill-kraft" />
            <circle cx="96" cy="76" r="7" className="fill-ill-kraft" />
            <ellipse cx="64" cy="100" rx="8" ry="6" className="fill-ill-kraft" />
            <ellipse cx="88" cy="100" rx="8" ry="6" className="fill-ill-kraft" />
            <ellipse cx="76" cy="86" rx="10" ry="9" className="fill-ill-apricot" />
            <circle cx="64" cy="36" r="7" className="fill-ill-kraft" />
            <circle cx="88" cy="36" r="7" className="fill-ill-kraft" />
            <circle cx="76" cy="50" r="16" className="fill-ill-kraft" />
            <ellipse cx="76" cy="56" rx="7" ry="5" className="fill-ill-apricot" />
            <ellipse cx="76" cy="54" rx="2.5" ry="2" className="fill-ill-ink" />
            <circle cx="70" cy="46" r="1.8" className="fill-ill-ink" />
            <circle cx="82" cy="46" r="1.8" className="fill-ill-ink" />
            <path d="M70 66l6 3 6-3v6l-6-3-6 3z" className="fill-ill-sky" />
            <circle cx="126" cy="92" r="12" className="fill-ill-red" />
            <path d="M114 92h24M126 80c-6 8-6 16 0 24" />
        </>
    ),

    /* A pram. For new parents and for a birth. */
    baby: (
        <>
            <path d="M36 66h72c0 16-14 26-36 26S36 82 36 66z" className="fill-ill-apricot" />
            <path d="M40 66a32 32 0 0 1 32-32v32z" className="fill-ill-sky" />
            <path d="M108 66l12-20h8" />
            <path d="M56 90l-4 6M90 90l4 6" />
            <circle cx="50" cy="100" r="9" className="fill-ill-cocoa" />
            <circle cx="50" cy="100" r="3" className="fill-ill-cream" />
            <circle cx="96" cy="100" r="9" className="fill-ill-cocoa" />
            <circle cx="96" cy="100" r="3" className="fill-ill-cream" />
            <path d="M128 24l2 5 5 1-4 3 1 5-4-3-4 3 1-5-4-3 5-1z" className="fill-ill-gold" />
        </>
    ),

    /* A rocking chair with a blanket, and the knitting beside it. For grandparents. */
    grandparents: (
        <>
            <path d="M28 100c30 8 70 8 100 0" strokeWidth={3} />
            <path d="M50 84l-6 16M94 84l6 16" />
            <path d="M44 76V34a11 11 0 0 1 22 0v42z" className="fill-ill-kraft" />
            <path d="M44 76h56v8H44z" className="fill-ill-kraft" />
            <path d="M64 56h34v20" />
            <path d="M40 40h30v26l-5-4-5 4-5-4-5 4-5-4-5 4z" className="fill-ill-red" />
            <path d="M40 50h30" className="stroke-ill-gold" strokeWidth={3} />
            <path d="M116 64l14 18M132 64l-12 18" className="stroke-ill-cocoa" strokeWidth={2.5} />
            <circle cx="122" cy="80" r="9" className="fill-ill-sky" />
            <path d="M108 86h28l-4 16h-20z" className="fill-ill-kraft" />
        </>
    ),

    /* A tall one and a small one. For a brother or sister. */
    siblings: (
        <>
            <path d="M34 108c0-24 12-36 28-36s28 12 28 36z" className="fill-ill-red" />
            <circle cx="62" cy="50" r="16" className="fill-ill-apricot" />
            <path d="M46 50c0-12 7-20 16-20s16 8 16 20c-6-6-24-6-32 0z" className="fill-ill-cocoa" />
            <path d="M84 108c0-18 10-26 22-26s22 8 22 26z" className="fill-ill-sky" />
            <circle cx="92" cy="62" r="4" className="fill-ill-gold" />
            <circle cx="120" cy="62" r="4" className="fill-ill-gold" />
            <circle cx="106" cy="66" r="13" className="fill-ill-apricot" />
            <path d="M93 64c0-10 6-14 13-14s13 4 13 14c-6-5-20-5-26 0z" className="fill-ill-gold" />
            <path d="M57 56c3 2 7 2 10 0M102 70c2 2 6 2 8 0" strokeWidth={1.6} />
        </>
    ),

    /* Two glasses meeting. For a friend. */
    friend: (
        <>
            <g transform="rotate(-12 60 70)">
                <path d="M76 46h6a8 8 0 0 1 0 16h-6" />
                <path d="M44 34h32l-4 56H48z" className="fill-ill-gold" />
                <path d="M44 34v-4a6 6 0 0 1 6-6h20a6 6 0 0 1 6 6v4z" className="fill-ill-cream" />
                <circle cx="56" cy="60" r="2" className="fill-ill-cream" />
                <circle cx="62" cy="74" r="2" className="fill-ill-cream" />
            </g>
            <g transform="rotate(12 100 70)">
                <path d="M84 46h-6a8 8 0 0 0 0 16h6" />
                <path d="M84 34h32l-4 56H88z" className="fill-ill-gold" />
                <path d="M84 34v-4a6 6 0 0 1 6-6h20a6 6 0 0 1 6 6v4z" className="fill-ill-cream" />
                <circle cx="102" cy="64" r="2" className="fill-ill-cream" />
            </g>
            <path d="M80 14V6M70 16l-5-6M90 16l5-6" className="stroke-ill-orange" strokeWidth={2.5} />
        </>
    ),

    /* A sticky note, a mug and a pen on a desk. For a colleague. */
    colleague: (
        <>
            <path d="M20 104h124" />
            <path d="M30 30h46v46H30z" className="fill-ill-gold" transform="rotate(-6 53 53)" />
            <path d="M38 44h28M38 54h22M38 64h16" transform="rotate(-6 53 53)" />
            <path d="M122 60h6a8 8 0 0 1 0 16h-6" />
            <path d="M88 52h34v34a8 8 0 0 1-8 8H96a8 8 0 0 1-8-8z" className="fill-ill-sky" />
            <path d="M98 44c4-4 0-8 4-12M110 44c4-4 0-8 4-12" />
            <path d="M28 96l46-8 1 7-46 8z" className="fill-ill-red" />
            <path d="M74 88l9 3-8 4z" className="fill-ill-cream" />
        </>
    ),

    /* A bottle with a bow, and a glass poured. For a host. */
    host: (
        <>
            <path d="M62 24h12v18c8 4 12 10 12 18v42a4 4 0 0 1-4 4H54a4 4 0 0 1-4-4V60c0-8 4-14 12-18z" className="fill-ill-red" />
            <path d="M62 24h12v6H62z" className="fill-ill-gold" />
            <path d="M54 66h28v20H54z" className="fill-ill-cream" />
            <path d="M60 74h16M60 80h10" />
            <ellipse cx="61" cy="44" rx="7" ry="4" className="fill-ill-gold" transform="rotate(-20 61 44)" />
            <ellipse cx="75" cy="44" rx="7" ry="4" className="fill-ill-gold" transform="rotate(20 75 44)" />
            <path d="M106 50h24c0 14-5 22-12 22s-12-8-12-22z" className="fill-ill-cream" />
            <path d="M107 58h22c-1 8-5 14-11 14s-10-6-11-14z" className="fill-ill-red" />
            <path d="M118 72v20M108 94h20" />
        </>
    ),

    /* An apple on a stack of books, and a pencil. For a teacher. */
    teacher: (
        <>
            <path d="M30 84h70v12H30z" className="fill-ill-sky" />
            <path d="M36 72h62v12H36z" className="fill-ill-mint" />
            <path d="M66 42c-6-6-22-4-22 12 0 14 10 20 16 18 3-1 5-1 8 0 6 2 16-4 16-18 0-16-16-18-18-12z" className="fill-ill-red" />
            <path d="M66 42c0-6 2-10 6-12" />
            <path d="M70 34c6-6 12-4 14-2-4 4-10 6-14 2z" className="fill-ill-mint" />
            <g transform="rotate(18 117 60)">
                <path d="M112 18h10v6h-10z" className="fill-ill-red" />
                <path d="M112 24h10v60h-10z" className="fill-ill-gold" />
                <path d="M112 84l5 12 5-12z" className="fill-ill-apricot" />
            </g>
        </>
    ),

    /* A laptop open on a desk, a plant beside it. For working from home. */
    home_office: (
        <>
            <path d="M16 86h132" />
            <rect x="40" y="28" width="66" height="46" rx="4" className="fill-ill-sky" />
            <path d="M48 36h50v30H48z" className="fill-ill-cream" />
            <path d="M54 44h30M54 52h22" />
            <path d="M32 74h82l6 10H26z" className="fill-ill-apricot" />
            <path d="M129 74c-8-2-12-10-10-18 8 2 12 10 10 18zM129 74c6-4 12-6 14-14-8-2-12 4-14 14z" className="fill-ill-mint" />
            <path d="M120 74h18l-3 12h-12z" className="fill-ill-orange" />
        </>
    ),

    /* Three building bricks, stacked. For somebody who builds. */
    building_blocks: (
        <>
            <path d="M22 102h120" />
            <path d="M36 72h10v6H36zM54 72h10v6H54zM72 72h10v6H72z" className="fill-ill-red" />
            <path d="M30 78h64v24H30z" className="fill-ill-red" />
            <path d="M68 42h10v6H68zM86 42h10v6H86z" className="fill-ill-sky" />
            <path d="M62 48h48v24H62z" className="fill-ill-sky" />
            <path d="M106 76h8v6h-8zM118 76h8v6h-8z" className="fill-ill-gold" />
            <path d="M100 82h32v20h-32z" className="fill-ill-gold" />
        </>
    ),

    /* A ball of wool with needles in it, and scissors. For somebody who makes things. */
    crafts: (
        <>
            <path d="M54 16l20 40M84 16l-14 40" className="stroke-ill-cocoa" strokeWidth={3} />
            <circle cx="54" cy="16" r="4" className="fill-ill-gold" />
            <circle cx="84" cy="16" r="4" className="fill-ill-gold" />
            <circle cx="64" cy="70" r="28" className="fill-ill-red" />
            <path d="M40 58c14 4 30 16 40 34M44 86c8-14 24-26 44-28M52 46c-2 12 4 30 18 46" strokeWidth={1.6} />
            <path d="M90 80c10 6 12 14 22 14" className="stroke-ill-red" />
            <path d="M121 88l14-40M131 88l-14-40" strokeWidth={2.5} />
            <circle cx="118" cy="94" r="7" className="fill-ill-orange" />
            <circle cx="134" cy="94" r="7" className="fill-ill-orange" />
        </>
    ),

    /* A backpack and a book. For a student. */
    student: (
        <>
            <path d="M64 34v-6a10 10 0 0 1 20 0v6" />
            <path d="M46 50c-6 0-8 6-8 14v20" />
            <rect x="46" y="34" width="56" height="70" rx="14" className="fill-ill-sky" />
            <path d="M50 52c8 6 40 6 48 0" />
            <rect x="54" y="68" width="40" height="28" rx="6" className="fill-ill-gold" />
            <path d="M60 76h28" />
            <path d="M110 64h26v40h-26z" className="fill-ill-red" />
            <path d="M114 64v40" />
        </>
    ),

    /* A mortarboard and a rolled diploma. For somebody who graduated. */
    graduation: (
        <>
            <path d="M52 52v14c0 6 12 12 28 12s28-6 28-12V52l-28 8z" className="fill-ill-cocoa" />
            <path d="M80 24l52 18-52 18-52-18z" className="fill-ill-ink" />
            <path d="M80 42l30 8v22" className="stroke-ill-gold" strokeWidth={2.5} />
            <path d="M106 72h8l2 10h-12z" className="fill-ill-gold" />
            <rect x="34" y="88" width="80" height="14" rx="7" className="fill-ill-cream" />
            <path d="M72 88v14" className="stroke-ill-red" strokeWidth={4} />
        </>
    ),

    /* A house with lit windows and a signal. For somebody who automates the house. */
    smart_home: (
        <>
            <path d="M36 60l44-34 44 34v42H36z" className="fill-ill-apricot" />
            <path d="M28 64l52-40 52 40" className="stroke-ill-red" strokeWidth={4} />
            <path d="M70 76h20v26H70z" className="fill-ill-cocoa" />
            <path d="M44 70h18v16H44zM98 70h18v16H98z" className="fill-ill-gold" />
            <path d="M118 30a16 16 0 0 1 16 16M118 20a26 26 0 0 1 26 26" className="stroke-ill-green" strokeWidth={2.5} />
            <circle cx="118" cy="46" r="3" className="fill-ill-green stroke-none" />
        </>
    ),

    /* A shoe set out with a carrot in it, and pepernoten. Sinterklaas. */
    sinterklaas: (
        <>
            <path d="M30 66h28v16l30 8c6 2 10 6 10 12H30z" className="fill-ill-red" />
            <ellipse cx="44" cy="66" rx="14" ry="4" className="fill-ill-cocoa" />
            <path d="M38 26h12l-4 40h-4z" className="fill-ill-orange" />
            <path d="M40 38h4M42 50h4" />
            <path d="M44 26c-4-6-10-8-14-6M44 26c0-6 2-10 6-12M44 26c4-4 10-4 12-2" className="stroke-ill-green" strokeWidth={2.5} />
            <circle cx="120" cy="86" r="6" className="fill-ill-kraft" />
            <circle cx="112" cy="97" r="6" className="fill-ill-kraft" />
            <circle cx="126" cy="98" r="6" className="fill-ill-kraft" />
            <circle cx="136" cy="88" r="5" className="fill-ill-kraft" />
            <path d="M116 30l3 6 7 1-5 5 1 7-6-3-6 3 1-7-5-5 7-1z" className="fill-ill-gold" />
        </>
    ),

    /* Two gift cards, fanned, one with a bow. */
    gift_card: (
        <>
            <rect x="34" y="34" width="72" height="46" rx="6" className="fill-ill-sky" transform="rotate(-10 70 57)" />
            <rect x="52" y="46" width="76" height="48" rx="6" className="fill-ill-red" />
            <path d="M92 46v48" className="stroke-ill-gold" strokeWidth={5} />
            <path d="M92 46c-6-10-18-10-16-2 1 5 10 4 16 2zM92 46c6-10 18-10 16-2-1 5-10 4-16 2z" className="fill-ill-gold" />
            <path d="M60 56h14v10H60z" className="fill-ill-gold" />
            <path d="M60 84h20" className="stroke-ill-cream" strokeWidth={3} />
        </>
    ),

    /* A carved pumpkin, lit. Halloween. */
    halloween: (
        <>
            <ellipse cx="80" cy="72" rx="44" ry="32" className="fill-ill-orange" />
            <path d="M58 44c-12 8-12 48 0 56M102 44c12 8 12 48 0 56" />
            <path d="M76 42c0-8 2-14 8-16l4 4c-4 2-6 6-6 12z" className="fill-ill-green" />
            <path d="M58 64l8-10 8 10zM86 64l8-10 8 10z" className="fill-ill-gold" />
            <path d="M56 80c8 10 40 10 48 0l-6 4-6-4-6 4-6-4-6 4-6-4-6 4z" className="fill-ill-gold" />
        </>
    ),

    /* A witch's hat and a mask. Dressing up. */
    costume: (
        <>
            <ellipse cx="62" cy="62" rx="40" ry="8" className="fill-ill-ink" />
            <path d="M42 60C52 44 56 30 54 12c12 6 24 22 30 48z" className="fill-ill-ink" />
            <path d="M46 52c12 4 28 4 36 0l2 8c-10 4-28 4-40 0z" className="fill-ill-gold" />
            <path d="M100 74c-6-2-8-6-8-10" />
            <path d="M100 76c0-8 8-12 18-10 4 1 6 3 8 3s4-2 8-3c10-2 18 2 18 10s-6 14-14 14c-6 0-8-4-12-4s-6 4-12 4c-8 0-14-6-14-14z" className="fill-ill-red" />
            <ellipse cx="114" cy="76" rx="5" ry="4" className="fill-ill-cream" />
            <ellipse cx="138" cy="76" rx="5" ry="4" className="fill-ill-cream" />
        </>
    ),

    /* A market stall with lights, a mug and a gingerbread biscuit. Christmas markets. */
    christmas_market: (
        <>
            <path d="M22 22c20 10 38 10 58 0s38-10 58 0" strokeWidth={1.5} />
            <circle cx="36" cy="27" r="3.4" className="fill-ill-gold" strokeWidth={1.4} />
            <circle cx="58" cy="28.5" r="3.4" className="fill-ill-red" strokeWidth={1.4} />
            <circle cx="80" cy="22" r="3.4" className="fill-ill-gold" strokeWidth={1.4} />
            <circle cx="102" cy="16" r="3.4" className="fill-ill-mint" strokeWidth={1.4} />
            <circle cx="124" cy="18" r="3.4" className="fill-ill-gold" strokeWidth={1.4} />
            <path d="M36 50v54M124 50v54" strokeWidth={3} />
            <path d="M28 36h104v14H28z" className="fill-ill-cream" />
            <path d="M28 36h21v14H28zM70 36h21v14H70zM111 36h21v14h-21z" className="fill-ill-red stroke-none" />
            <path d="M28 36h104v14H28z" />
            <path d="M28 50a7 5 0 0 0 14 0a7 5 0 0 0 14 0a7 5 0 0 0 14 0a7 5 0 0 0 14 0a7 5 0 0 0 14 0a7 5 0 0 0 14 0a7 5 0 0 0 14 0a7 5 0 0 0 6 0" className="fill-ill-red" />
            <path d="M26 82h108v22H26z" className="fill-ill-kraft" />
            <path d="M48 66h14v16H48z" className="fill-ill-red" />
            <path d="M62 70h3a4 4 0 0 1 0 8h-3" />
            <path d="M51 61c1-2 0-4 1-5M57 61c1-2 0-4 1-5" strokeWidth={1.4} />
            <circle cx="86" cy="74" r="8" className="fill-ill-cocoa" />
            <path d="M83 72h1M88 72h1M83 77c2 1.5 4 1.5 6 0" className="stroke-ill-cream" strokeWidth={1.6} />
            <path d="M104 82l8-18 8 18z" className="fill-ill-mint" />
        </>
    ),

    /* A clipboard list, two items ticked, and a heart. Keeping a wish list. */
    wish_list: (
        <>
            <rect x="42" y="22" width="64" height="84" rx="6" className="fill-ill-kraft" />
            <path d="M50 32h48v66H50z" className="fill-ill-cream" />
            <rect x="64" y="16" width="20" height="12" rx="3" className="fill-ill-sky" />
            <path d="M56 42h8v8h-8zM56 58h8v8h-8zM56 74h8v8h-8z" />
            <path d="M57 46l2 2 4-4M57 62l2 2 4-4" className="stroke-ill-green" strokeWidth={2.4} />
            <path d="M70 46h22M70 62h22M70 78h16" />
            <path d="M128 50c-4-9-17-7-15 3 2 7 15 13 15 13s13-6 15-13c2-10-11-12-15-3z" className="fill-ill-red" />
        </>
    ),

    /* A cracked mug and a tube of glue. Repair rather than replace. */
    repair: (
        <>
            <path d="M24 96h72" />
            <path d="M88 50h6a10 10 0 0 1 0 20h-6" />
            <path d="M38 40h50v40a14 14 0 0 1-14 14H52a14 14 0 0 1-14-14z" className="fill-ill-sky" />
            <path d="M62 40l-4 12 8 6-6 12 6 8" />
            <path d="M104 98l28-28 12 12-28 28z" className="fill-ill-gold" />
            <path d="M112 98l20-20" className="stroke-ill-cream" strokeWidth={3} />
            <path d="M136 74l8-8 4 4-8 8z" className="fill-ill-red" />
        </>
    ),

    /* Two messages and a parcel. Buying from a private seller. */
    private_sale: (
        <>
            <path d="M30 22h50a6 6 0 0 1 6 6v20a6 6 0 0 1-6 6H46l-10 8v-8h-6a6 6 0 0 1-6-6V28a6 6 0 0 1 6-6z" className="fill-ill-sky" />
            <path d="M34 34h40M34 42h26" />
            <path d="M80 44h50a6 6 0 0 1 6 6v20a6 6 0 0 1-6 6h-6v8l-10-8H80a6 6 0 0 1-6-6V50a6 6 0 0 1 6-6z" className="fill-ill-mint" />
            <path d="M84 56h40M84 64h20" />
            <path d="M22 74h44v32H22z" className="fill-ill-kraft" />
            <path d="M22 74l8-10h28l8 10M44 64v42" />
        </>
    ),

    /* A bank card and the arrow that keeps coming round. Subscriptions. */
    subscription: (
        <>
            <rect x="22" y="40" width="80" height="50" rx="7" className="fill-ill-sky" />
            <path d="M22 52h80v10H22z" className="fill-ill-ink stroke-none" />
            <path d="M32 70h16v10H32z" className="fill-ill-gold" />
            <path d="M58 80h34" />
            <path d="M128 42a20 20 0 1 1-18 11" className="stroke-ill-red" strokeWidth={3} />
            <path d="M104 50l6 3 3-7" className="stroke-ill-red" strokeWidth={3} />
        </>
    ),

    /* A parcel and a clock. The delivery that does not come. */
    late_delivery: (
        <>
            <path d="M26 50h64v50H26z" className="fill-ill-kraft" />
            <path d="M26 50l10-14h44l10 14" />
            <path d="M54 36h8v64h-8z" className="fill-ill-gold" />
            <circle cx="114" cy="58" r="26" className="fill-ill-cream" strokeWidth={3} />
            <path d="M114 58V42M114 58l10 8" strokeWidth={2.5} />
            <path d="M114 36v3M114 77v3M92 58h3M133 58h3" />
        </>
    ),

    /* A bank card and a shield with a tick. How you pay. */
    payment: (
        <>
            <g transform="rotate(-8 61 63)">
                <rect x="22" y="38" width="78" height="50" rx="7" className="fill-ill-red" />
                <rect x="32" y="52" width="16" height="12" rx="2" className="fill-ill-gold" />
                <path d="M58 76h32" className="stroke-ill-cream" strokeWidth={3} />
            </g>
            <path d="M118 30l26 10v20c0 18-12 30-26 36-14-6-26-18-26-36V40z" className="fill-ill-mint" />
            <path d="M106 62l8 8 16-16" className="stroke-ill-green" strokeWidth={3} />
        </>
    ),

    /* A rosette with ribbons. A trust mark. */
    trust_mark: (
        <>
            <path d="M66 74l-10 34 12-6 8 10 8-30zM94 74l10 34-12-6-8 10-8-30z" className="fill-ill-red" />
            <circle cx="80" cy="54" r="32" className="fill-ill-gold" />
            <circle cx="80" cy="54" r="24" className="fill-ill-cream" strokeDasharray="3 4" />
            <path d="M70 54l7 7 14-14" className="stroke-ill-green" strokeWidth={3} />
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
            <path d="M38 20h60l24 24v72H38z" className="fill-ill-cream" />
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
            className={className ? `stroke-ill-ink ${className}` : 'stroke-ill-ink'}
            fill="none"
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
