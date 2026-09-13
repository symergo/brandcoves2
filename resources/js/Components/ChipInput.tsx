import { useState } from 'react'

/**
 * Words of your own, one at a time, each a chip you can take off again.
 *
 * Type, press Enter or the button, and the word becomes a chip; press the chip
 * and it goes. Lifted out of the Gift Whisperer's "anything to avoid?" step on
 * 2026-09-13 when the interests step needed the same thing for "anything
 * else?" — two copies of an input with a keydown handler is how the two drift.
 *
 * `max` is enforced here because the server enforces it too, with a 422 that
 * puts nothing on screen. Refusing the ninth word at the input is the only
 * place the visitor can see the limit.
 */
export default function ChipInput({
    value,
    onChange,
    placeholder,
    addLabel,
    max,
    inputId,
}: {
    value: string[]
    onChange: (value: string[]) => void
    placeholder: string
    addLabel: string
    max?: number
    inputId?: string
}) {
    const [draft, setDraft] = useState('')

    const full = max !== undefined && value.length >= max

    const add = () => {
        const word = draft.trim()

        if (word === '' || full) {
            return
        }

        // The same word twice adds nothing to a filter and looks like a bug.
        if (value.some((existing) => existing.toLowerCase() === word.toLowerCase())) {
            setDraft('')
            return
        }

        onChange([...value, word])
        setDraft('')
    }

    return (
        <div>
            <div className="flex gap-2">
                <input
                    id={inputId}
                    type="text"
                    className="flex-1 rounded border border-line px-3 py-2 disabled:opacity-50"
                    placeholder={placeholder}
                    value={draft}
                    disabled={full}
                    onChange={(e) => setDraft(e.target.value)}
                    onKeyDown={(e) => {
                        if (e.key === 'Enter') {
                            e.preventDefault()
                            add()
                        }
                    }}
                />
                <button
                    type="button"
                    className="rounded border border-line px-4 text-sm disabled:opacity-50"
                    disabled={full || draft.trim() === ''}
                    onClick={add}
                >
                    {addLabel}
                </button>
            </div>
            {value.length > 0 && (
                <ul className="mt-3 flex flex-wrap gap-2">
                    {value.map((word) => (
                        <li key={word}>
                            <button
                                type="button"
                                className="rounded-full border border-accent bg-accent px-3 py-1 text-sm text-white"
                                onClick={() => onChange(value.filter((w) => w !== word))}
                            >
                                {word} ×
                            </button>
                        </li>
                    ))}
                </ul>
            )}
        </div>
    )
}
