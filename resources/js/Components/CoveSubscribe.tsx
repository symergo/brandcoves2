import { useForm, usePage } from '@inertiajs/react'
import type { SharedProps } from '../types'
import { useTranslations } from '../useTranslations'

/**
 * Subscribe to the Daily Cove.
 *
 * The response is the same whatever happened — new address, already subscribed,
 * previously unsubscribed — so the form cannot be used to find out whether
 * someone reads this site. That is decided server-side; this component simply
 * shows whatever it is told.
 *
 * `source` records where the signup came from, which is the only way to learn
 * whether the front-page placement is worth its space.
 */
export default function CoveSubscribe({ source = 'daily' }: { source?: string }) {
    const { t } = useTranslations()
    const { market, flash } = usePage<SharedProps>().props

    const form = useForm({ email: '', source })

    return (
        <section className="rounded-card border border-line bg-card p-6" aria-labelledby="cove-subscribe">
            <h2 id="cove-subscribe" className="text-xl font-semibold tracking-tight">
                {t('cove.subscribe_heading')}
            </h2>
            <p className="mt-2 max-w-xl text-sm text-ink-soft">{t('cove.subscribe_intro')}</p>

            {flash?.status ? (
                // Replaces the form rather than sitting above it: leaving the
                // form visible invites a second submission, which is a second
                // confirmation email to someone who already has one.
                <p className="mt-4 rounded border border-sage/40 bg-sage/10 p-3 text-sm" role="status">
                    {flash.status}
                </p>
            ) : (
                <form
                    onSubmit={(e) => {
                        e.preventDefault()
                        form.post(`/${market.key}/coves/subscribe`, { preserveScroll: true })
                    }}
                    className="mt-4 flex max-w-md flex-wrap gap-2"
                >
                    <label className="sr-only" htmlFor="cove-email">
                        {t('cove.subscribe_placeholder')}
                    </label>
                    <input
                        id="cove-email"
                        type="email"
                        required
                        autoComplete="email"
                        value={form.data.email}
                        onChange={(e) => form.setData('email', e.target.value)}
                        placeholder={t('cove.subscribe_placeholder')}
                        aria-invalid={form.errors.email ? true : undefined}
                        aria-describedby={form.errors.email ? 'cove-email-error' : undefined}
                        className="min-w-0 flex-1 rounded-lg border border-line bg-cream px-4 py-2.5"
                    />
                    <button
                        disabled={form.processing}
                        className="rounded-lg bg-accent px-5 py-2.5 font-medium text-white transition hover:bg-accent-dark disabled:opacity-60"
                    >
                        {t('cove.subscribe_button')}
                    </button>

                    {form.errors.email && (
                        <p id="cove-email-error" className="w-full text-sm text-accent">
                            {form.errors.email}
                        </p>
                    )}

                    <p className="w-full text-xs text-ink-soft">{t('cove.subscribe_privacy')}</p>
                </form>
            )}
        </section>
    )
}
