import { Head, Link, router, useForm, usePage } from '@inertiajs/react'
import { useEffect, useState } from 'react'
import AddProduct from '../../Components/AddProduct'
import { OwnItemFooter, SearchField, searchPanel } from '../../Components/ProductSearch'
import SaveToList from '../../Components/SaveToList'
import SignInLink from '../../Components/SignInLink'
import { useSignIn } from '../../signIn'
import { formatPrice, type Cents, type SharedProps } from '../../types'
import { useTranslations } from '../../useTranslations'
import ToolIcon from '../../Components/ToolIcon'

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
        /**
         * Their birthday, day and month — never a year, because no reader uses
         * one. Null until somebody gives it.
         */
        birthdayDay: number | null
        birthdayMonth: number | null
    }
    options: { interests: Option[]; vibes: Option[]; values: string[] }
    canClaim: boolean
    /** You are the giver, looking at the link you are about to send. */
    isGiver: boolean
    /** Signed out: saying "this is me" is what needs an account. */
    canSignInToClaim: boolean
    items: Item[]
    listId: string | null
    listTitle: string | null
    /** The server's answer to "may this visitor add to that list": signed in, and theirs. */
    canAdd: boolean
    /**
     * Signed in, and no list here yet: the page asks for one as it opens. The
     * GET no longer makes it, so a preview or a crawler cannot.
     */
    startsList?: boolean
    suggestions?: Suggestion[]
    suggestTerm?: string
}

/**
 * The add panel's search, for a visitor who cannot add yet: not signed in.
 *
 * The same field and footer as the panel (`ProductSearch`), so the page looks
 * the same either way; pressing either opens the sign-in dialog. Adding needs
 * an account, like every other list, and `/list-search` behind the field is
 * signed-in only. What they typed is not carried across the sign-in: the
 * replay the site has (`PendingSave`) is for a chosen product, and a search
 * term is not one. A suggestion's save button below does carry its product.
 */
function SignInToAdd() {
    const { t } = useTranslations()
    const signIn = useSignIn()
    const [term, setTerm] = useState('')
    const ask = () => signIn.open(t('recipients.add_sign_in'))

    return (
        <div className={searchPanel}>
            <SearchField value={term} onChange={setTerm} onSearch={ask} />
            <OwnItemFooter onClick={ask} />
        </div>
    )
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
    listTitle,
    canAdd,
    startsList = false,
    suggestions = [],
    suggestTerm = '',
}: Props) {
    const page = usePage<SharedProps>()
    const { market, auth } = page.props
    const { t } = useTranslations()
    /*
     * The token is the segment after `for`, not the last one: this page is also
     * served at `/for/{token}/suggest`, where the last segment is "suggest".
     * Taking the last one (until 2026-09-26) sent every claim, save and second
     * search from the suggestions page to `/for/suggest/...`, which is a 404.
     * `page.url` rather than `window` because `window` is absent on the server.
     */
    const segments = page.url.split('?')[0].split('/').filter(Boolean)
    const token = segments[segments.indexOf('for') + 1]
    const base = `/${market.key}/for/${token}`

    /*
     * Describing yourself needs only the link; keeping products needs an
     * account, like every other list. "This is me" above is the short path —
     * it signs them in and binds this person to the account in one go.
     *
     * Bumped after each add, which remounts the add panel open and empty for
     * the next thing (it closes itself on a successful add, as on a list page).
     */
    const [panelKey, setPanelKey] = useState(0)

    /*
     * The list is made by a POST, once, when a signed-in visitor opens the
     * page and has none yet; the page comes back with it and the add panel.
     * See RecipientProfileController::startList().
     */
    useEffect(() => {
        if (startsList) {
            router.post(`${base}/list`, {}, { preserveScroll: true, preserveState: true })
        }
    }, [startsList, base])

    const form = useForm({
        interests: person.interests,
        vibe: person.vibe ?? '',
        values: person.values,
        /*
         * Seeded from what is stored, unlike the taste answers above.
         *
         * Those are deliberately blank until this person has spoken, because
         * prefilling them with the giver's guesses reveals what they have been
         * told about. A date carries no such characterisation — it is a fact,
         * and it is theirs whoever typed it.
         */
        birthday_day: person.birthdayDay?.toString() ?? '',
        birthday_month: person.birthdayMonth?.toString() ?? '',
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

            {/* Full width: nothing sits beside it (owner's rule, re-checked 2026-09-27); only the sentence keeps a reading width. */}
            <header>
                <h1 className="text-xl sm:text-2xl font-semibold">{t('recipients.self_title')}</h1>
                <p className="mt-2 max-w-2xl text-ink-soft">
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

            <section className="mt-10">
                <h2 className="text-lg font-medium">{t('recipients.about_you')}</h2>

                {/*
                  The other way to say it: choose between products a dozen
                  times and let the choices describe you (This or that). What
                  comes out is saved as your own answer, like the form below.
                */}
                <div className="mt-3">
                    <Link
                        href={`${base}/taste`}
                        className="inline-flex items-center gap-2 rounded-lg border border-line bg-card px-4 py-2 text-sm hover:border-ink"
                    >
                        <ToolIcon name="taste" className="h-4 w-4 text-accent" />
                        {t('recipients.taste_link')}
                    </Link>
                    <p className="mt-1 text-xs text-ink-soft">{t('recipients.taste_link_hint')}</p>
                </div>

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

                    {/*
                      Their birthday, from the one person who definitely knows it.

                      The giver is guessing — most people cannot name a friend's
                      date, which is why `recipients.birthday` sat empty on
                      almost every row while the reminder job that reads it was
                      already running. This page is the one place the answer is
                      free.

                      Day and month, no year: every reader matches on month and
                      day because a birthday recurs, and a year is personal data
                      with no use here. The hint says so — being asked for a
                      birthday and not for a year is unusual enough to explain,
                      and on a page filled in by somebody who was sent a link by
                      a friend, "why do you want this" deserves an answer before
                      it is asked.
                    */}
                    <fieldset>
                        <legend className="text-sm font-medium">
                            {t('recipients.step_birthday')}
                        </legend>
                        <p className="mt-1 text-xs text-ink-soft">
                            {t('recipients.birthday_why')}
                        </p>

                        <div className="mt-2 flex gap-2">
                            <select
                                aria-label={t('lists.birthday_day')}
                                value={form.data.birthday_day}
                                onChange={(e) => form.setData('birthday_day', e.target.value)}
                                className="rounded-lg border border-line bg-cream px-3 py-2 text-sm"
                            >
                                <option value="">{t('lists.birthday_day')}</option>
                                {Array.from({ length: 31 }, (_, i) => i + 1).map((day) => (
                                    <option key={day} value={day}>
                                        {day}
                                    </option>
                                ))}
                            </select>

                            <select
                                aria-label={t('lists.birthday_month')}
                                value={form.data.birthday_month}
                                onChange={(e) => form.setData('birthday_month', e.target.value)}
                                className="rounded-lg border border-line bg-cream px-3 py-2 text-sm"
                            >
                                <option value="">{t('lists.birthday_month')}</option>
                                {Array.from({ length: 12 }, (_, i) => i + 1).map((month) => (
                                    <option key={month} value={month}>
                                        {/* The month's name in their own market:
                                            "3" is March here and nowhere else
                                            reliably. */}
                                        {new Date(2000, month - 1, 1).toLocaleDateString(
                                            market.hrefLang,
                                            { month: 'long' },
                                        )}
                                    </option>
                                ))}
                            </select>
                        </div>
                    </fieldset>

                    <button
                        type="submit"
                        disabled={form.processing}
                        className="rounded-lg bg-accent px-4 py-2 text-sm font-medium text-white disabled:opacity-50"
                    >
                        {t('lists.save')}
                    </button>
                </form>
            </section>

            <section className="mt-12">
                <h2 className="text-lg font-medium">{t('recipients.your_list')}</h2>
                {/*
                  The list page's own add panel (owner, 2026-09-27: "the inline
                  search under 'Dingen die je leuk zou vinden' should be the
                  same as the one on the list pages"): one field for words, a
                  barcode or a pasted link, catalogue results and shops we do
                  not mirror, the wording step before it lands, and something
                  typed by hand with a photo. It posts to `/list-items` like
                  the list page; `canAdd` is the server asking the same
                  questions that route asks.

                  Open from the start, like an empty list's, but without taking
                  the cursor: it is the third section of the page, and arriving
                  must not scroll down to it or raise a phone's keyboard.
                */}
                <div className="mt-4">
                    {canAdd && listId !== null ? (
                        <AddProduct
                            // A fresh panel after each add, so it stays open for the next one.
                            key={panelKey}
                            base={`/${market.key}`}
                            listId={listId}
                            market={market}
                            defaultOpen
                            autoFocus={false}
                            onClose={() => setPanelKey((k) => k + 1)}
                            // `/for/{token}/suggest?q=` is a real address; it opens with that search run.
                            initialTerm={panelKey === 0 ? suggestTerm : ''}
                        />
                    ) : (
                        !auth.user && <SignInToAdd />
                    )}
                </div>

                {/*
                  The other way in, for somebody who does not know yet what to
                  type: ideas ranked for the person themselves.
                */}
                <button
                    type="button"
                    onClick={() => router.get(`${base}/suggest`, {}, { preserveState: true, preserveScroll: true })}
                    className="mt-3 inline-flex items-center gap-2 rounded-lg border border-line px-4 py-2 text-sm hover:border-ink"
                >
                    <ToolIcon name="suggestions" className="h-4 w-4 text-accent" />
                    {t('recipients.suggest')}
                </button>

                {suggestions.length > 0 && (
                    <ul className="mt-6 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                        {suggestions.map((suggestion) => (
                            <li
                                key={suggestion.id}
                                className="flex flex-col rounded-card border border-line bg-card p-4"
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
                                {/*
                                  The site's own save button: onto this list
                                  when the visitor may add to it, and the
                                  sign-in dialog with the product kept for
                                  afterwards (PendingSave) when they may not.
                                  The list under it is redrawn, since it is on
                                  screen and the toast alone would leave it
                                  one item short.
                                */}
                                <div className="mt-auto pt-3">
                                    <SaveToList
                                        groupId={suggestion.id}
                                        title={suggestion.title}
                                        imageUrl={suggestion.image}
                                        price={suggestion.price}
                                        into={canAdd && listId !== null ? { id: listId, title: listTitle ?? '', kind: 'mine' } : undefined}
                                        onSaved={(saved) => saved === listId && router.reload({ only: ['items'] })}
                                    />
                                </div>
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
