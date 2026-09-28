import { useEffect, useRef } from 'react'

/**
 * The sign-in forms' bot trap (2026-09-28); the server half is
 * MagicLinkController::automated().
 *
 * Found after accounts named "GyfQDROfpEjLMJCPpjuwRjE" appeared on real
 * company addresses: a bot filled in the form, and we mailed a stranger. No
 * captcha, because this form is the way in. Instead:
 *
 * - `useFormClock` measures how long the form has been open, and the form
 *   sends it as `elapsed_ms`. A bot posting straight to the server sends none,
 *   and a script submits faster than a person.
 * - `Honeypot` is a field nobody sees: off-screen, skipped by the keyboard
 *   and hidden from screen readers. A bot filling every field fills it.
 */
export function useFormClock(openedWhen: boolean = true): () => number {
    const opened = useRef(Date.now())

    // The dialog is mounted long before it is shown; start the clock when it opens.
    useEffect(() => {
        if (openedWhen) {
            opened.current = Date.now()
        }
    }, [openedWhen])

    return () => Date.now() - opened.current
}

export function Honeypot({ value, onChange }: { value: string; onChange: (value: string) => void }) {
    return (
        <div aria-hidden="true" className="absolute -left-[9999px] h-px w-px overflow-hidden">
            <label>
                Website
                <input
                    type="text"
                    name="website"
                    tabIndex={-1}
                    autoComplete="off"
                    value={value}
                    onChange={(e) => onChange(e.target.value)}
                />
            </label>
        </div>
    )
}
