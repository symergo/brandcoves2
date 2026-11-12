import { Head, Link, router, useForm, usePage } from '@inertiajs/react'
import { useState, type ReactNode } from 'react'
import { buttonClasses, fieldClasses } from '../../Components/Button'
import InfoTip from '../../Components/InfoTip'
import { useConfirm } from '../../Components/Modal'
import PageHeader from '../../Components/PageHeader'
import SantaBadge from '../../Components/SantaBadge'
import { chipClasses, Countdown, Progress } from '../../Components/SantaGame'
import ShareRow from '../../Components/ShareRow'
import { formatBudget, formatDay, type Cents, type SharedProps } from '../../types'
import { useTranslations } from '../../useTranslations'

interface Member {
    id: number
    name: string
    joined: boolean
    hasList: boolean
    done: boolean
    /** Ids they may not draw; only filled for the organiser. */
    excludes: number[]
    /* No giftee, ever. See below. */
}

interface Props {
    group: {
        id: string
        title: string
        budgetMin: Cents | null
        budgetMax: Cents | null
        exchangeDate: string | null
        theme: string | null
        drawn: boolean
        inviteUrl: string
        drawAt: string | null
        drawWhenCount: number | null
        exchangePassed: boolean
        progress: { members: number; ready: number; done: number }
        guesses: { made: number; right: number } | null
    }
    isOrganiser: boolean
    members: Member[]
    me: {
        joinToken: string
        name: string
        done: boolean
        hasList: boolean
        /** The list this member attached, if any. */
        wishlistId: string | null
        revealed: boolean
        giftee: { name: string } | null
    } | null
    /** The lists this person could attach: their own, about themselves. */
    myLists: { id: string; title: string }[]
}

/**
 * The group.
 *
 * Deliberately aggregate. The organiser sees who is in and how many have
 * finished shopping, and never who drew whom — v1 let the organiser read the
 * pairings outright, which quietly makes one player a spectator of everyone
 * else's game.
 */
export default function SantaGroup({ group, isOrganiser, members, me, myLists }: Props) {
    const { market } = usePage<SharedProps>().props
    const { t } = useTranslations()
    // The site's own "are you sure?" (Modal.tsx), where `window.confirm()` was.
    const [confirm, confirmDialog] = useConfirm()
    const base = `/${market.key}/santa/${group.id}`
    const joined = members.filter((m) => m.joined).length
    // The organiser before the draw works through three numbered steps;
    // everybody else, and everybody afterwards, reads the same blocks unnumbered.
    const steps = isOrganiser && !group.drawn

    const invite = !group.exchangePassed && (
        <ShareRow
            url={group.inviteUrl}
            text={t('santa.invite_text', { title: group.title })}
            label={group.drawn ? t('santa.invite_others') : t('santa.invite')}
            hint={t('santa.invite_hint')}
        />
    )

    return (
        <>
            <Head title={group.title}>
                <meta name="robots" content="noindex, nofollow" />
            </Head>

            {confirmDialog}
            <PageHeader
                className="max-w-3xl"
                size="md"
                back={{ href: `/${market.key}/santa`, label: t('santa.title') }}
                title={group.title}
                beside={<SantaBadge />}
            >
                <p className="mt-2 text-sm text-ink-soft">
                    {[
                        group.budgetMax !== null ? `${t('santa.budget')}: ${formatBudget(group.budgetMax, market)}` : null,
                        group.exchangeDate ? formatDay(group.exchangeDate, market, { year: 'auto' }) : null,
                        group.theme || null,
                    ]
                        .filter(Boolean)
                        .join(' · ')}
                </p>

                {/* Where the group stands, in one plain line, before anything else. */}
                <p className="mt-1 text-sm text-ink-soft">
                    {group.drawn ? t('santa.hub.status_drawn') : t('santa.hub.status_open')}
                    {'. '}
                    <Countdown drawn={group.drawn} drawAt={group.drawAt} exchangeDate={group.exchangeDate} />
                </p>
            </PageHeader>

            <div className="max-w-3xl">
                {/*
                  Mine: the way to my own page, and my list. Above the steps,
                  because for every member except the organiser it is the only
                  thing on this page that is theirs to do.
                */}
                {me && (
                    <section className="mt-6 rounded-card border border-line bg-card p-5" aria-labelledby="santa-mine">
                        <h2 id="santa-mine" className="font-semibold">
                            {t('santa.group.for_you')}
                        </h2>

                        {/* No name here until they turned their card on their own page. */}
                        <p className="mt-1 text-sm text-ink-soft">
                            {group.drawn && me.giftee
                                ? me.revealed
                                    ? t('santa.you_have', { name: me.giftee.name })
                                    : t('santa.reveal_title')
                                : t('santa.group.mine_before')}
                        </p>
                        <Link
                            href={`${base}/me/${me.joinToken}`}
                            className={buttonClasses(group.drawn ? 'primary' : 'secondary', 'md', 'mt-3')}
                        >
                            {group.drawn && me.giftee && !me.revealed ? t('santa.email_reveal_cta') : t('santa.mail.open_mine')}
                        </Link>

                        {/* My own list, attached from here; the same endpoint as "Use this list" on a list. */}
                        {myLists.length > 0 ? (
                            <label className="mt-4 block text-sm">
                                <span className="font-medium">
                                    {t('santa.your_list')}
                                    <InfoTip className="ml-1">{t('santa.your_list_hint')}</InfoTip>
                                </span>
                                <select
                                    value={me.wishlistId ?? ''}
                                    onChange={(e) =>
                                        router.post(`${base}/list`, { wishlist_id: e.target.value === '' ? null : e.target.value }, { preserveScroll: true })
                                    }
                                    className={fieldClasses('sm:w-auto sm:min-w-64')}
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
                            <p className="mt-4 text-sm text-ink-soft">
                                {t('santa.build_yours_hint')}{' '}
                                <Link href={`/${market.key}/lists?new=mine`} className="underline hover:text-ink">
                                    {t('santa.build_yours')}
                                </Link>
                            </p>
                        )}
                    </section>
                )}

                {/* 1. Getting people in: the link, or typed in by the organiser. */}
                {(invite || isOrganiser) && !group.exchangePassed && (
                    <section className="mt-10" aria-labelledby="santa-invite">
                        <StepTitle id="santa-invite" step={steps ? 1 : null}>
                            {group.drawn ? t('santa.invite_others') : t('santa.group.step_invite')}
                        </StepTitle>
                        <div className="mt-3 rounded-card border border-line bg-card p-5">
                            {invite}
                            {isOrganiser && <AddMember groupId={group.id} drawn={group.drawn} />}
                        </div>
                    </section>
                )}

                {/* 2. Who is in, and what each of them still owes the group. Never who drew whom. */}
                <section className="mt-10" aria-labelledby="santa-members">
                    <StepTitle id="santa-members" step={steps ? 2 : null}>
                        {t('santa.group.step_members', { count: String(members.length) })}
                    </StepTitle>

                    <Progress drawn={group.drawn} progress={group.progress} />

                    {group.guesses && group.guesses.made > 0 && (
                        <p className="mt-2 text-sm text-ink-soft">
                            {t('santa.guesses_stat', { right: String(group.guesses.right), made: String(group.guesses.made) })}
                        </p>
                    )}

                    <ul className="mt-4 divide-y divide-line overflow-hidden rounded-card border border-line bg-card">
                        {members.map((member) => (
                            <li key={member.id} className="flex flex-wrap items-center gap-x-4 gap-y-2 p-4">
                                <span className="min-w-0 flex-1 text-sm font-medium">{member.name}</span>

                                {/* Aggregate facts only, as plain words: in or not, something to go on, finished. */}
                                <span className="text-sm text-ink-soft">
                                    {[
                                        member.joined ? null : t('santa.invited'),
                                        member.hasList ? t('santa.group.has_tips') : t('santa.group.no_tips'),
                                        member.done ? t('santa.group.bought') : null,
                                    ]
                                        .filter(Boolean)
                                        .join(' · ')}
                                </span>

                                {/* Repairing the draw, organiser only. Neither control reveals an assignment. */}
                                {isOrganiser && (
                                    <span className="flex items-center gap-3">
                                        {group.drawn && (
                                            <button
                                                type="button"
                                                onClick={async () => {
                                                    if (
                                                        await confirm({
                                                            message: t('santa.redraw_confirm', { name: member.name }),
                                                            confirmLabel: t('santa.redraw'),
                                                            danger: true,
                                                        })
                                                    ) {
                                                        router.post(`${base}/members/${member.id}/redraw`, {}, { preserveScroll: true })
                                                    }
                                                }}
                                                className="text-xs text-ink-soft underline hover:text-ink"
                                            >
                                                {t('santa.redraw')}
                                            </button>
                                        )}

                                        <button
                                            type="button"
                                            onClick={async () => {
                                                const warning = group.drawn
                                                    ? t('santa.remove_confirm_drawn', { name: member.name })
                                                    : t('santa.remove_confirm', { name: member.name })

                                                if (await confirm({ message: warning, confirmLabel: t('santa.remove_member'), danger: true })) {
                                                    router.delete(`${base}/members/${member.id}`, { preserveScroll: true })
                                                }
                                            }}
                                            className="text-xs text-ink-soft underline hover:text-accent"
                                        >
                                            {t('santa.remove_member')}
                                        </button>
                                    </span>
                                )}
                            </li>
                        ))}
                    </ul>

                    {isOrganiser && !group.drawn && members.length > 1 && <Couples groupId={group.id} members={members} />}
                </section>

                {/* 3. The draw: the one button this page exists for, with what it does said beside it. */}
                {steps && (
                    <section className="mt-10" aria-labelledby="santa-draw">
                        <StepTitle id="santa-draw" step={3}>
                            {t('santa.group.step_draw')}
                        </StepTitle>
                        <div className="mt-3 rounded-card border border-line bg-card p-5">
                            <p className="text-sm text-ink-soft">{t('santa.group.draw_hint')}</p>
                            <div className="mt-4 flex flex-wrap items-center gap-3">
                                <button
                                    type="button"
                                    onClick={async () => {
                                        if (await confirm({ message: t('santa.draw_confirm', { count: String(joined) }), confirmLabel: t('santa.draw') })) {
                                            router.post(`${base}/draw`)
                                        }
                                    }}
                                    disabled={joined < 2}
                                    className={buttonClasses('primary', 'lg')}
                                >
                                    {t('santa.draw')}
                                </button>
                                {/* Says why the button is disabled, rather than a button that simply does nothing. */}
                                {joined < 2 && <span className="text-sm text-ink-soft">{t('santa.draw_needs_two')}</span>}
                            </div>
                            <AutoDraw group={group} />
                        </div>
                    </section>
                )}

                {/* The same people, a year on: one press, from the organiser. */}
                {isOrganiser && group.drawn && (
                    <section className="mt-10 rounded-card border border-line bg-card p-5">
                        <h2 className="font-semibold">{t('santa.again')}</h2>
                        <p className="mt-1 text-sm text-ink-soft">{t('santa.again_hint')}</p>
                        <button type="button" onClick={() => router.post(`${base}/again`)} className={buttonClasses('secondary', 'md', 'mt-3')}>
                            {t('santa.again')}
                        </button>
                    </section>
                )}

                {/*
                  Calling it off, small and last. The confirmation is worded
                  differently once a draw has happened: by then people hold an
                  assignment and may have shopped, and nothing here tells them.
                */}
                {isOrganiser && (
                    <p className="mt-12 border-t border-line pt-6">
                        <button
                            type="button"
                            onClick={async () => {
                                const warning = group.drawn
                                    ? t('santa.delete_confirm_drawn', { title: group.title })
                                    : t('santa.delete_confirm', { title: group.title })

                                if (await confirm({ message: warning, confirmLabel: t('santa.delete'), danger: true })) {
                                    router.delete(base)
                                }
                            }}
                            className="text-sm text-ink-soft underline hover:text-accent"
                        >
                            {t('santa.delete')}
                        </button>
                    </p>
                )}
            </div>
        </>
    )
}

/** A section heading, numbered while the organiser is still setting the group up. */
function StepTitle({ id, step, children }: { id: string; step: number | null; children: ReactNode }) {
    return (
        <h2 id={id} className="text-lg font-semibold">
            {step !== null && `${step}. `}
            {children}
        </h2>
    )
}

/**
 * Somebody typed in by the organiser (owner, 2026-11-12): a name and an
 * address, for the people who will never open a link in a group chat. They
 * are in at once and get a mail with their own page. After the draw the same
 * form slots them into the chain, exactly as the invite link does.
 */
function AddMember({ groupId, drawn }: { groupId: string; drawn: boolean }) {
    const { market } = usePage<SharedProps>().props
    const { t } = useTranslations()
    const form = useForm({ display_name: '', email: '' })
    const field = fieldClasses()

    return (
        <form
            className="mt-5 border-t border-line pt-5"
            onSubmit={(e) => {
                e.preventDefault()
                form.post(`/${market.key}/santa/${groupId}/members`, { preserveScroll: true, onSuccess: () => form.reset() })
            }}
        >
            <p className="text-sm font-medium">{t('santa.group.add_title')}</p>
            <p className="mt-1 text-sm text-ink-soft">{drawn ? t('santa.late_note') : t('santa.group.add_hint')}</p>
            <div className="mt-3 grid gap-3 sm:grid-cols-[1fr_1fr_auto] sm:items-end">
                <label className="block">
                    <span className="text-sm">{t('santa.group.add_name')}</span>
                    <input
                        required
                        maxLength={80}
                        value={form.data.display_name}
                        onChange={(e) => form.setData('display_name', e.target.value)}
                        className={field}
                    />
                </label>
                <label className="block">
                    <span className="text-sm">{t('santa.group.add_email')}</span>
                    <input
                        type="email"
                        required
                        maxLength={190}
                        value={form.data.email}
                        onChange={(e) => form.setData('email', e.target.value)}
                        className={field}
                    />
                </label>
                <button disabled={form.processing} className={buttonClasses('secondary')}>
                    {t('santa.group.add_button')}
                </button>
            </div>
            {Object.entries(form.errors).map(([name, message]) => (
                <p key={name} className="mt-2 text-sm text-danger" role="alert">
                    {message}
                </p>
            ))}
        </form>
    )
}

/**
 * Who may not draw whom, set by tapping two names (owner, 2026-11-12). It was
 * a line each member typed on joining, with other people's addresses in it.
 * Symmetric: a couple is two people who may not draw each other.
 */
function Couples({ groupId, members }: { groupId: string; members: Member[] }) {
    const { market } = usePage<SharedProps>().props
    const { t } = useTranslations()
    const [picked, setPicked] = useState<number | null>(null)
    const first = members.find((m) => m.id === picked) ?? null
    const pairs = members.reduce((count, m) => count + m.excludes.length, 0)

    return (
        <details className="mt-4 rounded-card border border-line bg-card p-5" open={pairs > 0}>
            <summary className="cursor-pointer text-sm font-medium">{t('santa.couples_title')}</summary>
            <p className="mt-2 text-sm text-ink-soft">{t('santa.couples_hint')}</p>
            <p className="mt-3 text-sm font-medium">{first ? t('santa.couples_for', { name: first.name }) : t('santa.couples_pick')}</p>
            <ul className="mt-2 flex flex-wrap gap-2">
                {members.map((member) => {
                    const isFirst = member.id === picked
                    const apart = first !== null && first.excludes.includes(member.id)

                    return (
                        <li key={member.id}>
                            <button
                                type="button"
                                aria-pressed={isFirst || apart}
                                onClick={() => {
                                    if (first === null || isFirst) {
                                        setPicked(isFirst ? null : member.id)

                                        return
                                    }

                                    router.post(
                                        `/${market.key}/santa/${groupId}/exclusions`,
                                        { a: first.id, b: member.id },
                                        { preserveScroll: true },
                                    )
                                }}
                                className={`${chipClasses(isFirst || apart)} ${apart ? 'line-through' : ''} ${isFirst ? 'ring-2 ring-ink ring-offset-2' : ''}`}
                            >
                                {member.name}
                            </button>
                        </li>
                    )
                })}
            </ul>
        </details>
    )
}

/** A moment typed in a `datetime-local` field wants "2026-12-01T20:00", in the visitor's own time. */
function localMoment(iso: string | null): string {
    if (!iso) return ''

    const d = new Date(iso)
    const pad = (v: number) => String(v).padStart(2, '0')

    return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}T${pad(d.getHours())}:${pad(d.getMinutes())}`
}

/** The draw without the organiser coming back: at a moment, or once enough are in. */
function AutoDraw({ group }: { group: Props['group'] }) {
    const { market } = usePage<SharedProps>().props
    const { t } = useTranslations()
    const form = useForm({ draw_at: localMoment(group.drawAt), draw_when_count: group.drawWhenCount === null ? '' : String(group.drawWhenCount) })
    const field = fieldClasses()

    return (
        <details className="mt-5 border-t border-line pt-4" open={group.drawAt !== null || group.drawWhenCount !== null}>
            <summary className="cursor-pointer text-sm font-medium">{t('santa.draw_auto')}</summary>
            <p className="mt-2 text-sm text-ink-soft">{t('santa.draw_auto_hint')}</p>

            {group.drawAt && (
                <p className="mt-2 text-sm font-medium">
                    {t('santa.draw_at_set', {
                        when: new Date(group.drawAt).toLocaleString(market.hrefLang, { dateStyle: 'long', timeStyle: 'short' }),
                    })}
                </p>
            )}
            {group.drawWhenCount !== null && (
                <p className="mt-2 text-sm font-medium">{t('santa.draw_when_set', { count: String(group.drawWhenCount) })}</p>
            )}

            <form
                className="mt-3 grid gap-4 sm:grid-cols-2"
                onSubmit={(e) => {
                    e.preventDefault()
                    form.post(`/${market.key}/santa/${group.id}/settings`, { preserveScroll: true })
                }}
            >
                <label className="block">
                    <span className="text-sm font-medium">{t('santa.draw_at_label')}</span>
                    <input type="datetime-local" value={form.data.draw_at} onChange={(e) => form.setData('draw_at', e.target.value)} className={field} />
                </label>
                <label className="block">
                    <span className="text-sm font-medium">{t('santa.draw_when_label')}</span>
                    <input
                        type="number"
                        min={3}
                        max={200}
                        value={form.data.draw_when_count}
                        onChange={(e) => form.setData('draw_when_count', e.target.value)}
                        className={field}
                    />
                </label>
                {Object.entries(form.errors).map(([name, message]) => (
                    <p key={name} className="text-sm text-danger sm:col-span-2" role="alert">
                        {message}
                    </p>
                ))}
                <div className="sm:col-span-2">
                    <button disabled={form.processing} className={buttonClasses('secondary')}>
                        {t('santa.save')}
                    </button>
                </div>
            </form>
        </details>
    )
}
