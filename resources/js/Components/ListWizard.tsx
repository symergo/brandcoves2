import { useForm, usePage } from '@inertiajs/react'
import { useEffect, useState } from 'react'
import type { SharedProps } from '../types'
import { useTranslations } from '../useTranslations'
import SignInLink from './SignInLink'

type Kind = 'mine' | 'for_someone' | 'group'

interface Props {
    signedIn: boolean
    recipients: { id: string; name: string }[]
    /** Friends who are not a recipient yet: the "who is it for" answers. */
    friends: { id: number; name: string }[]
    /** Every friend: the "who may see it" answers. */
    allFriends: { id: number; name: string }[]
    occasions: { value: string; label: string }[]
}

/**
 * What the wizard remembers between a sign-in and the return.
 *
 * A visitor walks all four steps signed out — the walk is the explanation —
 * and signs in at the last one. The sign-in leaves the page, and a wizard that
 * comes back empty has thrown away four steps of answers at the moment they
 * were about to be used. Local storage rather than session: a magic link is
 * opened from the mail, in a new tab, and a new tab has no session storage.
 * A day is the limit — a draft list is not something to find again next week.
 */
const DRAFT = 'bc.list-wizard'
const DRAFT_TTL = 24 * 60 * 60 * 1000

const STEPS = ['kind', 'details', 'sharing', 'done'] as const
type Step = (typeof STEPS)[number]

/**
 * A list, made in four questions.
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
export default function ListWizard({ signedIn, recipients, friends, allFriends, occasions }: Props) {
    const { market } = usePage<SharedProps>().props
    const { t } = useTranslations()
    const base = `/${market.key}`

    const [step, setStep] = useState<Step>('kind')
    const [kind, setKind] = useState<Kind>('mine')

    const form = useForm({
        title: '',
        recipient_id: '',
        new_recipient: '',
        friend_id: '' as string | number,
        together: false,
        birthday_day: '',
        birthday_month: '',
        event_type: '',
        event_date: '',
        visibility: 'private' as 'private' | 'link',
        link_can_add: false,
        voting_enabled: true,
        share_with: [] as number[],
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

            if (signedIn) {
                setStep('done')
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
    const forSomeone = kind !== 'mine'
    const index = STEPS.indexOf(step)

    function choose(next: Kind) {
        setKind(next)
        // Only a group list pools money; the server re-derives the kind from
        // the recipient and this bit, so the form just keeps them consistent.
        form.setData('together', next === 'group')

        if (next === 'mine') {
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

    const personName = (() => {
        if (form.data.friend_id !== '') {
            return friends.find((f) => f.id === Number(form.data.friend_id))?.name ?? ''
        }

        if (form.data.recipient_id !== '') {
            return recipients.find((r) => r.id === form.data.recipient_id)?.name ?? ''
        }

        return form.data.new_recipient
    })()

    /*
     * One paragraph per kind, written for this step rather than borrowed
     * from the create form: the form's line and a second "more" line said the
     * same thing twice in different words, and the reader had to notice that.
     */
    const choices: { value: Kind; label: string; body: string }[] = [
        { value: 'mine', label: t('lists.for_me'), body: t('wizard.kind_mine_body') },
        { value: 'for_someone', label: t('lists.for_someone_else'), body: t('wizard.kind_for_someone_body') },
        { value: 'group', label: t('lists.for_group'), body: t('wizard.kind_group_body') },
    ]

    const canContinue = step !== 'details' || (form.data.title.trim() !== '' && (!forSomeone || personName.trim() !== ''))

    return (
        <section className="rounded-card border border-accent/40 bg-accent/5 p-5 sm:p-6" aria-labelledby="wizard-title">
            <div className="flex flex-wrap items-baseline justify-between gap-2">
                <h2 id="wizard-title" className="text-lg font-medium">{t('wizard.title')}</h2>
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
                        <legend className="font-medium">{t('lists.for_whom')}</legend>
                        <p className="mt-1 text-sm text-ink-soft">{t('wizard.kind_hint')}</p>

                        <div className="mt-3 grid gap-3 sm:grid-cols-3">
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
                                    <span className="mt-1 block text-sm text-ink-soft">{choice.body}</span>
                                </button>
                            ))}
                        </div>
                    </fieldset>
                )}

                {step === 'details' && (
                    <div className="grid gap-5 sm:grid-cols-2">
                        <div className="sm:col-span-2">
                            <label className="block font-medium" htmlFor="wizard-title-field">
                                {t('lists.list_name')}
                            </label>
                            <input
                                id="wizard-title-field"
                                type="text"
                                maxLength={120}
                                value={form.data.title}
                                onChange={(e) => form.setData('title', e.target.value)}
                                placeholder={t(`wizard.title_placeholder_${kind}`)}
                                className="mt-1 w-full rounded-lg border border-line bg-card px-3 py-2"
                            />
                            {form.errors.title && <p className="mt-1 text-sm text-red-700">{form.errors.title}</p>}
                        </div>

                        {forSomeone && (
                            <div className="sm:col-span-2">
                                <p className="font-medium">{t('wizard.person')}</p>
                                <p className="mt-1 text-sm text-ink-soft">{t('wizard.person_hint')}</p>

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
                                        className="mt-2 w-full rounded-lg border border-line bg-card px-3 py-2"
                                    >
                                        <option value="">{t('lists.someone_new')}</option>
                                        {recipients.map((r) => (
                                            <option key={r.id} value={r.id}>{r.name}</option>
                                        ))}
                                        {friends.length > 0 && (
                                            <optgroup label={t('lists.from_your_friends')}>
                                                {friends.map((f) => (
                                                    <option key={f.id} value={`friend:${f.id}`}>{f.name}</option>
                                                ))}
                                            </optgroup>
                                        )}
                                    </select>
                                )}

                                {form.data.recipient_id === '' && form.data.friend_id === '' && (
                                    <div className="mt-2 grid gap-3 sm:grid-cols-2">
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
                                                className="mt-1 w-full rounded-lg border border-line bg-card px-3 py-2"
                                            />
                                        </div>
                                        <div>
                                            <p className="text-sm">{t('lists.birthday_optional')}</p>
                                            <div className="mt-1 flex gap-2">
                                                <select
                                                    aria-label={t('lists.birthday_day')}
                                                    value={form.data.birthday_day}
                                                    onChange={(e) => form.setData('birthday_day', e.target.value)}
                                                    className="w-1/2 rounded-lg border border-line bg-card px-2 py-2"
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
                                                    className="w-1/2 rounded-lg border border-line bg-card px-2 py-2"
                                                >
                                                    <option value="">{t('lists.birthday_month')}</option>
                                                    {Array.from({ length: 12 }, (_, i) => i + 1).map((m) => (
                                                        <option key={m} value={m}>{m}</option>
                                                    ))}
                                                </select>
                                            </div>
                                            <p className="mt-1 text-xs text-ink-soft">{t('lists.birthday_why')}</p>
                                        </div>
                                    </div>
                                )}
                            </div>
                        )}

                        <div className="sm:col-span-2">
                            <p className="font-medium">{t('wizard.occasion')}</p>
                            <p className="mt-1 text-sm text-ink-soft">
                                {t(kind === 'mine' ? 'wizard.occasion_hint_mine' : 'wizard.occasion_hint_other')}
                            </p>
                            <div className="mt-2 grid gap-3 sm:grid-cols-2">
                                <select
                                    aria-label={t('registry.occasion')}
                                    value={form.data.event_type}
                                    onChange={(e) => form.setData('event_type', e.target.value)}
                                    className="w-full rounded-lg border border-line bg-card px-3 py-2"
                                >
                                    <option value="">{t('registry.none')}</option>
                                    {occasions.map((o) => (
                                        <option key={o.value} value={o.value}>{o.label}</option>
                                    ))}
                                </select>
                                <input
                                    type="date"
                                    aria-label={t('registry.date')}
                                    value={form.data.event_date}
                                    disabled={form.data.event_type === ''}
                                    onChange={(e) => form.setData('event_date', e.target.value)}
                                    className="w-full rounded-lg border border-line bg-card px-3 py-2 disabled:opacity-50"
                                />
                            </div>
                        </div>
                    </div>
                )}

                {step === 'sharing' && (
                    <div>
                        <fieldset>
                            <legend className="font-medium">{t('wizard.sharing')}</legend>
                            <p className="mt-1 text-sm text-ink-soft">{t(`wizard.sharing_hint_${kind}`)}</p>

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
                                        <span className="mt-1 block text-sm text-ink-soft">
                                            {t(`wizard.visibility_${choice}_${kind}`)}
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
                                        <span className="block text-sm font-medium">{t('lists.anyone_can_add')}</span>
                                        <span className="block text-xs text-ink-soft">{t('wizard.can_add_hint')}</span>
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
                                            <span className="block text-sm font-medium">{t('lists.voting_enabled')}</span>
                                            <span className="block text-xs text-ink-soft">{t('lists.voting_enabled_hint')}</span>
                                        </span>
                                    </label>
                                )}

                                <div>
                                    <p className="text-sm font-medium">{t('lists.share_with_friends')}</p>
                                    <p className="text-xs text-ink-soft">
                                        {allFriends.length > 0 ? t('wizard.friends_hint') : t('wizard.friends_none')}
                                    </p>
                                    {allFriends.length > 0 && (
                                        <div className="mt-2 flex flex-wrap gap-2">
                                            {allFriends.map((f) => {
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

                        <p className="mt-4 text-xs text-ink-soft">{t('wizard.rule')}</p>
                    </div>
                )}

                {step === 'done' && (
                    <div>
                        <p className="font-medium">{t('wizard.summary')}</p>
                        <dl className="mt-2 grid gap-x-6 gap-y-1 text-sm sm:grid-cols-[auto_1fr]">
                            <dt className="text-ink-soft">{t('lists.for_whom')}</dt>
                            <dd>{choices.find((c) => c.value === kind)?.label}{forSomeone && personName ? `: ${personName}` : ''}</dd>
                            <dt className="text-ink-soft">{t('lists.list_name')}</dt>
                            <dd>{form.data.title || '…'}</dd>
                            {form.data.event_type && (
                                <>
                                    <dt className="text-ink-soft">{t('registry.occasion')}</dt>
                                    <dd>
                                        {occasions.find((o) => o.value === form.data.event_type)?.label}
                                        {form.data.event_date ? ` · ${form.data.event_date}` : ''}
                                    </dd>
                                </>
                            )}
                            <dt className="text-ink-soft">{t('wizard.sharing')}</dt>
                            <dd>
                                {t(`wizard.visibility_${form.data.visibility}`)}
                                {shared && form.data.link_can_add ? ` · ${t('lists.anyone_can_add')}` : ''}
                                {shared && form.data.share_with.length > 0
                                    ? ` · ${t('wizard.shared_with_count', { count: String(form.data.share_with.length) })}`
                                    : ''}
                            </dd>
                        </dl>

                        <p className="mt-3 text-sm text-ink-soft">{t('wizard.after')}</p>

                        {Object.keys(form.errors).length > 0 && (
                            <p className="mt-3 text-sm text-red-700">{Object.values(form.errors)[0]}</p>
                        )}
                    </div>
                )}
            </div>

            <div className="mt-6 flex flex-wrap items-center justify-between gap-3">
                <button
                    type="button"
                    onClick={back}
                    disabled={index === 0}
                    className="text-sm text-ink-soft underline disabled:invisible"
                >
                    ← {t('wizard.back')}
                </button>

                {step !== 'done' ? (
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
                        {t('lists.create')}
                    </button>
                ) : (
                    <SignInLink
                        hint={t('wizard.sign_in_hint')}
                        onNavigate={remember}
                        className="rounded-lg bg-accent px-4 py-2 text-sm font-medium text-white hover:bg-accent-dark"
                    >
                        {t('wizard.sign_in_and_create')}
                    </SignInLink>
                )}
            </div>
        </section>
    )
}
