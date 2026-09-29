import { Link, usePage } from '@inertiajs/react'
import { type ReactNode, useState } from 'react'
import { send } from '../http'
import type { Cents, SavingTo, SharedProps } from '../types'
import { formatPrice } from '../types'
import { useTranslations } from '../useTranslations'
import InfoTip from './InfoTip'
import ListName from './ListName'
import ToolIcon from './ToolIcon'
import CommunityCoveCards, { type CommunityCoveCard } from './CommunityCoveCards'
import NextSteps, { type NextStepCard } from './NextSteps'
import OfflineIdeas, { type OfflineIdea } from './OfflineIdeas'
import SaveToList from './SaveToList'

/** One idea on the board. The same shape from every way in (App\Services\Gift\GiftResults::card). */
export interface GiftPick {
    id: number
    title: string
    brand: string | null
    image: string | null
    price: Cents | null
    merchantCount?: number
    url: string
    /** What this present has in common with the brief. Interests first, then the taste. */
    fits: { kind: 'interest' | 'preference'; value: string }[]
    /** On the lists of at least five people shopping for someone like this (crowd-picks.md). */
    chosenByOthers?: boolean
    /** The thumb already given: the saved person's, or this visitor's own (find-a-gift.md). */
    vote?: Thumb | null
}

type Thumb = 'up' | 'down'

/**
 * Who a thumb is about, sent with it. A saved person's id (the server checks
 * it is the visitor's own) or a kind of person; neither says who the visitor is.
 */
export interface ThumbContext {
    recipientId?: string | null
    relationship?: string | null
}

/** Everything under the cards, from GiftResults::extras(). Each part is optional and hides when empty. */
export interface GiftResultsExtras {
    /** The gift landing page nearest the brief: a link that can be kept and shared. */
    pageUrl?: string | null
    /** Approved ideas nobody sells here, matching the brief. Id and wording only. */
    offlineIdeas?: OfflineIdea[]
    /** "Coves others made for someone like this"; see docs/features/community-coves.md. */
    communityCoves?: CommunityCoveCard[]
    /** What could follow what the chosen person was given; see docs/features/gift-history.md. */
    nextSteps?: NextStepCard[]
    /** The chosen person's page (gift history), signed in only. */
    personUrl?: string | null
    /** The board where people ask others what to buy. */
    askUrl?: string | null
    /** Called as the ask link is followed, to hand what is known to the form (askBrief.ts). */
    onAsk?: () => void
}

interface Props extends GiftResultsExtras {
    picks: GiftPick[]
    /** Above the heading: what was said or learned, and what can be done with it. */
    top?: ReactNode
    /** Null draws no heading, for a page whose own heading already says it. */
    heading?: string | null
    /** Under the heading: why these, or where a save goes. */
    note?: ReactNode
    emptyText?: string
    /** The chosen person's list, where a save lands. */
    into?: SavingTo | null
    /** The saved person the ideas are for, by name: next steps and their page. */
    personName?: string | null
    /** Off on the person's own page, where they are describing themselves rather than shopping. */
    canSave?: boolean
    /** "Chosen by others with the same interests", when choosing for yourself. */
    forMe?: boolean
    /** "Something else": only where the brief can be posted again (the questions). The thumb down calls it. */
    onSwap?: (pickId: number) => void
    /**
     * Thumbs up and down on each card, and who they are about. Without
     * `onSwap`, a thumb down hides the card here instead of replacing it.
     * Off where nobody is shopping (the person's own page).
     */
    thumbs?: ThumbContext | null
    /** An interest's label; defaults to the site's own words, then the word as typed. */
    interestLabel?: (value: string) => string
    /** Buttons under the cards (Eight more, Start over, Choose again); "Open as a page" is added here. */
    actions?: ReactNode
}

/**
 * The one results page of "Find a gift".
 *
 * The questions, This or that and a gift landing page each drew their own
 * results, and each showed a different part of what the site knows: crowd
 * picks on two, the ideas without a shop on two, Coves others made on one.
 * They all draw this now, so a section added here reaches every way in, and
 * the same board looks and behaves the same wherever it was reached from.
 * See docs/features/find-a-gift.md.
 *
 * In reading order: the cards, what to do next with them, the ideas nobody
 * sells here, the next step after what a saved person was given, Coves other
 * people made for someone like this, and last the way to ask other people.
 */
export default function GiftResults({
    picks,
    top,
    heading,
    note,
    emptyText,
    into = null,
    personName = null,
    canSave = true,
    forMe = false,
    onSwap,
    thumbs = null,
    interestLabel,
    actions,
    pageUrl = null,
    offlineIdeas = [],
    communityCoves = [],
    nextSteps = [],
    personUrl = null,
    askUrl = null,
    onAsk,
}: Props) {
    const { market } = usePage<SharedProps>().props
    const { t, tRich } = useTranslations()

    const interest = (value: string) => {
        if (interestLabel) {
            return interestLabel(value)
        }

        const label = t(`gift.interests.${value}`)
        return label === `gift.interests.${value}` ? value : label
    }

    /*
      What a card fits, in the reader's words. An interest may be one the
      person typed themselves, so it falls back to the raw word; the taste
      poles are always ours, so they always translate.
    */
    const fitLabel = (fit: GiftPick['fits'][number]) =>
        fit.kind === 'interest' ? interest(fit.value) : t(`gift.preferences.${fit.value}`)

    const hasActions = actions != null || pageUrl !== null

    /*
      Thumbs (docs/features/find-a-gift.md, "Thumbs up, thumbs down"). What
      was pressed here overrides what the server said, until the next board.
      A thumb down with a swap re-ranks on the server (the swap records it);
      without one the card leaves this board and the vote is sent on its own.
    */
    const withThumbs = thumbs !== null || onSwap !== undefined
    const [votes, setVotes] = useState<Record<number, Thumb | null>>({})
    const [gone, setGone] = useState<number[]>([])
    const voteOf = (pick: GiftPick): Thumb | null => (pick.id in votes ? votes[pick.id] : (pick.vote ?? null))

    const sendVote = (pick: GiftPick, vote: Thumb | null) =>
        send(`/${market.key}/gift/feedback`, 'POST', {
            group_id: pick.id,
            vote: vote ?? '',
            recipient_id: thumbs?.recipientId ?? null,
            relationship: thumbs?.relationship ?? null,
        })

    const thumbUp = (pick: GiftPick) => {
        const before = voteOf(pick)
        const next = before === 'up' ? null : 'up'

        setVotes((all) => ({ ...all, [pick.id]: next }))
        // Put back what was there if the server said no: a pressed thumb
        // that was never stored would promise something false.
        sendVote(pick, next).catch(() => setVotes((all) => ({ ...all, [pick.id]: before })))
    }

    const thumbDown = (pick: GiftPick) => {
        if (onSwap) {
            onSwap(pick.id)
            return
        }

        setGone((ids) => [...ids, pick.id])
        sendVote(pick, 'down').catch(() => setGone((ids) => ids.filter((id) => id !== pick.id)))
    }

    const shown = picks.filter((pick) => !gone.includes(pick.id))

    return (
        <section className="mt-8">
            {top}

            {heading !== null && (
                <h2 className={`${top ? 'mt-6' : ''} flex flex-wrap items-center gap-x-1 text-sm font-medium text-ink-soft`}>
                    {heading ?? t('gift.results_title')}
                    {withThumbs && <InfoTip>{t('gift.thumbs_hint')}</InfoTip>}
                </h2>
            )}

            {into && canSave && (
                <p className="mt-1 text-sm text-ink-soft">
                    {tRich('gift.saving_to', {
                        // The list's name leads to it (owner, 2026-09-27), as "Bewaard in …" does.
                        list: (
                            <Link href={`/${market.key}/lists/${into.id}`} className="hover:underline">
                                <ListName name={into.title} kind={into.kind} />
                            </Link>
                        ),
                    })}
                </p>
            )}

            {note}

            {shown.length === 0 ? (
                <p className="mt-4 text-ink-soft">{emptyText ?? t('gift.no_results')}</p>
            ) : (
                /*
                  Two across on a phone rather than one: a board of eight (or a
                  landing page of twenty-four) one card at a time is a long
                  scroll before the sections under it.
                */
                <ul className="mt-4 grid grid-cols-2 gap-3 sm:gap-5 lg:grid-cols-4">
                    {shown.map((pick) => (
                        <li key={pick.id} className="flex flex-col rounded-card border border-line bg-card p-3 sm:p-4">
                            <Link href={pick.url}>
                                <span className="flex h-28 items-center justify-center sm:h-36">
                                    {pick.image && (
                                        <img src={pick.image} alt="" className="max-h-full max-w-full object-contain" loading="lazy" />
                                    )}
                                </span>
                                <h3 className="mt-3 line-clamp-2 text-sm font-medium sm:text-base">{pick.title}</h3>
                            </Link>

                            {/*
                              What it fits with, not a sentence saying it fits.
                              Listed, so the reader sees which part of what
                              they said this answers.
                            */}
                            {pick.fits.length > 0 && (
                                <ul className="mt-2 flex flex-wrap gap-1.5">
                                    {pick.fits.map((fit) => (
                                        <li
                                            key={`${fit.kind}:${fit.value}`}
                                            className="rounded-full bg-sage/10 px-2 py-0.5 text-xs text-sage"
                                        >
                                            {fitLabel(fit)}
                                        </li>
                                    ))}
                                </ul>
                            )}

                            {pick.chosenByOthers && (
                                <p className="mt-2 text-xs text-ink-soft">
                                    {t(forMe ? 'gift.chosen_by_others_me' : 'gift.chosen_by_others')}
                                </p>
                            )}

                            <div className="mt-auto space-y-2 pt-4">
                                <span className="block font-semibold">
                                    {pick.price === null ? '' : formatPrice(pick.price, market)}
                                </span>
                                {(canSave || withThumbs) && (
                                    <div className="flex flex-wrap items-center gap-x-3 gap-y-2">
                                        {canSave && <SaveToList groupId={pick.id} into={into ?? undefined} />}
                                        {withThumbs && (
                                            /*
                                              Two small line icons, the words in
                                              their labels. The thumb up stays
                                              pressed (and a second press takes
                                              it back); the thumb down is the
                                              old "Something else".
                                            */
                                            <span className="ml-auto flex items-center gap-1">
                                                <button
                                                    type="button"
                                                    aria-pressed={voteOf(pick) === 'up'}
                                                    aria-label={t('gift.thumb_up')}
                                                    title={t('gift.thumb_up')}
                                                    onClick={() => thumbUp(pick)}
                                                    className={`inline-flex h-8 w-8 items-center justify-center rounded-full border transition ${
                                                        voteOf(pick) === 'up'
                                                            ? 'border-sage bg-sage/15 text-sage'
                                                            : 'border-transparent text-ink-soft hover:border-line hover:text-ink'
                                                    }`}
                                                >
                                                    <ToolIcon name="thumbsUp" className="h-4 w-4" />
                                                </button>
                                                <button
                                                    type="button"
                                                    aria-label={t(onSwap ? 'gift.thumb_down' : 'gift.thumb_down_hide')}
                                                    title={t(onSwap ? 'gift.thumb_down' : 'gift.thumb_down_hide')}
                                                    onClick={() => thumbDown(pick)}
                                                    className="inline-flex h-8 w-8 items-center justify-center rounded-full border border-transparent text-ink-soft transition hover:border-line hover:text-ink"
                                                >
                                                    <ToolIcon name="thumbsDown" className="h-4 w-4" />
                                                </button>
                                            </span>
                                        )}
                                    </div>
                                )}
                            </div>
                        </li>
                    ))}
                </ul>
            )}

            {hasActions && (
                <div className="mt-8 flex flex-wrap items-center gap-3">
                    {actions}
                    {/*
                      Open as a page: a POSTed board cannot be kept or shared;
                      the landing page nearest the brief can. Offered only
                      when that page exists.
                    */}
                    {pageUrl && (
                        <Link href={pageUrl} className="rounded border border-line px-4 py-2 text-sm hover:border-ink/40">
                            {t('gift.open_as_page')}
                        </Link>
                    )}
                </div>
            )}

            <OfflineIdeas ideas={offlineIdeas} into={into} />

            {personName && <NextSteps steps={nextSteps} name={personName} into={into} />}

            {personName && personUrl && (
                <p className="mt-6 text-sm">
                    <Link href={personUrl} className="text-accent underline">
                        {t('gift_history.link', { name: personName })}
                    </Link>
                </p>
            )}

            {communityCoves.length > 0 && (
                <section className="mt-10">
                    <h2 className="text-lg font-medium">{t('community.finder_heading')}</h2>
                    <div className="mt-4">
                        <CommunityCoveCards coves={communityCoves} />
                    </div>
                </section>
            )}

            {/*
              The last line of every results page, wherever it was reached
              from: when nothing here fits, people can suggest something.
            */}
            {askUrl && (
                <p className="mt-10 border-t border-line pt-5 text-sm text-ink-soft">
                    {t('gift.ask_prompt')}{' '}
                    <Link href={askUrl} onClick={onAsk} className="font-medium text-accent-dark underline hover:text-ink">
                        {t('gift.ask_link')}
                    </Link>
                </p>
            )}
        </section>
    )
}
