import { useEffect, useState } from 'react'
import { useTranslations } from './useTranslations'

/**
 * Copy a text and say what happened: the part ShareRow and ShareMenu had
 * written twice (2026-09-27), and which drifted once already.
 *
 * `navigator.clipboard` is undefined outside a secure context (every plain-http
 * address, including the LAN one this gets tested on) and rejects when the page
 * is not focused. Both used to throw into nothing: the button did visibly
 * nothing and the reader had no idea why. So a failure is reported too, and the
 * caller may do the next best thing (`onFail`: ShareRow selects its field).
 *
 * The status clears after three seconds: a confirmation that never leaves
 * stops being read as a confirmation of the press that just happened.
 */
export function useCopy(): { status: string; copy: (value: string, onFail?: () => void) => Promise<void> } {
    const { t } = useTranslations()
    const [status, setStatus] = useState('')

    useEffect(() => {
        if (status === '') return
        const timer = setTimeout(() => setStatus(''), 3000)

        return () => clearTimeout(timer)
    }, [status])

    async function copy(value: string, onFail?: () => void) {
        try {
            await navigator.clipboard.writeText(value)
            setStatus(t('lists.copied'))
        } catch {
            onFail?.()
            setStatus(t('lists.copy_manual'))
        }
    }

    return { status, copy }
}
