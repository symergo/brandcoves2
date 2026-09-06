import { useForm, usePage } from '@inertiajs/react'
import type { SharedProps } from '../types'
import { useTranslations } from '../useTranslations'

/**
 * The report form, wherever it is asked for.
 *
 * Lifted out of `Pages/Feedback.tsx` on 2026-09-06 so `/help` could carry the
 * same form rather than a second one. Two forms posting to one endpoint is how
 * a honeypot ends up on one of them and not the other, and how the field that
 * says "only to reply to this" loses that line on the copy nobody re-read.
 *
 * Everything that made the original work travels with it: one required field,
 * a prefilled path, an optional address that says what it is for, and the
 * `website` honeypot that is hidden from people and from screen readers alike.
 */
export default function FeedbackForm({ path }: { path: string | null }) {
    const { t } = useTranslations()
    const { market, auth } = usePage<SharedProps>().props
    const base = `/${market.key}`

    const form = useForm<{
        message: string
        email: string
        path: string
        website: string
    }>({
        message: '',
        // Prefilled for a signed-in reporter: they have already given us this
        // address, and retyping it is friction with no privacy benefit.
        email: auth.user?.email ?? '',
        path: path ?? '',
        website: '',
    })

    function submit(event: React.FormEvent) {
        event.preventDefault()

        form.post(`${base}/feedback`, {
            preserveScroll: true,
            onSuccess: () => form.reset('message', 'website'),
        })
    }

    return (
        <form onSubmit={submit} className="mt-8 space-y-5">
                    <label className="block text-sm font-medium">
                        {/*
                          Named for a screen reader, and only for one. A single
                          textarea directly under the heading needs no visible
                          label — the heading is the label — but an unlabelled
                          field is not a field anybody can fill in without
                          sight.
                        */}
                        <span className="sr-only">{t('feedback.message_label')}</span>
                        <textarea
                            required
                            autoFocus
                            rows={7}
                            minLength={10}
                            maxLength={4000}
                            value={form.data.message}
                            onChange={(e) => form.setData('message', e.target.value)}
                            placeholder={t('feedback.message_placeholder')}
                            className="mt-1 w-full rounded-lg border border-line bg-cream px-3 py-2 font-normal"
                        />
                    </label>
                    {form.errors.message && <p className="text-sm text-accent">{form.errors.message}</p>}

                    <label className="block text-sm font-medium">
                        {t('feedback.path_label')}
                        <input
                            type="text"
                            maxLength={2048}
                            value={form.data.path}
                            onChange={(e) => form.setData('path', e.target.value)}
                            placeholder={t('feedback.path_placeholder')}
                            className="mt-1 w-full rounded-lg border border-line bg-cream px-3 py-2 font-normal"
                        />
                    </label>

                    <label className="block text-sm font-medium">
                        {t('feedback.email_label')}
                        <input
                            type="email"
                            maxLength={254}
                            value={form.data.email}
                            onChange={(e) => form.setData('email', e.target.value)}
                            placeholder={t('feedback.email_placeholder')}
                            className="mt-1 w-full rounded-lg border border-line bg-cream px-3 py-2 font-normal"
                        />
                        <span className="mt-1 block text-xs font-normal text-ink-soft">
                            {t('feedback.email_hint')}
                        </span>
                    </label>
                    {form.errors.email && <p className="text-sm text-accent">{form.errors.email}</p>}

                    {/* The honeypot. See the component docblock. */}
                    <div className="hidden" aria-hidden>
                        <label>
                            Website
                            <input
                                type="text"
                                tabIndex={-1}
                                autoComplete="off"
                                value={form.data.website}
                                onChange={(e) => form.setData('website', e.target.value)}
                            />
                        </label>
                    </div>

                    <button
                        type="submit"
                        disabled={form.processing}
                        className="rounded-lg bg-accent px-5 py-3 font-medium text-white transition hover:bg-accent-dark disabled:opacity-50"
                    >
                        {form.processing ? t('feedback.sending') : t('feedback.submit')}
                    </button>
                </form>
    )
}
