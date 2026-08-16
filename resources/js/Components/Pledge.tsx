import { router, usePage } from '@inertiajs/react'
import { useState } from 'react'
import type { SharedProps } from '../types'
import { formatPrice } from '../types'
import { useTranslations } from '../useTranslations'

/** What the server is willing to say about the money on one item. */
export interface Contributions {
    /** Cents, pooled so far. */
    total: number
    /** How many people are in. Never who, unless `breakdown` is present. */
    count: number
    /** This viewer's own share, in cents. Null when they have not put in. */
    mine: number | null
    /**
     * Who put in what — present **only** for the organiser of a group list.
     * Its absence is the privacy rule, not a loading state.
     */
    breakdown?: { name: string; amount: number }[]
}

/**
 * "I am in for €25."
 *
 * The read half of a feature that shipped complete and unreachable: the model,
 * the controller, both routes and ten copy keys in four languages existed for
 * months with no component referencing any of them, so nothing on any page
 * could pledge and nothing could show that anybody had.
 *
 * One component on two pages, the `ManualItem` precedent — the visitor's view
 * on `Lists/Shared` and the organiser's on `Lists/Show`. The difference between
 * them is entirely in the payload: `breakdown` arrives for the organiser of a
 * group list and for nobody else, so this renders what it is given rather than
 * asking who is looking. A component that re-decided that question would be a
 * second place to get invariant #4 wrong.
 *
 * Collapsed behind a button, like `ManualItem` and for the same reason: the
 * page is a list of presents, and a form standing open under every item reads
 * as the main thing to do with them.
 */
export default function Pledge({
    action,
    contributions,
    canContribute,
    price,
}: {
    /** `/{market}/l/{token}/pledge/{item}` — POST to join, DELETE to leave. */
    action: string
    contributions: Contributions
    canContribute: boolean
    /** The item's price, so a total can be shown against something. */
    price: number | null
}) {
    const { market, auth } = usePage<SharedProps>().props
    const { t, n } = useTranslations()

    const [open, setOpen] = useState(false)
    // Required on write since the table shipped, and collected by nothing until
    // now. Prefilled from the account when there is one: a pledge is a promise
    // made to people, and most people type their own name.
    const [name, setName] = useState(auth.user?.name ?? '')
    const [amount, setAmount] = useState('')
    const [error, setError] = useState<string | null>(null)

    const { total, count, mine, breakdown } = contributions

    function submit(event: React.FormEvent) {
        event.preventDefault()
        setError(null)

        router.post(
            action,
            {
                /*
                 * Euros in the box, cents everywhere else — except that this
                 * endpoint has validated euros since it shipped and multiplies
                 * server-side. Sending cents here would silently inflate every
                 * pledge a hundredfold, so the string goes as typed and only
                 * the comma is normalised: half our markets write €12,50.
                 */
                amount: amount.replace(',', '.'),
                display_name: name,
            },
            {
                preserveScroll: true,
                onSuccess: () => {
                    setAmount('')
                    setOpen(false)
                },
                onError: (errors) => setError(Object.values(errors)[0] ?? null),
            },
        )
    }

    return (
        <div className="mt-4 border-t border-line pt-3">
            {/*
              The total, measured against the price when we know it. "€75
              pledged of €240" is the number that decides whether to put more
              in; "€75 pledged" on its own is a fact about nothing.
            */}
            <p className="text-sm font-medium">
                {total === 0
                    ? t('pledges.none')
                    : price !== null
                      ? t('pledges.pledged', {
                            total: formatPrice(total, market),
                            price: formatPrice(price, market),
                        })
                      : formatPrice(total, market)}
            </p>

            {/*
              How many, never who — unless this is the organiser, below. A
              count is coordination; a list of names and amounts is a ladder,
              and a visible ladder is pressure on whoever put in least.
            */}
            {count > 0 && (
                <p className="mt-1 text-xs text-ink-soft">
                    {count === 1 ? t('pledges.one_in') : t('pledges.count', { count: n(count) })}
                    {mine !== null && ` · ${t('pledges.your_share_is', { amount: formatPrice(mine, market) })}`}
                </p>
            )}

            {breakdown !== undefined && breakdown.length > 0 && (
                <>
                    <ul className="mt-3 space-y-1">
                        {breakdown.map((entry, i) => (
                            <li key={i} className="flex justify-between gap-3 text-sm">
                                <span className="truncate">{entry.name}</span>
                                <span className="shrink-0 tabular-nums text-ink-soft">
                                    {formatPrice(entry.amount, market)}
                                </span>
                            </li>
                        ))}
                    </ul>
                    {/* Said out loud, so the organiser knows the others are not
                        looking at this. */}
                    <p className="mt-2 text-xs text-ink-soft">{t('pledges.organiser_note')}</p>
                </>
            )}

            {canContribute && (
                <div className="mt-3">
                    {mine !== null ? (
                        <button
                            type="button"
                            onClick={() => router.delete(action, { preserveScroll: true })}
                            className="text-xs text-ink-soft underline hover:text-ink"
                        >
                            {t('pledges.leave')}
                        </button>
                    ) : open ? (
                        <form onSubmit={submit} className="space-y-2">
                            <p className="text-xs text-ink-soft">{t('pledges.hint')}</p>

                            <div className="grid gap-2 sm:grid-cols-2">
                                <label className="block text-xs font-medium">
                                    {t('pledges.your_name')}
                                    <input
                                        required
                                        maxLength={80}
                                        value={name}
                                        onChange={(e) => setName(e.target.value)}
                                        className="mt-1 w-full rounded-lg border border-line bg-cream px-3 py-2 text-sm font-normal"
                                    />
                                </label>

                                <label className="block text-xs font-medium">
                                    {t('pledges.amount')}
                                    <input
                                        required
                                        inputMode="decimal"
                                        value={amount}
                                        onChange={(e) => setAmount(e.target.value)}
                                        className="mt-1 w-full rounded-lg border border-line bg-cream px-3 py-2 text-sm font-normal"
                                    />
                                </label>
                            </div>

                            {error && <p className="text-xs text-accent">{error}</p>}

                            <div className="flex gap-2">
                                <button
                                    type="submit"
                                    className="rounded-lg bg-accent px-3 py-1.5 text-xs font-medium text-white"
                                >
                                    {t('pledges.join')}
                                </button>
                                <button
                                    type="button"
                                    onClick={() => setOpen(false)}
                                    className="rounded-lg border border-line px-3 py-1.5 text-xs"
                                >
                                    {t('lists.cancel')}
                                </button>
                            </div>
                        </form>
                    ) : (
                        <button
                            type="button"
                            onClick={() => setOpen(true)}
                            className="rounded-lg border border-line px-3 py-1.5 text-xs hover:border-ink"
                        >
                            {t('pledges.join')}
                        </button>
                    )}
                </div>
            )}
        </div>
    )
}
