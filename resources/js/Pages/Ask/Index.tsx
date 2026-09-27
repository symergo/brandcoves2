import { Head, Link, useForm, usePage } from '@inertiajs/react'
import { useEffect, useState } from 'react'
import { takeAskBrief } from '../../askBrief'
import InfoTip from '../../Components/InfoTip'
import CoveIcon from '../../Components/CoveIcon'
import type { SharedProps } from '../../types'
import { formatBudget } from '../../types'
import { useTranslations } from '../../useTranslations'
import type { ListKind } from '../../Components/ListKindBadge'
import ListName from '../../Components/ListName'
import SignInLink from '../../Components/SignInLink'

interface Question {
    id: number
    title: string
    budget: number | null
    /** Already labels, in the reader's language. */
    tags: string[]
    answers: number
    author: string | null
    status: string
    askedAt: string
    url: string
}

interface Option {
    value: string
    label: string
}

interface Props {
    questions: Question[]
    /** Your own posts that are not on the board yet. Empty for a stranger. */
    mine: Question[]
    canAsk: boolean
    options: { interests: Option[]; vibes: Option[]; values: string[] }
    /**
     * The form filled in from what is known about who it is for (Find a gift,
     * or a list page). Null when nothing is. Never a name; see AskPrefill.
     */
    prefill?: Prefill | null
    /** Open the form at once: arrived from an "ask your question" button. */
    open?: boolean
    /** A published question also goes to this asker's people. */
    sendsToPeople?: boolean
}

interface Prefill {
    title: string
    interests: string[]
    vibe: string | null
    values: string[]
    age_band: string
    budget_max: string
    occasion: string
    /** The list it is asked from: answers get "save to" this list. */
    list: { id: string; title: string; kind?: ListKind } | null
}

/**
 * The board.
 *
 * Every other way into this site assumes you can already describe what you
 * want: search needs a noun, Find a gift needs six answers about a person,
 * a Cove is a theme somebody else picked. "She's turning forty and has
 * everything" is not a query — it is a question for a person, and this is the
 * only surface that takes one.
 *
 * ## The form is one required field and a lot of optional ones
 *
 * The question is the whole of what is required. Somebody who types one
 * sentence and presses Ask gets a question on the board.
 *
 * The rest — what they are into, how it should feel, a budget — is an
 * accelerator for people who want to be more specific, and it is folded away
 * until they ask for it. Answers are markedly better with *coffee, practical,
 * under €40* attached, and most people will tick that if the ticking is free;
 * almost nobody will fill in a nine-field form to ask a question.
 *
 * The vocabulary is Find a gift's own, so a question and a brief describe a
 * person the same way and an answerer can search from one without translating.
 *
 * ## Your held questions are shown to you
 *
 * A post is read before it appears, which is right and also the exact moment
 * the feature looks broken: you press Ask, the board reloads, and your question
 * is not there. `mine` carries your own unpublished posts back so the page can
 * say "we have it". It is not a disclosure — it is your own writing.
 */
export default function AskIndex({
    questions,
    mine,
    canAsk,
    options,
    prefill = null,
    open = false,
    sendsToPeople = false,
}: Props) {
    const { market } = usePage<SharedProps>().props
    const { t, tRich, n } = useTranslations()
    const base = `/${market.key}`

    /*
      Open straight away when somebody came here to ask: from Find a gift's
      fourth way, a list page, or an "ask your question" button.
    */
    const [asking, setAsking] = useState(canAsk && (open || prefill !== null))
    // The optional half, folded away. A form that opens with nine fields is a
    // form people close; unfolded when something in it was filled in for them,
    // so they can see (and clear) what will be posted.
    const [detailed, setDetailed] = useState(
        prefill !== null &&
            (prefill.interests.length > 0 ||
                prefill.vibe !== null ||
                prefill.values.length > 0 ||
                prefill.age_band !== '' ||
                prefill.budget_max !== '' ||
                prefill.occasion !== ''),
    )

    const form = useForm<{
        title: string
        body: string
        budget_max: string
        interests: string[]
        vibe: string | null
        values: string[]
        age_band: string
        occasion: string
        list_id: string | null
    }>({
        title: prefill?.title ?? '',
        body: '',
        budget_max: prefill?.budget_max ?? '',
        interests: prefill?.interests ?? [],
        vibe: prefill?.vibe ?? null,
        values: prefill?.values ?? [],
        age_band: prefill?.age_band ?? '',
        occasion: prefill?.occasion ?? '',
        list_id: prefill?.list?.id ?? null,
    })

    /*
      What Find a gift learned in this tab (the interests ticked, the budget
      typed), handed over through sessionStorage rather than the address; see
      askBrief.ts. Read once, after the first render, so the server's page and
      the browser's first paint agree.
    */
    useEffect(() => {
        if (!canAsk || !window.location.search.includes('from=gift')) return

        const brief = takeAskBrief()

        if (brief === null) return

        const known = new Set(options.interests.map((o) => o.value))
        const interests = (brief.interests ?? []).filter((v) => known.has(v))
        const values = (brief.values ?? []).filter((v) => options.values.includes(v))

        form.setData((data) => ({
            ...data,
            interests: interests.length > 0 ? interests : data.interests,
            vibe: brief.vibe ?? data.vibe,
            values: values.length > 0 ? values : data.values,
            budget_max: brief.budget_max || data.budget_max,
            age_band: brief.age_band || data.age_band,
        }))

        if (interests.length > 0 || brief.vibe || values.length > 0 || brief.budget_max || brief.age_band) {
            setDetailed(true)
        }
        // Once, on arrival.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [])

    function toggle(field: 'interests' | 'values', value: string) {
        const list = form.data[field]

        form.setData(field, list.includes(value) ? list.filter((v) => v !== value) : [...list, value])
    }

    function submit(event: React.FormEvent) {
        event.preventDefault()

        form.transform((data) => ({
            ...data,
            // Euros in the box, and this endpoint takes euros and multiplies —
            // only the comma half our markets type needs normalising.
            budget_max: data.budget_max.trim() === '' ? null : data.budget_max.replace(',', '.'),
        }))

        form.post(`${base}/ask`, {
            onSuccess: () => {
                form.reset()
                setAsking(false)
                setDetailed(false)
            },
        })
    }

    function chip(active: boolean): string {
        return `rounded-full border px-3 py-1.5 text-sm transition ${
            active ? 'border-accent bg-accent/10 text-accent' : 'border-line hover:border-ink'
        }`
    }

    function card(question: Question, held = false) {
        return (
            <li key={question.id}>
                <Link
                    href={question.url}
                    className={`flex h-full flex-col rounded-card border bg-card p-5 transition hover:border-ink ${
                        held ? 'border-amber/40' : 'border-line'
                    }`}
                >
                    <h3 className="font-medium">{question.title}</h3>

                    {/*
                      What they said about the person, if anything. Chips rather
                      than a sentence: they are a handful of one-word facts, and
                      the whole reason for collecting them is that they can be
                      taken in without reading.
                    */}
                    {question.tags.length > 0 && (
                        <ul className="mt-3 flex flex-wrap gap-1.5">
                            {question.tags.map((tag) => (
                                <li
                                    key={tag}
                                    className="rounded-full bg-line/60 px-2 py-0.5 text-2xs text-ink-soft"
                                >
                                    {tag}
                                </li>
                            ))}
                        </ul>
                    )}

                    <p className="mt-3 flex flex-wrap items-center gap-x-2 gap-y-1 text-sm text-ink-soft">
                        {/* The count is the signal that decides whether this is
                            worth opening, so it leads. */}
                        <span className={question.answers > 0 ? 'font-medium text-accent' : undefined}>
                            {question.answers === 0
                                ? t('ask.no_answers')
                                : question.answers === 1
                                  ? t('ask.one_answer')
                                  : t('ask.answers', { count: n(question.answers) })}
                        </span>

                        {question.budget !== null && (
                            <>
                                <span aria-hidden>·</span>
                                <span>{t('ask.budget_up_to', { amount: formatBudget(question.budget, market) })}</span>
                            </>
                        )}

                        {question.author && (
                            <>
                                <span aria-hidden>·</span>
                                <span>{t('ask.asked_by', { name: question.author })}</span>
                            </>
                        )}
                    </p>

                    {held && (
                        <p className="mt-3 rounded-lg bg-amber/10 px-3 py-2 text-xs text-ink-soft">
                            {question.status === 'rejected'
                                ? t('ask.rejected_notice')
                                : t('ask.pending_notice')}
                        </p>
                    )}
                </Link>
            </li>
        )
    }

    return (
        <>
            <Head title={t('ask.seo_title')} />

            <header className="max-w-3xl">
                {/*
                  The mark beside the name. Two speech bubbles, the second
                  answering the first — the same drawing the Discover menu and
                  the hub card use, so arriving here from either one lands on
                  something recognisable.
                */}
                <div className="flex items-center gap-3">
                    <span className="flex h-12 w-12 shrink-0 items-center justify-center rounded-xl bg-accent/10 text-accent">
                        <CoveIcon name="ask" className="h-7 w-7" />
                    </span>
                    <h1 className="text-2xl sm:text-3xl font-semibold tracking-tight">{t('ask.title')}</h1>
                </div>

                <p className="mt-3 text-lg text-ink-soft">{t('ask.intro')}</p>
            </header>

            <div className="mt-6">
                {canAsk ? (
                    !asking && (
                        <button
                            type="button"
                            onClick={() => setAsking(true)}
                            className="rounded-lg bg-accent px-4 py-2 font-medium text-white hover:bg-accent-dark"
                        >
                            {t('ask.ask_cta')}
                        </button>
                    )
                ) : (
                    <p className="max-w-3xl rounded-card border border-line bg-card p-4 text-sm text-ink-soft">
                        {t('ask.sign_in_to_ask')}{' '}
                        <SignInLink hint={t('ask.sign_in_to_ask')} className="underline">
                            {t('nav.sign_in')}
                        </SignInLink>
                    </p>
                )}
            </div>

            {asking && (
                <form onSubmit={submit} className="mt-6 space-y-5 rounded-card border border-line bg-card p-6 lg:p-8">
                    <h2 className="font-medium">{t('ask.ask_heading')}</h2>

                    {/*
                      Asked from a list: say so, because it is why the answers
                      will have a "save to" that list on them. Only the asker
                      ever sees which list; the question does not name it.
                    */}
                    {prefill?.list && (
                        <p className="flex flex-wrap items-center gap-1 text-sm text-ink-soft">
                            <span>{tRich('ask.from_list', { list: <ListName name={prefill.list.title} kind={prefill.list.kind} /> })}</span>
                            <InfoTip>{t('ask.from_list_hint')}</InfoTip>
                        </p>
                    )}

                    {/* The question, full width — it is the only required field
                        and the one people came to write. */}
                    <label className="block text-sm font-medium">
                        {t('ask.question_label')}
                        <input
                            required
                            autoFocus
                            minLength={10}
                            maxLength={160}
                            value={form.data.title}
                            onChange={(e) => form.setData('title', e.target.value)}
                            placeholder={t('ask.question_placeholder')}
                            className="mt-1 w-full rounded-lg border border-line bg-cream px-3 py-2 font-normal"
                        />
                    </label>
                    {form.errors.title && <p className="text-sm text-danger">{form.errors.title}</p>}

                    <label className="block text-sm font-medium">
                        {t('ask.detail_label')}
                        <textarea
                            rows={4}
                            maxLength={2000}
                            value={form.data.body}
                            onChange={(e) => form.setData('body', e.target.value)}
                            placeholder={t('ask.detail_placeholder')}
                            className="mt-1 w-full rounded-lg border border-line bg-cream px-3 py-2 font-normal"
                        />
                    </label>

                    {/*
                      Everything below is optional and folded away until asked
                      for. Opening the form on nine fields is how a question
                      board gets no questions.
                    */}
                    {!detailed ? (
                        <button
                            type="button"
                            onClick={() => setDetailed(true)}
                            className="text-sm text-accent underline hover:text-accent-dark"
                        >
                            {t('ask.more_about_them')}
                        </button>
                    ) : (
                        <div className="space-y-5 border-t border-line pt-5">
                            <p className="text-xs text-ink-soft">{t('ask.more_hint')}</p>

                            <fieldset>
                                <legend className="text-sm font-medium">{t('gift.step_interests')}</legend>
                                <div className="mt-2 flex flex-wrap gap-2">
                                    {options.interests.map((interest) => (
                                        <button
                                            key={interest.value}
                                            type="button"
                                            aria-pressed={form.data.interests.includes(interest.value)}
                                            onClick={() => toggle('interests', interest.value)}
                                            className={chip(form.data.interests.includes(interest.value))}
                                        >
                                            {interest.label}
                                        </button>
                                    ))}
                                </div>
                            </fieldset>

                            <div className="grid gap-5 sm:grid-cols-2">
                                <fieldset>
                                    <legend className="text-sm font-medium">{t('gift.step_vibe')}</legend>
                                    <div className="mt-2 flex flex-wrap gap-2">
                                        {options.vibes.map((vibe) => (
                                            <button
                                                key={vibe.value}
                                                type="button"
                                                aria-pressed={form.data.vibe === vibe.value}
                                                // Pressing the chosen one again
                                                // clears it: there is no "none"
                                                // chip, and a choice you cannot
                                                // take back is a trap.
                                                onClick={() =>
                                                    form.setData('vibe', form.data.vibe === vibe.value ? null : vibe.value)
                                                }
                                                className={chip(form.data.vibe === vibe.value)}
                                            >
                                                {vibe.label}
                                            </button>
                                        ))}
                                    </div>
                                </fieldset>

                                <fieldset>
                                    <legend className="text-sm font-medium">{t('gift.step_values')}</legend>
                                    <div className="mt-2 flex flex-wrap gap-2">
                                        {options.values.map((value) => (
                                            <button
                                                key={value}
                                                type="button"
                                                aria-pressed={form.data.values.includes(value)}
                                                onClick={() => toggle('values', value)}
                                                className={chip(form.data.values.includes(value))}
                                            >
                                                {t(`gift.values.${value}`)}
                                            </button>
                                        ))}
                                    </div>
                                </fieldset>
                            </div>

                            <div className="grid gap-5 sm:grid-cols-3">
                                <label className="block text-sm font-medium">
                                    {t('ask.occasion_label')}
                                    <input
                                        maxLength={40}
                                        value={form.data.occasion}
                                        onChange={(e) => form.setData('occasion', e.target.value)}
                                        placeholder={t('ask.occasion_placeholder')}
                                        className="mt-1 w-full rounded-lg border border-line bg-cream px-3 py-2 text-sm font-normal"
                                    />
                                </label>

                                <label className="block text-sm font-medium">
                                    {t('ask.age_label')}
                                    <input
                                        maxLength={20}
                                        value={form.data.age_band}
                                        onChange={(e) => form.setData('age_band', e.target.value)}
                                        placeholder={t('ask.age_placeholder')}
                                        className="mt-1 w-full rounded-lg border border-line bg-cream px-3 py-2 text-sm font-normal"
                                    />
                                </label>

                                <label className="block text-sm font-medium">
                                    {t('ask.budget_label')}
                                    <input
                                        inputMode="decimal"
                                        value={form.data.budget_max}
                                        onChange={(e) => form.setData('budget_max', e.target.value)}
                                        className="mt-1 w-full rounded-lg border border-line bg-cream px-3 py-2 text-sm font-normal"
                                    />
                                    <span className="mt-1 block text-xs font-normal text-ink-soft">
                                        {t('ask.budget_hint')}
                                    </span>
                                </label>
                            </div>
                            {form.errors.budget_max && (
                                <p className="text-sm text-danger">{form.errors.budget_max}</p>
                            )}
                        </div>
                    )}

                    <div className="flex flex-wrap items-center gap-2 border-t border-line pt-5">
                        <button
                            type="submit"
                            disabled={form.processing}
                            className="rounded-lg bg-accent px-4 py-2 font-medium text-white disabled:opacity-50"
                        >
                            {t('ask.submit')}
                        </button>
                        <button
                            type="button"
                            onClick={() => setAsking(false)}
                            className="rounded-lg border border-line px-4 py-2 text-sm"
                        >
                            {t('ask.cancel')}
                        </button>
                        {/*
                          Where else it goes. One line, the detail behind the
                          (i): once published it is sent to your people, and
                          the switch is on the notifications page.
                        */}
                        {sendsToPeople && (
                            <span className="flex items-center gap-1 text-xs text-ink-soft sm:ml-auto">
                                {t('ask.people.form_note')}
                                <InfoTip>
                                    {t('ask.people.form_note_hint')}{' '}
                                    <Link href={`${base}/notifications`} className="underline">
                                        {t('notifications.title')}
                                    </Link>
                                </InfoTip>
                            </span>
                        )}
                    </div>
                </form>
            )}

            {mine.length > 0 && (
                <section className="mt-12">
                    <h2 className="text-lg font-medium">{t('ask.mine_heading')}</h2>
                    <ul className="mt-4 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                        {mine.map((question) => card(question, true))}
                    </ul>
                </section>
            )}

            <section className="mt-12">
                {questions.length === 0 ? (
                    <div className="rounded-card border border-line bg-card p-8 text-center">
                        <p className="font-medium">{t('ask.empty')}</p>
                        <p className="mt-1 text-sm text-ink-soft">{t('ask.empty_hint')}</p>
                    </div>
                ) : (
                    <ul className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                        {questions.map((question) => card(question))}
                    </ul>
                )}
            </section>
        </>
    )
}
