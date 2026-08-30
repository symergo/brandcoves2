import { Head, router, useForm, usePage } from '@inertiajs/react'
import { useState } from 'react'
import SignInLink from '../../Components/SignInLink'
import { formatPrice, type Cents, type SharedProps } from '../../types'
import { useTranslations } from '../../useTranslations'
import ScanButton from '../../Components/ScanButton'

interface Option {
    value: string
    label: string
}

interface Item {
    id: number
    title: string
    image: string | null
    price: Cents | null
    note: string | null
    live: boolean
    /*
     * No `claimed`, no `claimedByMe`, no `sent`. Their absence is the feature:
     * the person reading this page is exactly the person the surprise is being
     * kept from. See RecipientProfileController.
     */
}

interface Suggestion {
    id: number
    title: string
    image: string | null
    price: Cents | null
    reason: string | null
}

interface Props {
    person: {
        name: string
        interests: string[]
        vibe: string | null
        values: string[]
        hasSpoken: boolean
        isLinked: boolean
    }
    options: { interests: Option[]; vibes: Option[]; values: string[] }
    canClaim: boolean
    /** You are the giver, looking at the link you are about to send. */
    isGiver: boolean
    /** Signed out: saying "this is me" is what needs an account. */
    canSignInToClaim: boolean
    items: Item[]
    listId: string | null
    suggestions?: Suggestion[]
}

/**
 * The other end of a recipient.
 *
 * Two jobs, and the second is the one that matters: say who you are, and put
 * actual things on a list. "She likes cooking" moves the engine; "she wants
 * this pan" ends the conversation.
 */
export default function SelfDescribe({
    person,
    options,
    canClaim,
    isGiver,
    canSignInToClaim,
    items,
    listId,
    suggestions = [],
}: Props) {
    const page = usePage<SharedProps>()
    const { market, auth } = page.props
    const { t } = useTranslations()
    // See the note in Lists/Shared: `window` is absent on the server.
    const token = page.url.split('?')[0].split('/').filter(Boolean).pop()
    const base = `/${market.key}/for/${token}`

    // The sign-in dialog, so saying "this is me" does not cost the page.

    const [query, setQuery] = useState('')

    /*
     * Describing yourself needs only the link; keeping products needs an
     * account, like every other list. "This is me" above is the short path —
     * it signs them in and binds this person to the account in one go.
     */
    const canSave = Boolean(auth.user)

    const form = useForm({
        interests: person.interests,
        vibe: person.vibe ?? '',
        values: person.values,
    })

    const toggle = (list: string[], key: 'interests' | 'values', value: string) =>
        form.setData(
            key,
            list.includes(value) ? list.filter((v) => v !== value) : [...list, value],
        )

    return (
        <>
            {/* A capability URL, not a public page. Never indexed. */}
            <Head title={t('recipients.self_title')}>
                <meta name="robots" content="noindex, nofollow" />
            </Head>

            <header className="max-w-2xl">
                <h1 className="text-xl sm:text-2xl font-semibold">{t('recipients.self_title')}</h1>
                <p className="mt-2 text-ink-soft">
                    {t('recipients.self_intro', { name: person.name })}
                </p>
                {canClaim && (
                    <div className="mt-4 rounded-card border border-accent/40 bg-accent/5 p-4">
                        <p className="text-sm">{t('recipients.claim_hint')}</p>
                        <button
                            type="button"
                            onClick={() => router.post(`${base}/claim`, {}, { preserveScroll: true })}
                            className="mt-3 rounded-lg bg-accent px-4 py-2 text-sm font-medium text-white"
                        >
                            {t('recipients.claim_this_is_me')}
                        </button>
                    </div>
                )}

                {/*
                  You are the person who made this list.

                  The button used to be offered here and answered 403 when
                  pressed — the endpoint has always refused it, because claiming
                  your own stub would make you the recipient of your own gift
                  research. The likeliest visitor to this page is the giver
                  checking what they are about to send, so this says which side
                  of the link they are on rather than rendering nothing and
                  leaving them to wonder whether the page is broken.
                */}
                {isGiver && (
                    <p className="mt-4 rounded-card border border-line bg-card p-4 text-sm text-ink-soft">
                        {t('recipients.claim_is_you')}
                    </p>
                )}

                {/*
                  Signed out. Describing yourself needs no account — the token
                  is the credential — but saying "this is me" attaches the
                  person to an account, so it needs one. That makes this the
                  short path to having one rather than a refusal.
                */}
                {canSignInToClaim && (
                    <div className="mt-4 rounded-card border border-line bg-card p-4">
                        <p className="text-sm text-ink-soft">{t('recipients.claim_sign_in')}</p>
                        {/*
                          A dialog, not a link to the login page.

                          Somebody is here because a friend sent them a link and
                          they were part-way through describing themselves.
                          Navigating away to sign in throws that away — the form
                          they had started, and the page they meant to come back
                          to. The dialog keeps both.

                          This page argued that first and kept its own copy of
                          the dialog; the layout now mounts one for the whole
                          site, so this is the same behaviour with the state
                          somewhere it can be shared. See resources/js/signIn.tsx.
                        */}
                        <SignInLink
                            hint={t('recipients.claim_sign_in')}
                            className="mt-3 inline-block rounded-lg border border-line px-4 py-2 text-sm hover:border-ink"
                        >
                            {t('nav.sign_in')}
                        </SignInLink>
                    </div>
                )}
            </header>

            <section className="mt-10 max-w-2xl">
                <h2 className="text-lg font-medium">{t('recipients.about_you')}</h2>

                <form
                    className="mt-4 space-y-6"
                    onSubmit={(e) => {
                        e.preventDefault()
                        form.post(base, { preserveScroll: true })
                    }}
                >
                    <fieldset>
                        <legend className="text-sm font-medium">{t('recipients.step_interests')}</legend>
                        <div className="mt-2 flex flex-wrap gap-2">
                            {options.interests.map((option) => (
                                <button
                                    key={option.value}
                                    type="button"
                                    aria-pressed={form.data.interests.includes(option.value)}
                                    onClick={() => toggle(form.data.interests, 'interests', option.value)}
                                    className={`rounded-full border px-3 py-1.5 text-sm ${
                                        form.data.interests.includes(option.value)
                                            ? 'border-accent bg-accent text-white'
                                            : 'border-line hover:bg-card'
                                    }`}
                                >
                                    {option.label}
                                </button>
                            ))}
                        </div>
                    </fieldset>

                    <fieldset>
                        <legend className="text-sm font-medium">{t('recipients.step_vibe')}</legend>
                        <div className="mt-2 flex flex-wrap gap-2">
                            {options.vibes.map((option) => (
                                <button
                                    key={option.value}
                                    type="button"
                                    aria-pressed={form.data.vibe === option.value}
                                    onClick={() =>
                                        form.setData(
                                            'vibe',
                                            form.data.vibe === option.value ? '' : option.value,
                                        )
                                    }
                                    className={`rounded-full border px-3 py-1.5 text-sm ${
                                        form.data.vibe === option.value
                                            ? 'border-accent bg-accent text-white'
                                            : 'border-line hover:bg-card'
                                    }`}
                                >
                                    {option.label}
                                </button>
                            ))}
                        </div>
                    </fieldset>

                    <fieldset>
                        <legend className="text-sm font-medium">{t('recipients.step_values')}</legend>
                        <div className="mt-2 flex flex-wrap gap-2">
                            {options.values.map((value) => (
                                <button
                                    key={value}
                                    type="button"
                                    aria-pressed={form.data.values.includes(value)}
                                    onClick={() => toggle(form.data.values, 'values', value)}
                                    className={`rounded-full border px-3 py-1.5 text-sm ${
                                        form.data.values.includes(value)
                                            ? 'border-accent bg-accent text-white'
                                            : 'border-line hover:bg-card'
                                    }`}
                                >
                                    {t(`gift.values.${value}`)}
                                </button>
                            ))}
                        </div>
                    </fieldset>

                    <button
                        type="submit"
                        disabled={form.processing}
                        className="rounded-lg bg-accent px-4 py-2 text-sm font-medium text-white disabled:opacity-60"
                    >
                        {t('lists.save')}
                    </button>
                </form>
            </section>

            <section className="mt-12">
                <h2 className="text-lg font-medium">{t('recipients.your_list')}</h2>

                {/*
                  Two ways in, side by side. Typing assumes you already know what
                  you want, which is exactly what somebody staring at an empty
                  list does not.
                */}
                <div className="mt-4 flex flex-wrap gap-3">
                    <form
                        className="flex flex-1 gap-2"
                        onSubmit={(e) => {
                            e.preventDefault()
                            router.get(`${base}/suggest`, { q: query }, { preserveState: true })
                        }}
                    >
                        <input
                            value={query}
                            onChange={(e) => setQuery(e.target.value)}
                            placeholder={t('recipients.search_placeholder')}
                            className="min-w-0 flex-1 rounded-lg border border-line px-3 py-2 text-sm"
                        />
                        {/*
                          The third way in, beside typing and "show me ideas":
                          the thing you already own and would like another of,
                          or the one you photographed in a shop window.
                        */}
                        <ScanButton
                            className="shrink-0 rounded-lg border border-line px-3 py-2"
                            onScan={(gtin) => {
                                setQuery(gtin)
                                router.get(`${base}/suggest`, { q: gtin }, { preserveState: true })
                            }}
                        />
                        <button
                            type="submit"
                            className="rounded-lg border border-line px-4 py-2 text-sm"
                        >
                            {t('recipients.add_something')}
                        </button>
                    </form>

                    <button
                        type="button"
                        onClick={() => router.get(`${base}/suggest`, {}, { preserveState: true })}
                        className="rounded-lg border border-line px-4 py-2 text-sm"
                    >
                        {t('recipients.suggest')}
                    </button>
                </div>

                {suggestions.length > 0 && (
                    <ul className="mt-6 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                        {suggestions.map((suggestion) => (
                            <li
                                key={suggestion.id}
                                className="rounded-card border border-line bg-card p-4"
                            >
                                {suggestion.image && (
                                    <img
                                        src={suggestion.image}
                                        alt=""
                                        loading="lazy"
                                        className="mx-auto h-32 w-auto max-w-full object-contain"
                                    />
                                )}
                                <p className="mt-3 text-sm font-medium">{suggestion.title}</p>
                                {suggestion.price !== null && (
                                    <p className="mt-1 text-sm text-ink-soft">
                                        {formatPrice(suggestion.price, market)}
                                    </p>
                                )}
                                <button
                                    type="button"
                                    onClick={() =>
                                        canSave
                                            ? router.post(
                                                  `/${market.key}/list-items`,
                                                  { group_id: suggestion.id, wishlist_id: listId },
                                                  { preserveScroll: true },
                                              )
                                            : router.get(`/${market.key}/login`)
                                    }
                                    className="mt-3 w-full rounded-lg border border-line px-3 py-1.5 text-sm"
                                >
                                    {canSave ? t('lists.save') : t('nav.sign_in')}
                                </button>
                            </li>
                        ))}
                    </ul>
                )}

                {items.length === 0 ? (
                    <p className="mt-6 rounded-card border border-line bg-card p-8 text-center text-ink-soft">
                        {t('recipients.nothing_yet')}
                    </p>
                ) : (
                    <ul className="mt-6 divide-y divide-line overflow-hidden rounded-card border border-line bg-card">
                        {items.map((item) => (
                            <li key={item.id} className="flex items-center gap-4 p-4">
                                {item.image && (
                                    <img
                                        src={item.image}
                                        alt=""
                                        loading="lazy"
                                        className="h-14 w-14 shrink-0 object-contain"
                                    />
                                )}
                                <div className="min-w-0 flex-1">
                                    <p className="truncate text-sm font-medium">{item.title}</p>
                                    {item.price !== null && !item.live && (
                                        <p className="text-sm text-ink-soft">
                                            {formatPrice(item.price, market)}
                                        </p>
                                    )}
                                </div>
                                <button
                                    type="button"
                                    onClick={() =>
                                        router.delete(`/${market.key}/list-items/${item.id}`, {
                                            preserveScroll: true,
                                        })
                                    }
                                    className="text-sm text-ink-soft hover:text-ink"
                                >
                                    {t('lists.remove')}
                                </button>
                            </li>
                        ))}
                    </ul>
                )}
            </section>
        </>
    )
}
