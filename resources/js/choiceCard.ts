/**
 * How a choice looks, chosen or not: one rule for every selection on Find a
 * gift and in the list wizard (owner, 2026-11-07: "the styling of the buttons
 * and selections is not consistent").
 *
 * There were three chosen looks for the same act of choosing: solid red with
 * white text ("Voor mezelf", the relationship buttons, the interest chips), a
 * pale fill with a red border and a check (the person cards), and a red ring
 * with red text (the list wizard's kinds). The owner picked the person card's:
 * a pale fill, a red border, dark text, and a check (`ChoiceCheck`) so
 * "chosen" does not rest on colour alone. Unchosen is the white card with a
 * thin border everywhere.
 *
 * Shapes are shared, sizes are not (owner, the same day): every choice is a
 * card-cornered box, and a person card stays taller and "Voor mezelf" larger
 * because they hold more.
 */
export function choiceState(selected: boolean): string {
    return selected ? 'border-accent bg-accent/5 text-ink' : 'border-line bg-card text-ink hover:border-ink'
}

/** A one-line choice (a relationship, an interest): the box, its padding, its state. */
export function choiceCard(selected: boolean): string {
    return `inline-flex items-center gap-1.5 rounded-card border px-4 py-2.5 text-sm font-medium transition ${choiceState(selected)}`
}
