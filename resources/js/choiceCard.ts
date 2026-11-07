/**
 * One answer to "who is it for?", drawn like "Voor mezelf" on Find a gift: a
 * card-cornered button on the card's white, red once chosen (owner,
 * 2026-11-07: "make the relationship pills the same style as Voor mezelf").
 * Smaller than that button, which is first and larger on purpose (2026-09-28),
 * so a row of ten still wraps into a few lines on a phone.
 *
 * Used by the relationship choices on Find a gift and in the list wizard, so
 * the two never drift apart again.
 */
export function choiceCard(selected: boolean): string {
    return `rounded-card border px-4 py-2.5 text-sm font-medium transition ${
        selected ? 'border-accent bg-accent text-white' : 'border-line bg-card hover:border-ink'
    }`
}
