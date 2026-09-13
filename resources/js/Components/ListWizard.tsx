import { useForm, usePage } from '@inertiajs/react'
import { useEffect, useState } from 'react'
import type { SharedProps } from '../types'
import { formatOccasionDate } from '../types'
import { useTranslations } from '../useTranslations'
import InfoTip from './InfoTip'
import SignInLink from './SignInLink'

/**
 * The four answers to "who is it for?". Three are list kinds; `santa` is a
 * Secret Friend group, which is not a list at all but is the fourth thing
 * somebody pressing "make a new list" may have meant (owner's call,
 * 2026-09-12). It runs the same three steps with its own second and third,
 * and posts to the group endpoint instead of the list one.
 */
type Kind = 'mine' | 'for_someone' | 'group' | 'santa'

/** Somebody a list can be for: a friend, or a person I made a profile for. */
interface Person {
    name: string
    /**
     * When their birthday next falls, resolved by the server, or null when
     * nobody has said. The wizard shows it and never computes it: the date on
     * the list is the server's answer, and two answers would eventually differ.
     */
    birthday: string | null
}

interface Friend extends Person {
    id: number
    /**
     * The profile this friend already has with me, if any. Picking them then
     * means picking that profile rather than minting a second one, which is
     * what lets every friend stay in the list.
     */
    recipientId: string | null
}

/** What the server offers the wizard; see App\Services\Wishlist\WizardOffer. */
export interface WizardOffer {
    recipients: (Person & { id: string })[]
    friends: Friend[]
    occasions: { value: string; label: string; date: string | null }[]
    /** My own lists, about myself: what a Secret Friend group may be pointed at. */
    myLists: { id: string; title: string }[]
}

interface Props extends WizardOffer {
    signedIn: boolean
    /**
     * A kind already chosen — by the link that opened the page, as the Gift
     * Cove's cards and the home page do with `?new=<kind>`. The first
     * question is then answered, so the wizard opens on the second; the
     * back button still leads to it.
     */
    initialKind?: Kind
    /** Offered where the wizard was opened by a button and can be put away again. */
    onCancel?: () => void
}

/**
 * What the wizard remembers between a sign-in and the return.
 *
 * A visitor walks all three steps signed out — the walk is the explanation —
 * and signs in at the last one. The sign-in leaves the page, and a wizard that
 * comes back empty has thrown away three steps of answers at the moment they
 * were about to be used. Local storage rather than session: a magic link is
 * opened from the mail, in a new tab, and a new tab has no session storage.
 * A day is the limit — a draft list is not something to find again next week.
 */
const DRAFT = 'bc.list-wizard'
const DRAFT_TTL = 24 * 60 * 60 * 1000

const STEPS = ['kind', 'details', 'sharing'] as const
type Step = (typeof STEPS)[number]

/**
 * Is there a draft waiting to be replayed? My Lists asks on arrival, so a
 * sign-in that lands there rather than on the Gift Cove still finishes the
 * list it was started for.
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
 * A list, made in three questions.
 *
 * The create form on My Lists asks the same things on one screen and assumes
 * the reader already knows what a group list is, what sharing does to a wish
 * list and why an occasion matters. This asks them one at a time and explains
 * each before it asks, so that by the last step somebody who arrived with no
 * idea what the site does has met every option a list has — and made one.
 *
 * It posts to the same endpoint as that form. The extra settings (occasion,
 * sharing, adding, voting, friends) were always settable on the list page
 * afterwards; `store()` now takes them too, because a wizard that explains an
 * option and then sends you elsewhere to turn it on has explained it to nobody.
 */
export default function ListWizard({ signedIn, recipients, friends, occasions, myLists, initialKind, onCancel }: Props) {
    const { market } = usePage<SharedProps>().props
    const { t } = useTranslations()
    const base = `/${market.key}`

    const [step, setStep] = useState<Step>(initialKind === undefined ? 'kind' : 'details')

    /*
     * "That date is not our date."
     *
     * A filled-in date has to be arguable. Christmas Day is the 25th and plenty
     * of families here hand out presents on the evening of the 24th; a birthday
     * is a birthday and the party is on the Saturday. So the wizard says what
     * it will put on the list and offers to take a different date instead,
     * rather than either asking everybody or deciding for everybody.
     */
    const [ownDate, setOwnDate] = useState(false)
    const [kind, setKind] = useState<Kind>(initialKind ?? 'mine')
    const [replay, setReplay] = useState(false)
    const [titleTouched, setTitleTouched] = useState(false)

    const form = useForm({
        title: '',
        recipient_id: '',
        new_recipient: '',
        friend_id: '' as string | number,
        together: initialKind === 'group',
        birthday_day: '',
        birthday_month: '',
        event_type: '',
        event_date: '',
        visibility: 'private' as 'private' | 'link',
        link_can_add: false,
        voting_enabled: true,
        share_with: [] as number[],
        /*
         * The Secret Friend group's own fields. `title` is shared: it is the
         * group's name there, and a list's title otherwise. Euros here, cents
         * in the column, as the group form has always done.
         */
        budget_max: '',
        exchange_date: '',
        theme: '',
        wishlist_id: '',
    })

    /*
     * Restore a draft, and if it was written by somebody who has since signed
     * in, put them back on the step they left from: the one with the button.
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
            // The title in the draft is the one they left with, typed or not;
            // the auto-fill must not write over it on the way back in.
            setTitleTouched(true)

            /*
             * Signed in on the way back: the button they pressed was "sign in
             * and make the list", so make it. Deferred one render, because
             * the data set just above is not readable until then.
             */
            if (signedIn) {
                setStep('sharing')
                setReplay(true)
            }
        } catch {
            // A private window or cleared storage: start fresh, which is what
            // the page does anyway.
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [])

    function remember() {
        try {
            localStorage.setItem(DRAFT, JSON.stringify({ at: Date.now(), kind, data: form.data }))
        } catch {
            // Nothing to do: the draft simply does not survive a sign-in.
        }
    }

    function forget() {
        try {
            localStorage.removeItem(DRAFT)
        } catch {
            // Same as above.
        }
    }

    const shared = form.data.visibility === 'link'
    const isSanta = kind === 'santa'
    // A group is "for someone" in the sense of the two list kinds only: it
    // names no person, and the person question must not appear for it.
    const forSomeone = kind === 'for_someone' || kind === 'group'
    const index = STEPS.indexOf(step)

    function choose(next: Kind) {
        setKind(next)
        // Only a group list pools money; the server re-derives the kind from
        // the recipient and this bit, so the form just keeps them consistent.
        form.setData('together', next === 'group')

        if (next === 'mine' || next === 'santa') {
            form.setData('recipient_id', '')
            form.setData('new_recipient', '')
            form.setData('friend_id', '')
        }
    }

    function next() {
        setStep(STEPS[Math.min(index + 1, STEPS.length - 1)])
    }

    function back() {
        setStep(STEPS[Math.max(index - 1, 0)])
    }

    function submit() {
        /*
         * A group, not a list: only the group's fields, to the group
         * endpoint, which auto-joins the organiser and lands on the group
         * page with the invite link. The same `store()` the Secret Friend
         * hub's own form posts to, so there is one way a group is made.
         */
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
            ...data,
            /*
             * Only what this list can use. `store()` treats an absent key as
             * "leave the kind default alone", which is the right answer for a
             * private list's `link_can_add` and a wish list's `voting_enabled`
             * — the wizard never showed those switches, so it has no answer
             * to send.
             */
            link_can_add: shared ? data.link_can_add : undefined,
            voting_enabled: kind === 'group' ? data.voting_enabled : undefined,
            share_with: shared ? data.share_with : [],
            event_type: data.event_type || null,
            event_date: data.event_date || null,
            friend_id: data.friend_id === '' ? null : data.friend_id,
            recipient_id: data.recipient_id || null,
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
     * The person this list is about, whichever way they were named.
     *
     * A friend can arrive as a friend id or as the profile they already have,
     * so both spellings are looked up here and the rest of the step asks this
     * one question instead of three.
     */
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

    const personName = person?.name ?? form.data.new_recipient

    /*
     * The title follows the person until somebody types one.
     *
     * "For Anna" is what nine lists in ten would be called, so it is
     * written for them the moment a name is known; a title the person
     * has edited is theirs and is never overwritten, and a list for
     * yourself keeps the placeholder's suggestions instead.
     */
    useEffect(() => {
        if (titleTouched) return

        const name = personName.trim()
        const next = forSomeone && name !== '' ? t('wizard.title_for', { name }) : ''

        /*
         * Only a real change is written. This effect runs on mount too, with
         * the empty initial values, in the same pass as the draft restore
         * above — and an unconditional write of '' there landed after the
         * restore and wiped the title out of every replayed draft. Found by
         * signing in at the end of the wizard and arriving without one.
         */
        if (next !== form.data.title) {
            form.setData('title', next)
        }
        // The form object is stable per render; personName is what changes.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [personName, forSomeone, titleTouched])
    const occasion = occasions.find((o) => o.value === form.data.event_type) ?? null
    const isBirthday = occasion?.value === 'birthday'
    const typedBirthday = form.data.birthday_day !== '' && form.data.birthday_month !== ''

    /*
     * The date this occasion falls on, when it is not a question.
     *
     * A birthday is the person's birthday and Christmas is the 25th, so a date
     * field beside either is asking somebody to look up something already on
     * the screen. Null means we genuinely do not know: a wedding, or a birthday
     * nobody has told us. The server derives the same date on the way in, from
     * the same sources; this is what the reader sees while deciding.
     */
    const settledDate = isBirthday ? person?.birthday ?? null : occasion?.date ?? null

    /*
     * The birthday pair belongs to the person, and is asked for whenever it is
     * missing and wanted: for somebody new, and for anybody at all once the
     * occasion is their birthday. It used to appear only for a new person, so
     * choosing "Birthday" for somebody already on file left nothing to answer
     * it with. What is typed here is stored on their profile, so the reminders
     * and the next list both have it.
     */
    const isNewPerson = form.data.recipient_id === '' && form.data.friend_id === ''
    const asksForBirthday = forSomeone && (isNewPerson || (isBirthday && person?.birthday == null))

    // A birthday being typed in above answers it too, but only the server can
    // say which year it lands in, so the wizard promises rather than prints.
    const fromBirthday = isBirthday && settledDate === null && typedBirthday
    /*
     * One question at a time: when the birthday pair below is what answers
     * this occasion, the date field would be a second way to answer it, and a
     * screen with both asks the reader to choose which one counts.
     */
    const asksForDate = ownDate || (occasion !== null && settledDate === null && !fromBirthday && !(isBirthday && asksForBirthday))


    /*
     * One paragraph per kind, written for this step rather than borrowed
     * from the create form: the form's line and a second "more" line said the
     * same thing twice in different words, and the reader had to notice that.
     */
    const choices: { value: Kind; label: string; body: string }[] = [
        { value: 'mine', label: t('lists.for_me'), body: t('wizard.kind_mine_body') },
        { value: 'for_someone', label: t('lists.for_someone_else'), body: t('wizard.kind_for_someone_body') },
        { value: 'group', label: t('lists.for_group'), body: t('wizard.kind_group_body') },
        // Secret Friend, fourth: see `Kind`.
        { value: 'santa', label: t('santa.title'), body: t('santa.subtitle') },
    ]

    const canContinue = step !== 'details' || (form.data.title.trim() !== '' && (!forSomeone || personName.trim() !== ''))

    return (
        <section className="rounded-card border border-accent/40 bg-accent/5 p-5 sm:p-6" aria-labelledby="wizard-title">
            <div className="flex flex-wrap items-baseline justify-between gap-2">
                <h2 id="wizard-title" className="text-lg font-medium">{t(isSanta ? 'wizard.title_santa' : 'wizard.title')}</h2>
                <p className="text-xs text-ink-soft tabular-nums">
                    {t('wizard.step_of', { step: String(index + 1), total: String(STEPS.length) })}
                </p>
            </div>

            {/*
              Four dots, the current one filled and the ones behind it done.
              A progress bar would say "60%"; the reader wants to know "how
              many more questions", which is what dots count.
            */}
            <ol className="mt-3 flex flex-wrap gap-x-4 gap-y-1 text-xs" aria-hidden="true">
                {STEPS.map((s, i) => (
                    <li
                        key={s}
                        className={`flex items-center gap-1.5 ${i === index ? 'font-medium text-accent' : i < index ? 'text-ink' : 'text-ink-soft'}`}
                    >
                        <span className={`inline-block h-2 w-2 rounded-full ${i <= index ? 'bg-accent' : 'bg-line'}`} />
                        {t(`wizard.step_${s}`)}
                    </li>
                ))}
            </ol>

            <div className="mt-5">
                {step === 'kind' && (
                    <fieldset>
                        <legend className="font-medium">
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

                        {/* Two by two from `sm`, four across from `lg`:
                            four cards in three columns leaves a widow. */}
                        <div className="mt-3 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                            {choices.map((choice) => (
                                <button
                                    key={choice.value}
                                    type="button"
                                    aria-pressed={kind === choice.value}
                                    onClick={() => choose(choice.value)}
                                    className={`rounded-card border bg-card p-4 text-left transition ${
                                        kind === choice.value ? 'border-accent ring-2 ring-accent/30' : 'border-line hover:border-ink'
                                    }`}
                                >
                                    <span className={`block font-medium ${kind === choice.value ? 'text-accent' : ''}`}>
                                        {choice.label}
                                    </span>
                                </button>
                            ))}
                        </div>
                    </fieldset>
                )}

                {/*
                  Step 2 for a Secret Friend group: the group form's fields,
                  two to a row, in the wizard's clothes. Name on its own row,
                  budget beside date, theme alone. The hub's own form asks the
                  same things; this is the same request from the other door.
                */}
                {step === 'details' && isSanta && (
                    <div className="grid gap-5 sm:grid-cols-2">
                        <label className="block sm:col-span-2">
                            <span className="font-medium">{t('santa.group_name')}</span>
                            <input
                                value={form.data.title}
                                onChange={(e) => {
                                    setTitleTouched(true)
                                    form.setData('title', e.target.value)
                                }}
                                required
                                maxLength={120}
                                placeholder={t('wizard.title_placeholder_santa')}
                                className="mt-2 w-full rounded-card border border-line bg-card px-3 py-2"
                            />
                        </label>

                        <label className="block">
                            <span className="font-medium">
                                {t('santa.budget')}
                                <InfoTip className="ml-1">{t('santa.budget_hint')}</InfoTip>
                            </span>
                            <input
                                type="number"
                                min={0}
                                step="1"
                                value={form.data.budget_max}
                                onChange={(e) => form.setData('budget_max', e.target.value)}
                                className="mt-2 w-full rounded-card border border-line bg-card px-3 py-2"
                            />
                        </label>

                        <label className="block">
                            <span className="font-medium">{t('santa.exchange_date')}</span>
                            <input
                                type="date"
                                value={form.data.exchange_date}
                                onChange={(e) => form.setData('exchange_date', e.target.value)}
                                className="mt-2 w-full rounded-card border border-line bg-card px-3 py-2"
                            />
                        </label>

                        <label className="block">
                            <span className="font-medium">{t('santa.theme')}</span>
                            <input
                                value={form.data.theme}
                                onChange={(e) => form.setData('theme', e.target.value)}
                                maxLength={120}
                                className="mt-2 w-full rounded-card border border-line bg-card px-3 py-2"
                            />
                        </label>
                    </div>
                )}

                {step === 'details' && !isSanta && (
                    <div className="grid gap-5 sm:grid-cols-2">

                        {forSomeone && (
                            <div className="sm:col-span-2">
                                <p className="font-medium">
                                    {t('wizard.person')}
                                    <InfoTip className="ml-1">{t('wizard.person_hint')}</InfoTip>
                                </p>

                                {(recipients.length > 0 || friends.length > 0) && (
                                    <select
                                        aria-label={t('lists.for_whom')}
                                        value={form.data.friend_id === '' ? form.data.recipient_id : `friend:${form.data.friend_id}`}
                                        onChange={(e) => {
                                            const value = e.target.value

                                            if (value.startsWith('friend:')) {
                                                form.setData('friend_id', Number(value.slice(7)))
                                                form.setData('recipient_id', '')
                                                form.setData('new_recipient', '')

                                                return
                                            }

                                            form.setData('friend_id', '')
                                            form.setData('recipient_id', value)
                                        }}
                                        className="mt-2 w-full rounded-card border border-line bg-card px-3 py-2"
                                    >
                                        <option value="">{t('lists.someone_new')}</option>
                                        {recipients.map((r) => (
                                            <option key={r.id} value={r.id}>{r.name}</option>
                                        ))}
                                        {friends.length > 0 && (
                                            <optgroup label={t('lists.from_your_friends')}>
                                                {friends.map((f) => (
                                                    /*
                                                      A friend who already has a profile is offered
                                                      as that profile: one person, one entry, and no
                                                      second profile made by picking them here.
                                                    */
                                                    <option
                                                        key={f.id}
                                                        value={f.recipientId ?? `friend:${f.id}`}
                                                    >
                                                        {f.name}
                                                    </option>
                                                ))}
                                            </optgroup>
                                        )}
                                    </select>
                                )}

                                {(isNewPerson || asksForBirthday) && (
                                    <div className="mt-2 grid gap-3 sm:grid-cols-2">
                                        {isNewPerson && (
                                            <div>
                                                <label className="block text-sm" htmlFor="wizard-person">
                                                    {t('lists.person_name')}
                                                </label>
                                                <input
                                                    id="wizard-person"
                                                    type="text"
                                                    maxLength={80}
                                                    value={form.data.new_recipient}
                                                    onChange={(e) => form.setData('new_recipient', e.target.value)}
                                                    className="mt-1 w-full rounded-card border border-line bg-card px-3 py-2"
                                                />
                                            </div>
                                        )}
                                        {asksForBirthday && (
                                        <div>
                                            <p className="text-sm">
                                                {t('lists.birthday_optional')}
                                                <InfoTip className="ml-1">{t('lists.birthday_why')}</InfoTip>
                                            </p>
                                            <div className="mt-1 flex gap-2">
                                                <select
                                                    aria-label={t('lists.birthday_day')}
                                                    value={form.data.birthday_day}
                                                    onChange={(e) => form.setData('birthday_day', e.target.value)}
                                                    className="w-1/2 rounded-card border border-line bg-card px-2 py-2"
                                                >
                                                    <option value="">{t('lists.birthday_day')}</option>
                                                    {Array.from({ length: 31 }, (_, i) => i + 1).map((d) => (
                                                        <option key={d} value={d}>{d}</option>
                                                    ))}
                                                </select>
                                                <select
                                                    aria-label={t('lists.birthday_month')}
                                                    value={form.data.birthday_month}
                                                    onChange={(e) => form.setData('birthday_month', e.target.value)}
                                                    className="w-1/2 rounded-card border border-line bg-card px-2 py-2"
                                                >
                                                    <option value="">{t('lists.birthday_month')}</option>
                                                    {Array.from({ length: 12 }, (_, i) => i + 1).map((m) => (
                                                        <option key={m} value={m}>{m}</option>
                                                    ))}
                                                </select>
                                            </div>
                                        </div>
                                        )}
                                    </div>
                                )}

                                {/* Their birthday, when they brought one with them. */}
                                {person?.birthday && (
                                    <p className="mt-2 text-sm text-ink-soft">
                                        🎂 {formatOccasionDate(person.birthday, market)}
                                    </p>
                                )}
                            </div>
                        )}
                        <div className="sm:col-span-2">
                            <label className="block font-medium" htmlFor="wizard-title-field">
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
                                placeholder={t(`wizard.title_placeholder_${kind}`)}
                                className="mt-1 w-full rounded-card border border-line bg-card px-3 py-2"
                            />
                            {form.errors.title && <p className="mt-1 text-sm text-danger">{form.errors.title}</p>}
                        </div>

                        <div className="sm:col-span-2">
                            <p className="font-medium">
                                {t('wizard.occasion')}
                                <InfoTip className="ml-1">
                                    {t(kind === 'mine' ? 'wizard.occasion_hint_mine' : 'wizard.occasion_hint_other')}
                                </InfoTip>
                            </p>
            {/*
                              Both fields carry their label, which is also what
                              keeps them the same height: a bare select beside a
                              labelled input stretches to the taller cell and
                              the pair reads as two different controls.
                            */}
                            <div className="mt-2 grid items-start gap-3 sm:grid-cols-2">
                                <label className="block text-sm">
                                    {t('registry.occasion')}
                                    <select
                                        aria-label={t('registry.occasion')}
                                        value={form.data.event_type}
                                        onChange={(e) => {
                                            form.setData('event_type', e.target.value)
                                            // A date typed for the occasion
                                            // before this one is not an answer
                                            // about this one.
                                            form.setData('event_date', '')
                                            setOwnDate(false)
                                        }}
                                        className="mt-1 block w-full rounded-card border border-line bg-card px-3 py-2 text-base text-ink"
                                    >
                                        <option value="">{t('registry.none')}</option>
                                        {occasions.map((o) => (
                                            <option key={o.value} value={o.value}>{o.label}</option>
                                        ))}
                                    </select>
                                </label>

                                {/*
                                  The date, and only when it is a question.

                                  It used to sit here always, greyed out until
                                  an occasion was chosen and then demanding one
                                  next to "Birthday" -- a field asking for
                                  something the screen above already knew. Now
                                  it appears for the occasions nobody can look
                                  up, with a label of its own rather than a bare
                                  box wearing a placeholder.
                                */}
                                {asksForDate && (
                                    <label className="block text-sm">
                                        {t('wizard.date_label')}
                                        <input
                                            type="date"
                                            value={form.data.event_date}
                                            onChange={(e) => form.setData('event_date', e.target.value)}
                                            className="mt-1 block w-full rounded-card border border-line bg-card px-3 py-2 text-base text-ink"
                                        />
                                    </label>
                                )}
                            </div>

                            {settledDate !== null && !ownDate && (
                                <p className="mt-2 text-sm text-ink-soft">
                                    {t('wizard.date_known', {
                                        date: formatOccasionDate(settledDate, market),
                                    })}{' '}
                                    <button
                                        type="button"
                                        onClick={() => {
                                            // Starts from the date it was
                                            // going to use, because most
                                            // corrections are a day or two.
                                            form.setData('event_date', settledDate)
                                            setOwnDate(true)
                                        }}
                                        className="underline hover:text-ink"
                                    >
                                        {t('wizard.date_other')}
                                    </button>
                                </p>
                            )}

                            {fromBirthday && (
                                <p className="mt-2 text-sm text-ink-soft">{t('wizard.date_from_birthday')}</p>
                            )}

                            {isBirthday && settledDate === null && !typedBirthday && (
                                <p className="mt-2 text-sm text-ink-soft">
                                    {t(forSomeone ? 'wizard.date_needs_birthday' : 'wizard.date_needs_mine')}
                                </p>
                            )}
                        </div>
                    </div>
                )}

                {/*
                  Step 3 for a group: my own list, and what happens next. A
                  group is shared by its invite link, which does not exist
                  until the group does, so this step cannot ask "who may see
                  it" the way a list's does; it says how the sharing will go
                  and asks the one thing that can be settled now — which of
                  my lists whoever draws me will see.
                */}
                {step === 'sharing' && isSanta && (
                    <div>
                        <p className="font-medium">{t('wizard.santa_sharing')}</p>
                        <p className="mt-2 text-sm text-ink-soft">{t('wizard.santa_sharing_hint')}</p>

                        {myLists.length > 0 ? (
                            <label className="mt-5 block">
                                <span className="font-medium">
                                    {t('santa.your_list')}
                                    <InfoTip className="ml-1">{t('santa.your_list_hint')}</InfoTip>
                                </span>
                                <select
                                    value={form.data.wishlist_id}
                                    onChange={(e) => form.setData('wishlist_id', e.target.value)}
                                    className="mt-2 w-full rounded-card border border-line bg-card px-3 py-2 sm:w-auto sm:min-w-64"
                                >
                                    <option value="">{t('santa.no_list_option')}</option>
                                    {myLists.map((list) => (
                                        <option key={list.id} value={list.id}>
                                            {list.title}
                                        </option>
                                    ))}
                                </select>
                            </label>
                        ) : (
                            /* Nothing to choose yet: said, rather than a select
                               with one empty option. The group page offers the
                               same choice once a list exists. */
                            <p className="mt-5 text-sm text-ink-soft">{t('wizard.santa_no_list_yet')}</p>
                        )}
                    </div>
                )}

                {step === 'sharing' && !isSanta && (
                    <div>
                        <fieldset>
                            <legend className="font-medium">
                                {t('wizard.sharing')}
                                <InfoTip className="ml-1">
                                    <span className="block">{t(`wizard.sharing_hint_${kind}`)}</span>
                                    {(['private', 'link'] as const).map((choice) => (
                                        <span key={choice} className="mt-2 block">
                                            <span className="font-medium text-ink">{t(`wizard.visibility_${choice}`)}</span> —{' '}
                                            {t(`wizard.visibility_${choice}_${kind}`)}
                                        </span>
                                    ))}
                                    <span className="mt-2 block">{t('wizard.rule')}</span>
                                </InfoTip>
                            </legend>

                            <div className="mt-3 grid gap-3 sm:grid-cols-2">
                                {(['private', 'link'] as const).map((choice) => (
                                    <button
                                        key={choice}
                                        type="button"
                                        aria-pressed={form.data.visibility === choice}
                                        onClick={() => form.setData('visibility', choice)}
                                        className={`rounded-card border bg-card p-4 text-left transition ${
                                            form.data.visibility === choice ? 'border-accent ring-2 ring-accent/30' : 'border-line hover:border-ink'
                                        }`}
                                    >
                                        <span className={`block font-medium ${form.data.visibility === choice ? 'text-accent' : ''}`}>
                                            {t(`wizard.visibility_${choice}`)}
                                        </span>
                                    </button>
                                ))}
                            </div>
                        </fieldset>

                        {shared && (
                            <div className="mt-4 space-y-4">
                                <label className="flex items-start gap-3">
                                    <input
                                        type="checkbox"
                                        checked={form.data.link_can_add}
                                        onChange={(e) => form.setData('link_can_add', e.target.checked)}
                                        className="mt-1"
                                    />
                                    <span>
                                        <span className="block text-sm font-medium">
                                            {t('lists.anyone_can_add')}
                                            <InfoTip className="ml-1">{t('wizard.can_add_hint')}</InfoTip>
                                        </span>
                                    </span>
                                </label>

                                {kind === 'group' && (
                                    <label className="flex items-start gap-3">
                                        <input
                                            type="checkbox"
                                            checked={form.data.voting_enabled}
                                            onChange={(e) => form.setData('voting_enabled', e.target.checked)}
                                            className="mt-1"
                                        />
                                        <span>
                                            <span className="block text-sm font-medium">
                                                {t('lists.voting_enabled')}
                                                <InfoTip className="ml-1">{t('lists.voting_enabled_hint')}</InfoTip>
                                            </span>
                                        </span>
                                    </label>
                                )}

                                <div>
                                    <p className="text-sm font-medium">
                                        {t('lists.share_with_friends')}
                                        {friends.length > 0 && <InfoTip className="ml-1">{t('wizard.friends_hint')}</InfoTip>}
                                    </p>
                                    {friends.length === 0 && <p className="text-xs text-ink-soft">{t('wizard.friends_none')}</p>}
                                    {friends.length > 0 && (
                                        <div className="mt-2 flex flex-wrap gap-2">
                                            {friends.map((f) => {
                                                const on = form.data.share_with.includes(f.id)

                                                return (
                                                    <button
                                                        key={f.id}
                                                        type="button"
                                                        aria-pressed={on}
                                                        onClick={() =>
                                                            form.setData(
                                                                'share_with',
                                                                on
                                                                    ? form.data.share_with.filter((id) => id !== f.id)
                                                                    : [...form.data.share_with, f.id],
                                                            )
                                                        }
                                                        className={`rounded-full border px-3 py-1 text-sm ${
                                                            on ? 'border-sage bg-sage/20 text-ink' : 'border-line bg-card text-ink-soft hover:border-ink'
                                                        }`}
                                                    >
                                                        {on ? '✓ ' : ''}{f.name}
                                                    </button>
                                                )
                                            })}
                                        </div>
                                    )}
                                </div>
                            </div>
                        )}

                    </div>
                )}

            </div>

            {/* What the server refused, on whichever step the reader is: a
                replayed draft can be refused on the last one. */}
            {Object.keys(form.errors).length > 0 && (
                <p className="mt-3 text-sm text-danger" role="alert">{Object.values(form.errors)[0]}</p>
            )}

            <div className="mt-6 flex flex-wrap items-center justify-between gap-3">
                <div className="flex items-center gap-4">
                    <button
                        type="button"
                        onClick={back}
                        disabled={index === 0}
                        className="text-sm text-ink-soft underline disabled:invisible"
                    >
                        ← {t('wizard.back')}
                    </button>
                    {onCancel && (
                        <button type="button" onClick={onCancel} className="text-sm text-ink-soft underline">
                            {t('lists.cancel')}
                        </button>
                    )}
                </div>

                {step !== 'sharing' ? (
                    <button
                        type="button"
                        onClick={next}
                        disabled={!canContinue}
                        className="rounded-lg bg-accent px-4 py-2 text-sm font-medium text-white hover:bg-accent-dark disabled:opacity-50"
                    >
                        {t('wizard.next')} →
                    </button>
                ) : signedIn ? (
                    <button
                        type="button"
                        onClick={submit}
                        disabled={form.processing}
                        className="rounded-lg bg-accent px-4 py-2 text-sm font-medium text-white hover:bg-accent-dark disabled:opacity-50"
                    >
                        {t(isSanta ? 'santa.create' : 'lists.create')}
                    </button>
                ) : (
                    <SignInLink
                        hint={t('wizard.sign_in_hint')}
                        onNavigate={remember}
                        className="rounded-lg bg-accent px-4 py-2 text-sm font-medium text-white hover:bg-accent-dark"
                    >
                        {t(isSanta ? 'wizard.sign_in_and_create_santa' : 'wizard.sign_in_and_create')}
                    </SignInLink>
                )}
            </div>
        </section>
    )
}
