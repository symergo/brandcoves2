import { Head, useForm, usePage } from '@inertiajs/react'
import type { FormEvent } from 'react'
import type { SharedProps } from '../../types'
import { useTranslations } from '../../useTranslations'

interface Props {
    googleEnabled: boolean
}

export default function Login({ googleEnabled }: Props) {
    const { market, flash } = usePage<SharedProps>().props
    const { t } = useTranslations()
    const base = `/${market.key}`

    const form = useForm({ email: '', name: '' })

    function submit(e: FormEvent) {
        e.preventDefault()
        form.post(`${base}/login`, { preserveScroll: true })
    }

    return (
        <>
            <Head title={t('auth.title')} />

            <div className="mx-auto max-w-md">
                <h1 className="text-2xl font-semibold">{t('auth.title')}</h1>
                <p className="mt-2 text-ink-soft">{t('auth.intro')}</p>

                {/*
                  Success is announced politely rather than replacing the form:
                  the most common next action is "it did not arrive, send
                  another", and hiding the form makes that harder.
                */}
                {flash.success && (
                    <p
                        className="mt-6 rounded-card border border-sage/30 bg-sage/10 p-4 text-sm"
                        role="status"
                    >
                        {flash.success}
                    </p>
                )}

                <form onSubmit={submit} className="mt-6 space-y-3">
                    {/*
                      Optional, and only used when the account is created.
                      A magic link is the whole of registration here, so this is
                      the one moment there is to ask — and without a name a
                      shared wishlist cannot say whose it is.
                    */}
                    <label htmlFor="name" className="block text-sm font-medium">
                        {t('auth.name')}
                    </label>
                    <input
                        id="name"
                        type="text"
                        autoComplete="name"
                        maxLength={80}
                        value={form.data.name}
                        onChange={(e) => form.setData('name', e.target.value)}
                        className="mt-1 mb-4 w-full rounded-lg border border-line px-3 py-2"
                    />

                    <label htmlFor="email" className="block text-sm font-medium">
                        {t('auth.email')}
                    </label>
                    <input
                        id="email"
                        type="email"
                        inputMode="email"
                        autoComplete="email"
                        required
                        value={form.data.email}
                        onChange={(e) => form.setData('email', e.target.value)}
                        aria-invalid={form.errors.email ? true : undefined}
                        aria-describedby={form.errors.email ? 'email-error' : undefined}
                        className="w-full rounded-lg border border-line bg-card px-4 py-3"
                    />

                    {form.errors.email && (
                        <p id="email-error" className="text-sm text-accent" role="alert">
                            {form.errors.email}
                        </p>
                    )}

                    <button
                        type="submit"
                        disabled={form.processing}
                        className="w-full rounded-lg bg-accent px-5 py-3 font-medium text-white transition hover:bg-accent-dark disabled:opacity-60"
                    >
                        {t('auth.send')}
                    </button>
                </form>

                {/* Hidden entirely when unconfigured — a button that leads to an
                    exception is worse than no button. */}
                {googleEnabled && (
                    <>
                        <div className="my-6 flex items-center gap-3 text-xs text-ink-soft">
                            <span className="h-px flex-1 bg-line" />
                            {t('auth.or')}
                            <span className="h-px flex-1 bg-line" />
                        </div>

                        <a
                            href={`${base}/auth/google`}
                            className="flex w-full items-center justify-center gap-2 rounded-lg border border-line px-5 py-3 font-medium transition hover:border-ink"
                        >
                            {t('auth.google')}
                        </a>
                    </>
                )}
            </div>
        </>
    )
}
