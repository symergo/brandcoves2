import { Head, useForm, usePage } from '@inertiajs/react'
import type { SharedProps } from '../types'
import { useTranslations } from '../useTranslations'

interface Props {
    /** The page they came from, prefilled and editable. Null if we could not tell. */
    path: string | null
}

/**
 * Tell us what is wrong.
 *
 * ## One required field
 *
 * The message. Everything else — the address, the page — is optional, and the
 * page is prefilled. A report form that opens on five required fields collects
 * reports from people who were already determined to file one, which is not the
 * population worth hearing from: the useful reports come from someone mildly
 * annoyed, thirty seconds before they give up and leave.
 *
 * ## The address says what it is for
 *
 * "Only to reply to this" is written next to the field rather than buried in
 * the privacy policy, because that is the question being asked at the moment the
 * cursor is in the box. It is optional and the form works without it.
 *
 * ## The honeypot
 *
 * `website` is hidden from people and from screen readers — `aria-hidden` plus
 * `tabIndex={-1}` — so anything in it was typed by something filling every input
 * on the page. It is not `display:none` alone: a field with no `autoComplete`
 * off can still be filled by a browser's own autofill, so it is named something
 * no autofill heuristic recognises as a real field of this form.
 */
export default function Feedback({ path }: Props) {
    const { t } = useTranslations()
    const { flash, market, auth } = usePage<SharedProps>().props
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
        <>
            <Head title={t('feedback.title')} />

            <div className="mx-auto max-w-2xl px-4 py-10">
                <h1 className="text-3xl font-semibold tracking-tight">{t('feedback.title')}</h1>
                <p className="mt-3 text-ink-soft">{t('feedback.intro')}</p>

                {/*
                  The confirmation replaces nothing and hides nothing — the form
                  stays open below it. Somebody who has just reported one wrong
                  price often has a second one, and a form that collapses into a
                  thank-you makes them navigate back to it.
                */}
                {flash.status && (
                    <p className="mt-6 rounded-card border border-line bg-card p-4 font-medium">
                        {flash.status}
                    </p>
                )}

                <form onSubmit={submit} className="mt-8 space-y-5">
                    <label className="block text-sm font-medium">
                        {t('feedback.message_label')}
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
                        <span className="mt-1 block text-xs font-normal text-ink-soft">
                            {t('feedback.path_hint')}
                        </span>
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
            </div>
        </>
    )
}
