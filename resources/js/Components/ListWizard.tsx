import { useForm, usePage } from '@inertiajs/react'
import { useEffect, useRef, useState } from 'react'
import type { SharedProps } from '../types'
import { useTranslations } from '../useTranslations'
import InfoTip from './InfoTip'
import SignInLink from './SignInLink'
import PersonPicker, { type PickablePerson } from './PersonPicker'

/**
 * The answers to "who is it for?". Three are list kinds; `santa` is a Secret
 * Friend group, which is not a list at all but is a thing somebody pressing
 * "make a new list" may have meant (owner's call, 2026-09-12). It sits under
 * the three as a quieter link, and posts to the group endpoint instead.
 */
type Kind = 'mine' | 'for_someone' | 'group' | 'santa'

/** Somebody a list can be for: a friend, or a person I made a profile for. */
interface Person {
    name: string
    /** When their birthday next falls, resolved by the server; null when unknown. */
    birthday: string | null
    /** "Mama", for the card; null when nothing was saved. */
    relationship?: string | null
    /** The birthday as the card's date line, with how many days away. */
    next?: PickablePerson['next']
}

interface Friend extends Person {
    id: number
    /**
     * The profile this friend already has with me, if any. Picking them then
     * means picking that profile rather than minting a second one.
     */
    recipientId: string | null
}

/** What the server offers the screen; see App\Services\Wishlist\WizardOffer. */
export interface WizardOffer {
    recipients: (Person & { id: string })[]
    friends: Friend[]
    /**
     * Still sent, no longer asked here: the occasion moved to the list page
     * with the one-step create. Kept on the props so both pages that build
     * this offer stay one shape.
     */
    occasions: { value: string; label: string; date: string | null }[]
    /** My own lists, about myself: what a Secret Friend group may be pointed at. */
    myLists: { id: string; title: string }[]
}

interface Props extends WizardOffer {
    signedIn: boolean
    /**
     * A kind already chosen by the link that opened the page (`?new=<kind>`,
     * from the home page, the Gift Cove's cards and emails). It is preselected;
     * the other choices stay one tap away.
     */
    initialKind?: Kind
    /** Offered where the screen was opened by a button and can be put away again. */
    onCancel?: () => void
}

/**
 * What the screen remembers between a sign-in and the return.
 *
 * A visitor without an account answers the question signed out and signs in
 * at the button. The sign-in leaves the page, and coming back to an empty form
 * throws the answer away at the moment it was about to be used. Local storage
 * rather than session: a magic link is opened from the mail, in a new tab, and
 * a new tab has no session storage. A day is the limit.
 */
const DRAFT = 'bc.list-wizard'
const DRAFT_TTL = 24 * 60 * 60 * 1000

/**
 * Is there a draft waiting to be replayed? My Lists asks on arrival, so a
 * sign-in that lands there still finishes the list it was started for.
 */
export function hasListDraft(): boolean {
    try {
        const raw = localStorage.getItem(DRAFT)

        if (!raw) {
            return false
        }

        return Date.now() - (JSON.parse(raw) as { at: number }).at <= DRAFT_TTL
    } catch {
        return false
    }
}

/**
 * A list, made in one step (2026-09-26; docs/features/one-step-list.md).
 *
 * This was three steps: who, then name and occasion, then sharing. The audit
 * counted ten presses between "make a list for my sister" and the first thing
 * on it, and most of them were questions nobody needs answered before the list
 * exists. Now there is one question, "who is it for?", because that is the one
 * that decides the kind and cannot be changed later. The name is filled in and
 * may be changed or left. Occasion, sharing and asking for ideas were always
 * settable on the list page; that is where they are now, offered once by a
 * light prompt when the list opens (see NewListPrompt).
 *
 * The list page then opens with the add field focused, so the next thing to do
 * is paste or search.
 */
export default function ListWizard({ signedIn, recipients, friends, myLists, initialKind, onCancel }: Props) {
    const { market } = usePage<SharedProps>().props
    const { t } = useTranslations()
    const base = `/${market.key}`

    const [kind, setKind] = useState<Kind>(initialKind ?? 'mine')
    const [replay, setReplay] = useState(false)
    const [titleTouched, setTitleTouched] = useState(false)
    const nameField = useRef<HTMLInputElement>(null)

    const form = useForm({
        title: '',
        recipient_id: '',
        new_recipient: '',
        friend_id: '' as string | number,
        together: initialKind === 'group',
        // The Secret Friend group's own fields. `title` is its name there.
        budget_max: '',
        exchange_date: '',
        theme: '',
        wishlist_id: '',
    })

    /*
     * Restore a draft, and if it was written by somebody who has since signed
     * in, make the list: the button they pressed was "sign in and make it".
     */
    useEffect(() => {
        try {
            const raw = localStorage.getItem(DRAFT)

            if (!raw) {
                return
            }

            const draft = JSON.parse(raw) as { at: number; kind: Kind; data: typeof form.data }

            if (Date.now() - draft.at > DRAFT_TTL) {
                localStorage.removeItem(DRAFT)

                return
            }

            setKind(draft.kind)
            form.setData(draft.data)
            // The name in the draft is the one they left with; the auto-fill
            // must not write over it on the way back in.
            setTitleTouched(true)

            // Deferred one render: the data set above is not readable until then.
            if (signedIn) {
                setReplay(true)
            }
        } catch {
            // A private window or cleared storage: start fresh.
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [])

    function remember() {
        try {
            localStorage.setItem(DRAFT, JSON.stringify({ at: Date.now(), kind, data: form.data }))
        } catch {
            // The draft simply does not survive the sign-in.
        }
    }

    function forget() {
        try {
            localStorage.removeItem(DRAFT)
        } catch {
            // Same as above.
        }
    }

    const isSanta = kind === 'santa'
    // A list about somebody: a name is the one thing it cannot be made without.
    const forSomeone = kind === 'for_someone' || kind === 'group'

    function choose(next: Kind) {
        // A group's name is not a list's name: crossing between the two
        // starts the name over rather than carrying one into the other.
        if ((next === 'santa') !== (kind === 'santa')) {
            setTitleTouched(false)
            form.setData('title', '')
        }

        setKind(next)
        // Only a group list pools money; the server derives the kind from the
        // recipient and this bit, so the form just keeps them consistent.
        form.setData('together', next === 'group')

        if (next === 'mine' || next === 'santa') {
            form.setData((data) => ({ ...data, recipient_id: '', new_recipient: '', friend_id: '' }))
        }
    }

    // Choosing a person-shaped kind puts the cursor where the answer goes. On
    // mount only when the link already chose it (`?new=for_someone`): then
    // the name is the one thing left to type.
    const mounted = useRef(false)

    useEffect(() => {
        if (!mounted.current) {
            mounted.current = true

            if (!initialKind || initialKind === 'mine') return
        }

        if (forSomeone && form.data.recipient_id === '' && form.data.friend_id === '') {
            nameField.current?.focus()
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [kind])

    /* The person this list is about, whichever way they were named. */
    const person: Person | null = (() => {
        if (form.data.friend_id !== '') {
            return friends.find((f) => f.id === Number(form.data.friend_id)) ?? null
        }

        if (form.data.recipient_id !== '') {
            return (
                friends.find((f) => f.recipientId === form.data.recipient_id)
                ?? recipients.find((r) => r.id === form.data.recipient_id)
                ?? null
            )
        }

        return null
    })()

    const personName = (person?.name ?? form.data.new_recipient).trim()

    /*
     * The name follows the choice until somebody types one: "Wish list",
     * "Gifts for Sara", "Together for Sara". The server writes the same words
     * when the field arrives empty (ListMaker::defaultTitle), so clearing it
     * is allowed and changes nothing.
     */
    function suggestedTitle(): string {
        if (kind === 'mine') return t('wizard.default_mine')
        if (kind === 'for_someone' && personName !== '') return t('wizard.default_for_someone', { name: personName })
        if (kind === 'group' && personName !== '') return t('wizard.default_group', { name: personName })

        return ''
    }

    const suggestion = suggestedTitle()

    useEffect(() => {
        if (titleTouched) return

        /*
         * Only a real change is written. This runs on mount too, in the same
         * pass as the draft restore above, and an unconditional write there
         * would wipe the title out of every replayed draft.
         */
        if (suggestion !== form.data.title) {
            form.setData('title', suggestion)
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [suggestion, titleTouched])

    function submit() {
        if (isSanta) {
            form.transform((data) => ({
                title: data.title,
                budget_max: data.budget_max || null,
                exchange_date: data.exchange_date || null,
                theme: data.theme || null,
                wishlist_id: data.wishlist_id || null,
            }))

            form.post(`${base}/santa`, { onSuccess: forget })

            return
        }

        form.transform((data) => ({
            title: data.title.trim() || null,
            recipient_id: forSomeone ? data.recipient_id || null : null,
            new_recipient: forSomeone && data.recipient_id === '' && data.friend_id === '' ? data.new_recipient : null,
            friend_id: forSomeone && data.friend_id !== '' ? data.friend_id : null,
            together: kind === 'group',
        }))

        form.post(`${base}/lists`, { onSuccess: forget })
    }

    useEffect(() => {
        if (!replay) return

        setReplay(false)
        submit()
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [replay])

    /*
     * The people I already have, as one-tap chips: my profiles first, then
     * friends who have none yet (a friend with a profile is that profile, so
     * one person is offered once).
     */
    /*
     * As cards since the consistency review's round 3 (2026-09-27): the same
     * `PersonPicker` Find a gift draws, compact because it sits in a form.
     * A friend is marked "op GiftCoves"; a friend with a profile is picked as
     * that profile, one without by their account (`friend_id`).
     */
    const people: PickablePerson[] = [
        ...recipients.map((r) => ({
            key: `p:${r.id}`,
            name: r.name,
            relationship: r.relationship ?? null,
            personId: r.id,
            friend: null,
            next: r.next ?? null,
        })),
        ...friends
            .filter((f) => f.recipientId === null || !recipients.some((r) => r.id === f.recipientId))
            .map((f) => ({
                key: `f:${f.id}`,
                name: f.name,
                relationship: f.relationship ?? null,
                personId: f.recipientId,
                friend: { id: f.id },
                next: f.next ?? null,
            })),
    ]

    const isPicked = (p: PickablePerson) =>
        p.personId !== null ? form.data.recipient_id === p.personId : p.friend !== null && form.data.friend_id === p.friend.id

    const pick = (p: PickablePerson) =>
        form.setData((data) =>
            p.personId !== null
                ? { ...data, recipient_id: p.personId, friend_id: '', new_recipient: '' }
                : { ...data, friend_id: p.friend?.id ?? '', recipient_id: '', new_recipient: '' },
        )

    const choices: { value: Kind; label: string; kindLabel: string; body: string }[] = [
        { value: 'mine', label: t('lists.for_me'), kindLabel: t('lists.kind_mine'), body: t('wizard.kind_mine_body') },
        { value: 'for_someone', label: t('lists.for_someone_else'), kindLabel: t('lists.kind_for_someone'), body: t('wizard.kind_for_someone_body') },
        { value: 'group', label: t('lists.for_group'), kindLabel: t('lists.kind_group'), body: t('wizard.kind_group_body') },
    ]

    const ready = isSanta ? form.data.title.trim() !== '' : !forSomeone || personName !== ''

    const button = signedIn ? (
        <button
            type="submit"
            disabled={!ready || form.processing}
            className="rounded-lg bg-accent px-5 py-2.5 font-medium text-white hover:bg-accent-dark disabled:opacity-50"
        >
            {t(isSanta ? 'santa.create' : 'lists.create')}
        </button>
    ) : ready ? (
        <SignInLink
            hint={t('wizard.sign_in_hint')}
            onNavigate={remember}
            className="rounded-lg bg-accent px-5 py-2.5 font-medium text-white hover:bg-accent-dark"
        >
            {t(isSanta ? 'wizard.sign_in_and_create_santa' : 'wizard.sign_in_and_create')}
        </SignInLink>
    ) : (
        <button type="button" disabled className="rounded-lg bg-accent px-5 py-2.5 font-medium text-white opacity-50">
            {t('wizard.sign_in_and_create')}
        </button>
    )

    return (
        <form
            onSubmit={(e) => {
                e.preventDefault()
                // Signed out, the button is the sign-in link; Enter does nothing.
                if (ready && signedIn) submit()
            }}
            className="rounded-card border border-accent/40 bg-accent/5 p-5 sm:p-6"
            aria-labelledby="wizard-title"
        >
            {isSanta ? (
                <>
                    <h2 id="wizard-title" className="text-lg font-medium">{t('wizard.title_santa')}</h2>
                    <p className="mt-1 text-sm text-ink-soft">{t('santa.subtitle')}</p>

                    {/*
                      The group's fields on the same one screen. A group cannot
                      be edited afterwards, so these stay here rather than
                      moving to its page the way a list's settings did.
                    */}
                    <div className="mt-5 grid gap-4 sm:grid-cols-2">
                        <label className="block sm:col-span-2">
                            <span className="text-sm font-medium">{t('santa.group_name')}</span>
                            <input
                                value={form.data.title}
                                onChange={(e) => {
                                    setTitleTouched(true)
                                    form.setData('title', e.target.value)
                                }}
                                required
                                autoFocus
                                maxLength={120}
                                placeholder={t('wizard.title_placeholder_santa')}
                                className="mt-1 w-full rounded-card border border-line bg-card px-3 py-2"
                            />
                        </label>
                        <label className="block">
                            <span className="text-sm font-medium">
                                {t('santa.budget')}
                                <InfoTip className="ml-1">{t('santa.budget_hint')}</InfoTip>
                            </span>
                            <input
                                type="number"
                                min={0}
                                step="1"
                                value={form.data.budget_max}
                                onChange={(e) => form.setData('budget_max', e.target.value)}
                                className="mt-1 w-full rounded-card border border-line bg-card px-3 py-2"
                            />
                        </label>
                        <label className="block">
                            <span className="text-sm font-medium">{t('santa.exchange_date')}</span>
                            <input
                                type="date"
                                value={form.data.exchange_date}
                                onChange={(e) => form.setData('exchange_date', e.target.value)}
                                className="mt-1 w-full rounded-card border border-line bg-card px-3 py-2"
                            />
                        </label>
                        <label className="block">
                            <span className="text-sm font-medium">{t('santa.theme')}</span>
                            <input
                                value={form.data.theme}
                                onChange={(e) => form.setData('theme', e.target.value)}
                                maxLength={120}
                                className="mt-1 w-full rounded-card border border-line bg-card px-3 py-2"
                            />
                        </label>
                        {myLists.length > 0 && (
                            <label className="block">
                                <span className="text-sm font-medium">
                                    {t('santa.your_list')}
                                    <InfoTip className="ml-1">{t('santa.your_list_hint')}</InfoTip>
                                </span>
                                <select
                                    value={form.data.wishlist_id}
                                    onChange={(e) => form.setData('wishlist_id', e.target.value)}
                                    className="mt-1 w-full rounded-card border border-line bg-card px-3 py-2"
                                >
                                    <option value="">{t('santa.no_list_option')}</option>
                                    {myLists.map((list) => (
                                        <option key={list.id} value={list.id}>{list.title}</option>
                                    ))}
                                </select>
                            </label>
                        )}
                    </div>
                    <p className="mt-3 text-sm text-ink-soft">{t('wizard.santa_sharing_hint')}</p>
                </>
            ) : (
                <fieldset>
                    <legend id="wizard-title" className="text-lg font-medium">
                        {t('lists.for_whom')}
                        <InfoTip className="ml-1">
                            <span className="block">{t('wizard.kind_hint')}</span>
                            {choices.map((choice) => (
                                <span key={choice.value} className="mt-2 block">
                                    <span className="font-medium text-ink">{choice.label}</span> — {choice.body}
                                </span>
                            ))}
                        </InfoTip>
                    </legend>

                    {/*
                      Three cards, the kind's name under each: the choice is
                      "who for", and the kind is what that answer makes, said
                      so the reader learns the word on the way past.
                    */}
                    <div className="mt-3 grid gap-2 sm:grid-cols-3 sm:gap-3">
                        {choices.map((choice) => (
                            <button
                                key={choice.value}
                                type="button"
                                aria-pressed={kind === choice.value}
                                onClick={() => choose(choice.value)}
                                className={`rounded-card border bg-card px-4 py-3 text-left transition ${
                                    kind === choice.value ? 'border-accent ring-2 ring-accent/30' : 'border-line hover:border-ink'
                                }`}
                            >
                                <span className={`block font-medium ${kind === choice.value ? 'text-accent' : ''}`}>
                                    {choice.label}
                                </span>
                                <span className="block text-xs text-ink-soft">{choice.kindLabel}</span>
                            </button>
                        ))}
                    </div>
                </fieldset>
            )}

            {!isSanta && (
                <div className="mt-5 grid gap-4 sm:grid-cols-2">
                    {forSomeone && (
                        <div className="sm:col-span-2">
                            <label className="block text-sm font-medium" htmlFor="wizard-person">
                                {t('lists.person_name')}
                            </label>
                            {people.length > 0 && (
                                <div className="mt-2">
                                    <PersonPicker variant="compact" people={people} isChosen={isPicked} onChoose={pick} />
                                </div>
                            )}
                            <input
                                id="wizard-person"
                                ref={nameField}
                                type="text"
                                maxLength={80}
                                value={form.data.new_recipient}
                                onChange={(e) =>
                                    form.setData((data) => ({ ...data, new_recipient: e.target.value, recipient_id: '', friend_id: '' }))
                                }
                                placeholder={t('wizard.person_placeholder')}
                                className="mt-2 w-full rounded-card border border-line bg-card px-3 py-2"
                            />
                        </div>
                    )}

                    <div className="sm:col-span-2">
                        <label className="block text-sm font-medium" htmlFor="wizard-title-field">
                            {t('lists.list_name')}
                        </label>
                        <input
                            id="wizard-title-field"
                            type="text"
                            maxLength={120}
                            value={form.data.title}
                            onChange={(e) => {
                                setTitleTouched(true)
                                form.setData('title', e.target.value)
                            }}
                            placeholder={suggestion || t(`wizard.title_placeholder_${kind}`)}
                            className="mt-1 w-full rounded-card border border-line bg-card px-3 py-2"
                        />
                    </div>
                </div>
            )}

            {Object.keys(form.errors).length > 0 && (
                <p className="mt-3 text-sm text-danger" role="alert">{Object.values(form.errors)[0]}</p>
            )}

            <div className="mt-5 flex flex-wrap items-center gap-x-4 gap-y-3">
                {button}
                {onCancel && (
                    <button type="button" onClick={onCancel} className="text-sm text-ink-soft underline">
                        {t('lists.cancel')}
                    </button>
                )}
            </div>

            {/*
              What moved, said once, so nobody hunts for the occasion on this
              screen. And the Secret Friend group, the fourth thing "make a
              new list" may have meant, as a quiet way across.
            */}
            <div className="mt-4 flex flex-wrap items-baseline justify-between gap-x-4 gap-y-2 text-sm text-ink-soft">
                {!isSanta && <p>{t('wizard.one_step_hint')}</p>}
                <button
                    type="button"
                    onClick={() => choose(isSanta ? 'mine' : 'santa')}
                    className="underline hover:text-ink"
                >
                    {isSanta ? t('wizard.not_santa') : `${t('wizard.or_santa')} (${t('santa.title')})`}
                </button>
            </div>
        </form>
    )
}
