import type { ReactNode } from 'react'

export type PersonaSceneKey =
    | 'coffee'
    | 'cooking'
    | 'racing'
    | 'has_everything'
    | 'dog'
    | 'photography'
    | 'diy'
    | 'outdoors'
    | 'someone'

/**
 * The drawing on a gift persona, at card size.
 *
 * Same `160x116` viewBox, same stroke weight and same
 * `currentColor`-plus-one-accent-wash rule as `CoveIllustration` and
 * `ListIllustration`. The three appear on pages that link to each other, and
 * two illustration styles in one place reads as two websites.
 *
 * ## Why a persona is drawn and not photographed
 *
 * The shelf used the first buyable product's photo as each persona's cover.
 * That made a page about a *person* look like a product category, and the cover
 * moved whenever stock did — the same persona wearing a different face week to
 * week, for a reason no reader could see. A drawing is chosen once, by the
 * person writing the Cove, and stays.
 *
 * ## What each one shows
 *
 * The *interest*, not the person having it. A drawn human at 160px is a face,
 * and a face is a specific person — which is the opposite of what a persona is
 * for. "The coffee obsessive" has to be recognisable to anyone whose brother
 * grinds his own beans, so the objects carry it. `someone` is the exception and
 * is deliberately a silhouette: no features, because it stands for whoever has
 * not been described yet.
 *
 * Decorative throughout: `aria-hidden`, because every one of these sits beside
 * the persona's own title and blurb.
 */
const scenes: Record<PersonaSceneKey, ReactNode> = {
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
}

/**
 * `scene` is nullable on the model and null means `someone` — every persona
 * written before the field existed has none, and a drawing is not a reason to
 * hide a page.
 */
export default function PersonaIllustration({
    name,
    className,
}: {
    name: PersonaSceneKey | null
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
