import { router } from '@inertiajs/react'
import { useState } from 'react'
import { useTranslations } from '../useTranslations'

/**
 * "It is not in the shops you cover."
 *
 * The same form, on the two pages where somebody wants to put something on a
 * list: the owner adding to their own, and a visitor suggesting into somebody
 * else's. One component because it is one act — the endpoints differ, the
 * fields and the rules do not — and two copies would drift the moment one of
 * them gained a field.
 *
 * The caller supplies `action` and any extra payload. What comes back differs
 * by design: the owner's post lands on the list, the visitor's lands as a
 * pending suggestion, and neither page has to know how the other behaves.
 *
 * Collapsed behind a button. Every list starts from a product search, and a
 * form standing permanently open beside it reads as the main way in, which it
 * is not.
 */
export default function ManualItem({
    action,
    data = {},
    hint,
}: {
    action: string
    data?: Record<string, string>
    /** Why this exists, in the words of whichever page is showing it. */
    hint: string
}) {
    const { t } = useTranslations()
    const [open, setOpen] = useState(false)
    const [title, setTitle] = useState('')
    const [url, setUrl] = useState('')
    const [price, setPrice] = useState('')
    const [error, setError] = useState<string | null>(null)

    function submit(event: React.FormEvent) {
        event.preventDefault()
        setError(null)

        router.post(
            action,
            {
                ...data,
                title,
                url: url.trim() || null,
                /*
                 * Euros in the box, cents on the wire (invariant #7). The whole
                 * pipeline is integers; a float entering here is how a price
                 * ends up a cent out three aggregates later.
                 *
                 * Comma or dot: half our markets write €12,50 and typing it the
                 * way you say it should not be a validation error.
                 */
                price: price.trim() === ''
                    ? null
                    : Math.round(Number(price.replace(',', '.')) * 100),
            },
            {
                preserveScroll: true,
                onSuccess: () => {
                    setTitle('')
                    setUrl('')
                    setPrice('')
                    setOpen(false)
                },
                // Server-side rules are the authority — the link check in
                // particular, which is a security rule and not a hint. Showing
                // its message is what stops a rejected link looking like a
                // button that did nothing.
                onError: (errors) => setError(Object.values(errors)[0] ?? null),
            },
        )
    }

    if (!open) {
        return (
            <button
                type="button"
                onClick={() => setOpen(true)}
                className="rounded-lg border border-line px-4 py-2 text-sm hover:border-ink"
            >
                {t('lists.manual_add')}
            </button>
        )
    }

    return (
        <form onSubmit={submit} className="w-full space-y-3 rounded-card border border-line bg-card p-4">
            <p className="text-sm text-ink-soft">{hint}</p>

            <label className="block text-sm font-medium">
                {t('lists.manual_title')}
                <input
                    required
                    autoFocus
                    maxLength={500}
                    value={title}
                    onChange={(e) => setTitle(e.target.value)}
                    className="mt-1 w-full rounded-lg border border-line bg-cream px-3 py-2 text-sm font-normal"
                />
            </label>

            <div className="grid gap-3 sm:grid-cols-[2fr_1fr]">
                <label className="block text-sm font-medium">
                    {t('lists.manual_url')}
                    <input
                        type="url"
                        inputMode="url"
                        maxLength={2048}
                        placeholder="https://"
                        value={url}
                        onChange={(e) => setUrl(e.target.value)}
                        className="mt-1 w-full rounded-lg border border-line bg-cream px-3 py-2 text-sm font-normal"
                    />
                </label>

                <label className="block text-sm font-medium">
                    {t('lists.manual_price')}
                    <input
                        inputMode="decimal"
                        value={price}
                        onChange={(e) => setPrice(e.target.value)}
                        className="mt-1 w-full rounded-lg border border-line bg-cream px-3 py-2 text-sm font-normal"
                    />
                </label>
            </div>

            {/* Said plainly rather than discovered: nothing is fetched from the
                link, so the title is the only thing that will ever show. */}
            <p className="text-xs text-ink-soft">{t('lists.manual_no_preview')}</p>

            {error && <p className="text-sm text-accent">{error}</p>}

            <div className="flex gap-2">
                <button type="submit" className="rounded-lg bg-accent px-4 py-2 text-sm font-medium text-white">
                    {t('lists.manual_save')}
                </button>
                <button
                    type="button"
                    onClick={() => setOpen(false)}
                    className="rounded-lg border border-line px-4 py-2 text-sm"
                >
                    {t('lists.cancel')}
                </button>
            </div>
        </form>
    )
}
