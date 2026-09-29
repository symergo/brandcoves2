interface Pole {
    value: string
    label: string
}

/**
 * Which way somebody's taste goes, as pairs of opposites: a two-sided switch
 * per pair, Handig | Design, Modern | Vintage (docs/features/find-a-gift.md).
 *
 * The one taste question since 2026-09-29, when the owner removed "Hoe mag het
 * voelen?" (practical, playful, beautiful) everywhere: the pairs cover it. And
 * a nicer shape than two loose chips with "or" between them: one pill per
 * pair, so a pair reads as one choice and its two ends as each other's
 * opposite. Picking an end clears the other; picking the lit end again clears
 * the pair. At most `max` pairs: somebody who picks six has described nothing,
 * so once full the other pairs are dimmed and do nothing.
 *
 * Shared by Find a gift's questions and My taste, so the two cannot drift.
 */
export default function TastePairs({
    axes,
    selected,
    onChange,
    max = 3,
}: {
    axes: { axis: string; poles: Pole[] }[]
    selected: string[]
    onChange: (next: string[]) => void
    max?: number
}) {
    const choose = (poles: Pole[], value: string) => {
        const ends = poles.map((p) => p.value)
        const without = selected.filter((v) => !ends.includes(v))

        if (selected.includes(value)) {
            onChange(without)
        } else if (without.length < max) {
            onChange([...without, value])
        }
    }

    return (
        <div className="grid gap-2 sm:grid-cols-2 lg:grid-cols-3">
            {axes.map(({ axis, poles }) => {
                const chosen = poles.some((p) => selected.includes(p.value))
                const full = !chosen && selected.length >= max

                return (
                    <div
                        key={axis}
                        role="group"
                        aria-label={poles.map((p) => p.label).join(' / ')}
                        className={`flex rounded-full border p-1 transition ${
                            chosen ? 'border-accent bg-accent/5' : 'border-line bg-card'
                        } ${full ? 'opacity-40' : ''}`}
                    >
                        {poles.map((pole) => {
                            const on = selected.includes(pole.value)

                            return (
                                <button
                                    key={pole.value}
                                    type="button"
                                    aria-pressed={on}
                                    disabled={full}
                                    onClick={() => choose(poles, pole.value)}
                                    className={`min-h-10 flex-1 rounded-full px-3 text-sm transition ${
                                        on ? 'bg-accent font-medium text-white shadow-sm' : 'text-ink hover:bg-cream'
                                    } disabled:cursor-not-allowed`}
                                >
                                    {pole.label}
                                </button>
                            )
                        })}
                    </div>
                )
            })}
        </div>
    )
}
