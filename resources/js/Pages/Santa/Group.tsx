import { Head, Link, router, useForm, usePage } from '@inertiajs/react'
import { useState } from 'react'
import { buttonClasses, fieldClasses } from '../../Components/Button'
import { useConfirm } from '../../Components/Modal'
import { chipClasses, Countdown, HintsForm, Progress } from '../../Components/SantaGame'
import ShareRow from '../../Components/ShareRow'
import GiftArt from '../../Components/GiftArt'
import ToolIcon from '../../Components/ToolIcon'
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
        interests: string[]
        hints: string | null
        giftee: { name: string } | null
    } | null
    /** The lists this person could attach: their own, about themselves. */
    myLists: { id: string; title: string }[]
    interestKeys: string[]
}

/**
 * The group.
 *
 * Deliberately aggregate. The organiser sees who is in and how many have
 * finished shopping, and never who drew whom — v1 let the organiser read the
 * pairings outright, which quietly makes one player a spectator of everyone
 * else's game.
 */
export default function SantaGroup({ group, isOrganiser, members, me, myLists, interestKeys }: Props) {
    const { market } = usePage<SharedProps>().props
    const { t } = useTranslations()
    // The site's own "are you sure?" (Modal.tsx), where `window.confirm()` was.
    const [confirm, confirmDialog] = useConfirm()
    const base = `/${market.key}/santa/${group.id}`
    const joined = members.filter((m) => m.joined).length

    return (
        <>
            <Head title={group.title}>
                <meta name="robots" content="noindex, nofollow" />
            </Head>

            {confirmDialog}

            {/*
              Two things on this page (third pass, 2026-11-12: "make it look
              simpler"): the people, and the draw. Getting people in belongs
              with the people, so the link and the "add somebody" row live in
              that card. Everything occasional (my list, couples, the automatic
              draw, deleting) is a line that opens, not a box.
            */}
            {/* The group's name and where it stands. No orange band behind it (owner, 2026-11-12). */}
            <div>
                <div className="mx-auto max-w-2xl">
                    <Link href={`/${market.key}/santa`} className="text-sm text-ink-soft hover:underline">
                        ← {t('santa.title')}
                    </Link>
                    <h1 className="mt-2 text-2xl font-semibold tracking-tight text-balance text-ink sm:text-3xl">{group.title}</h1>
                    <p className="mt-2 text-ink-soft">
                        {[
                            group.drawn ? t('santa.hub.status_drawn') : t('santa.hub.status_open'),
                            group.budgetMax !== null ? formatBudget(group.budgetMax, market) : null,
                            group.exchangeDate ? formatDay(group.exchangeDate, market, { year: 'auto' }) : null,
                            group.theme || null,
                        ]
                            .filter(Boolean)
                            .join(' · ')}
                    </p>
                    <p className="mt-1">
                        <Countdown drawn={group.drawn} drawAt={group.drawAt} exchangeDate={group.exchangeDate} />
                    </p>

                    {/*
                      Mine, once there is a name to turn: one row to my own
                      page. Before the draw that page was only a place to give
                      tips, so the tips moved into "Je verlanglijst / tips"
                      below and the row is gone (owner, 2026-11-12).
                    */}
                    {me && group.drawn && me.giftee && (
                        <Link
                            href={`${base}/me/${me.joinToken}`}
                            className="mt-6 flex items-center gap-4 rounded-card border border-line bg-card p-4 transition hover:border-ink"
                        >
                            <ToolIcon name="santa" className="h-9 w-9 shrink-0 text-accent" duo />
                            <span className="min-w-0 flex-1">
                                <span className="block font-medium">
                                    {me.revealed ? t('santa.you_have', { name: me.giftee.name }) : t('santa.reveal_title')}
                                </span>
                                <span className="block text-sm text-ink-soft">
                                    {me.revealed ? t('santa.mail.open_mine') : t('santa.reveal_tap')}
                                </span>
                            </span>
                            <span aria-hidden className="text-accent-dark">
                                →
                            </span>
                        </Link>
                    )}
                </div>
            </div>

            <div className="mx-auto max-w-2xl">
                {/* The people: who is in, and the two ways to get more of them in. */}
                <section className="mt-8" aria-labelledby="santa-members">
                    <h2 id="santa-members" className="text-lg font-semibold">
                        {t('santa.group.step_members', { count: String(members.length) })}
                    </h2>
                    <Progress drawn={group.drawn} progress={group.progress} />

                    {group.guesses && group.guesses.made > 0 && (
                        <p className="mt-1 text-sm text-ink-soft">
                            {t('santa.guesses_stat', { right: String(group.guesses.right), made: String(group.guesses.made) })}
                        </p>
                    )}

                    <div className="mt-3 overflow-hidden rounded-card border border-line bg-card">
                        <ul className="divide-y divide-line">
                            {members.map((member) => (
                                <li key={member.id} className="flex items-center gap-3 px-4 py-3">
                                    <span
                                        aria-hidden
                                        className="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-accent/10 text-sm font-semibold text-accent-dark"
                                    >
                                        {member.name.trim().charAt(0).toUpperCase()}
                                    </span>
                                    <span className="min-w-0 flex-1">
                                        <span className="block truncate font-medium">{member.name}</span>
                                        {/* Aggregate facts only: in or not, something to go on, finished. Never who drew whom. */}
                                        <span className="block text-sm text-ink-soft">
                                            {[
                                                member.joined ? null : t('santa.invited'),
                                                member.hasList ? t('santa.group.has_tips') : t('santa.group.no_tips'),
                                                member.done ? t('santa.group.bought') : null,
                                            ]
                                                .filter(Boolean)
                                                .join(' · ')}
                                        </span>
                                    </span>

                                    {/* Repairing the draw, organiser only. Neither control reveals an assignment. */}
                                    {isOrganiser && (
                                        <span className="flex shrink-0 items-center gap-3 text-sm">
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
                                                    className="text-ink-soft underline hover:text-ink"
                                                >
                                                    {t('santa.group.redraw_short')}
                                                </button>
                                            )}
                                            <button
                                                type="button"
                                                aria-label={`${t('santa.remove_member')}: ${member.name}`}
                                                title={t('santa.remove_member')}
                                                onClick={async () => {
                                                    const warning = group.drawn
                                                        ? t('santa.remove_confirm_drawn', { name: member.name })
                                                        : t('santa.remove_confirm', { name: member.name })

                                                    if (await confirm({ message: warning, confirmLabel: t('santa.remove_member'), danger: true })) {
                                                        router.delete(`${base}/members/${member.id}`, { preserveScroll: true })
                                                    }
                                                }}
                                                className="flex h-8 w-8 items-center justify-center rounded-full text-lg text-ink-soft hover:bg-cream hover:text-danger"
                                            >
                                                ×
                                            </button>
                                        </span>
                                    )}
                                </li>
                            ))}
                        </ul>

                        {isOrganiser && !group.exchangePassed && <AddMember groupId={group.id} />}
                    </div>

                    {!group.exchangePassed && (
                        <div className="mt-4">
                            <ShareRow
                                url={group.inviteUrl}
                                text={t('santa.invite_text', { title: group.title })}
                                label={group.drawn ? t('santa.invite_others') : t('santa.invite')}
                                hint={t('santa.invite_hint')}
                            />
                        </div>
                    )}
                </section>

                {/* The draw: the one red button, with what it does said under it. */}
                {isOrganiser && !group.drawn && (
                    <section className="mt-10 flex items-center gap-5 rounded-card bg-hero p-6 text-ink sm:p-8" aria-labelledby="santa-draw">
                        <div className="min-w-0 flex-1">
                            <h2 id="santa-draw" className="text-2xl font-semibold tracking-tight">
                                {t('santa.group.step_draw')}
                            </h2>
                            <p className="mt-2 text-ink">{t('santa.group.draw_hint')}</p>
                            <button
                                type="button"
                                onClick={async () => {
                                    if (await confirm({ message: t('santa.draw_confirm', { count: String(joined) }), confirmLabel: t('santa.draw') })) {
                                        router.post(`${base}/draw`)
                                    }
                                }}
                                disabled={joined < 2}
                                className={buttonClasses('primary', 'lg', 'mt-5 px-8')}
                            >
                                {t('santa.draw')} <span aria-hidden="true">→</span>
                            </button>
                            {/* Says why the button is disabled, rather than a button that simply does nothing. */}
                            {joined < 2 && <p className="mt-2 text-sm text-ink">{t('santa.draw_needs_two')}</p>}
                        </div>
                        <GiftArt variant="badge" className="hidden w-28 sm:block" />
                    </section>
                )}

                {/* Occasional things: each a line that opens. */}
                <div className="mt-8 divide-y divide-line border-y border-line">
                    {isOrganiser && !group.drawn && <AutoDraw group={group} />}
                    {isOrganiser && !group.drawn && members.length > 1 && <Couples groupId={group.id} members={members} />}

                    {me && (
                        <details className="py-3" open={!me.hasList && me.interests.length === 0 && !me.hints}>
                            <summary className="cursor-pointer font-medium">{t('santa.group.list_tips')}</summary>
                            <p className="mt-2 text-sm text-ink-soft">{t('santa.your_list_hint')}</p>
                            {myLists.length > 0 ? (
                                <select
                                    aria-label={t('santa.your_list')}
                                    value={me.wishlistId ?? ''}
                                    onChange={(e) =>
                                        router.post(`${base}/list`, { wishlist_id: e.target.value === '' ? null : e.target.value }, { preserveScroll: true })
                                    }
                                    className={fieldClasses('mt-2 sm:w-auto sm:min-w-64')}
                                >
                                    <option value="">{t('santa.no_list_option')}</option>
                                    {myLists.map((list) => (
                                        <option key={list.id} value={list.id}>
                                            {list.title}
                                        </option>
                                    ))}
                                </select>
                            ) : (
                                <Link href={`/${market.key}/lists?new=mine`} className="mt-2 inline-block text-sm underline hover:text-ink">
                                    {t('santa.build_yours')}
                                </Link>
                            )}

                            {/* Tips in a few taps, here rather than on a page of their own. */}
                            <HintsForm
                                bare
                                url={`${base}/me/${me.joinToken}/hints`}
                                keys={interestKeys}
                                interests={me.interests}
                                hints={me.hints}
                            />
                        </details>
                    )}

                    {/* The same people, a year on: one press, from the organiser. */}
                    {isOrganiser && group.drawn && (
                        <details className="py-3">
                            <summary className="cursor-pointer font-medium">{t('santa.again')}</summary>
                            <p className="mt-2 text-sm text-ink-soft">{t('santa.again_hint')}</p>
                            <button type="button" onClick={() => router.post(`${base}/again`)} className={buttonClasses('secondary', 'md', 'mt-3')}>
                                {t('santa.again')}
                            </button>
                        </details>
                    )}

                    {/*
                      Calling it off. Worded differently once a draw has
                      happened: by then people hold an assignment and may have
                      shopped, and nothing here tells them.
                    */}
                    {isOrganiser && (
                        <p className="py-3">
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
                                className="text-sm text-ink-soft underline hover:text-danger"
                            >
                                {t('santa.delete')}
                            </button>
                        </p>
                    )}
                </div>
            </div>
        </>
    )
}

/**
 * Somebody typed in by the organiser (owner, 2026-11-12): a name and an
 * address, for the people who will never open a link in a group chat. The last
 * row of the people's own card, so adding reads as "one more line in this
 * list". They are in at once and get a mail with their own page; after the
 * draw the same row slots them into the chain, as the invite link does.
 */
function AddMember({ groupId }: { groupId: string }) {
    const { market } = usePage<SharedProps>().props
    const { t } = useTranslations()
    const form = useForm({ display_name: '', email: '' })

    return (
        <form
            className="border-t border-line bg-cream/60 px-4 py-3"
            onSubmit={(e) => {
                e.preventDefault()
                form.post(`/${market.key}/santa/${groupId}/members`, { preserveScroll: true, onSuccess: () => form.reset() })
            }}
        >
            <div className="flex flex-wrap items-center gap-2">
                <input
                    required
                    maxLength={80}
                    value={form.data.display_name}
                    onChange={(e) => form.setData('display_name', e.target.value)}
                    placeholder={t('santa.group.add_name')}
                    aria-label={t('santa.group.add_name')}
                    className={fieldClasses('min-w-0 flex-1 basis-32 bg-card', { inline: true })}
                />
                <input
                    type="email"
                    required
                    maxLength={190}
                    value={form.data.email}
                    onChange={(e) => form.setData('email', e.target.value)}
                    placeholder={t('santa.group.add_email')}
                    aria-label={t('santa.group.add_email')}
                    className={fieldClasses('min-w-0 flex-1 basis-44 bg-card', { inline: true })}
                />
                <button disabled={form.processing} className={buttonClasses('secondary')}>
                    + {t('santa.group.add_button')}
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
        <details className="py-3" open={pairs > 0}>
            <summary className="cursor-pointer font-medium">{t('santa.couples_title')}</summary>
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
        <details className="py-3" open={group.drawAt !== null || group.drawWhenCount !== null}>
            <summary className="cursor-pointer font-medium">{t('santa.draw_auto')}</summary>
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
