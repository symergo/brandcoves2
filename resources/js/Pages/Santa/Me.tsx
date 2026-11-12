import { Head, router, usePage } from '@inertiajs/react'
import { buttonClasses } from '../../Components/Button'
import PageHeader from '../../Components/PageHeader'
import { AskBox, chipClasses, Countdown, GuessSanta, HintsForm, Progress, QuestionInbox, RevealCard, type GuessState, type SantaQuestion } from '../../Components/SantaGame'
import SaveToList from '../../Components/SaveToList'
import { formatBudget, formatDay, formatPrice, type Cents, type SharedProps } from '../../types'
import { useTranslations } from '../../useTranslations'

interface Wish {
    id: number
    token: string
    title: string
    image: string | null
    price: Cents | null
    live: boolean
    url: string | null
    /** So a Santa can keep an idea on their own list. Null for a manual wish. */
    groupId: number | null
    claimed: boolean
}

interface Props {
    group: {
        id: string
        title: string
        budgetMin: Cents | null
        budgetMax: Cents | null
        exchangeDate: string | null
        drawn: boolean
        drawAt: string | null
        exchangePassed: boolean
        progress: { members: number; ready: number; done: number }
    }
    me: {
        joinToken: string
        name: string
        done: boolean
        hasList: boolean
        listUrl: string | null
        /** False until the card is turned: nothing below prints the name before that. */
        revealed: boolean
        /** False for somebody copied in from last year who has not pressed "I'm in" yet. */
        joined: boolean
        interests: string[]
        hints: string | null
        listViews: number
        muted: boolean
        inbox: SantaQuestion[]
        guess: GuessState | null
        thanked: boolean
        giftee: {
            name: string
            isLinked: boolean
            wishes: Wish[]
            interests: string[]
            hints: string | null
            ideas: { label: string; url: string }[]
            asked: SantaQuestion[]
        } | null
    }
    interestKeys: string[]
}

/**
 * One member's view: who they drew, and that person's list.
 *
 * Reached by join token so it works without an account — requiring a login to
 * be in an office Secret Santa is how most of the office does not join.
 */
export default function SantaMe({ group, me, interestKeys }: Props) {
    const { market } = usePage<SharedProps>().props
    const { t } = useTranslations()
    const base = `/${market.key}`
    // Everything a member does on this page goes through their own token.
    const mine = `${base}/santa/${group.id}/me/${me.joinToken}`
    const showGiftee = group.drawn && me.giftee !== null && me.revealed
    const hasTips = me.interests.length > 0 || Boolean(me.hints)

    return (
        <>
            {/* The single page that names a pairing. Never indexed. */}
            <Head title={group.title}>
                <meta name="robots" content="noindex, nofollow" />
            </Head>

            {/* One centred column, like the group page (2026-11-12). */}
            <div className="mx-auto max-w-2xl">

            <PageHeader
                className="max-w-2xl"
                size="md"
                back={{ href: `/${market.key}/santa`, label: t('santa.title') }}
                title={group.title}
            >

                {!group.drawn ? (
                    <p className="mt-4 rounded-card border border-line bg-card p-4 text-sm">
                        {t('santa.not_drawn')}
                    </p>
                ) : me.giftee && me.revealed ? (
                    <p className="mt-3 text-lg">
                        {t('santa.you_have', { name: me.giftee.name })}
                    </p>
                ) : null}

                <p className="mt-2 text-sm text-ink-soft">
                    {/* No dangling separator when one of the two is missing. */}
                    {[
                        group.budgetMax !== null ? formatBudget(group.budgetMax, market) : null,
                        group.exchangeDate ? formatDay(group.exchangeDate, market, { year: 'auto' }) : null,
                    ]
                        .filter(Boolean)
                        .join(' · ')}
                </p>

                <p className="mt-1">
                    <Countdown drawn={group.drawn} drawAt={group.drawAt} exchangeDate={group.exchangeDate} />
                </p>
                <Progress drawn={group.drawn} progress={group.progress} />
            </PageHeader>

            {/* The game around the draw (owner, 2026-11-12), most pressing first:
                a question waiting for me, then the card to turn. */}
            <div className="max-w-2xl">
                {/* Copied in from last year's group: in again with one press.
                    A button, not the page view: mail scanners open links. */}
                {!me.joined && !group.drawn && (
                    <div className="mt-8 rounded-card border border-line bg-card p-5">
                        <p className="font-medium">{t('santa.mail.again_subject', { title: group.title })}</p>
                        <button
                            type="button"
                            onClick={() => router.post(`${mine}/in`, {}, { preserveScroll: true })}
                            className={buttonClasses('primary', 'md', 'mt-3')}
                        >
                            {t('santa.mail.again_cta')}
                        </button>
                    </div>
                )}

                <QuestionInbox baseUrl={mine} questions={me.inbox} />

                {group.drawn && me.giftee && !me.revealed && <RevealCard name={me.giftee.name} revealUrl={`${mine}/reveal`} />}

                {me.guess && <GuessSanta baseUrl={mine} guess={me.guess} thanked={me.thanked} />}

                {/* Tips in a few taps, for everybody who will never make a list.
                    Open while empty, folded once filled in. */}
                {!group.exchangePassed && (
                    <HintsForm url={`${mine}/hints`} keys={interestKeys} interests={me.interests} hints={me.hints} open={!hasTips && !me.hasList} />
                )}

                {me.listViews > 0 && !group.exchangePassed && (
                    <p className="mt-4 text-sm text-ink-soft">
                        {me.listViews === 1 ? t('santa.list_views_one') : t('santa.list_views_many', { count: String(me.listViews) })}{' '}
                        {!me.hasList && !hasTips && t('santa.list_views_nudge')}
                    </p>
                )}
            </div>

            {/*
              Your own list, pushed before anything else.

              Joining a group is the strongest possible reason to build one —
              whoever drew you has nothing to go on until you do. Without this
              nudge Secret Santa degrades into "here is a name, good luck".
            */}
            {!me.hasList && !group.exchangePassed && (
                <section className="mt-8 max-w-2xl rounded-card border border-line bg-card p-4">
                    <h2 className="font-medium">{t('santa.build_yours')}</h2>
                    <p className="mt-1 text-sm text-ink-soft">{t('santa.build_yours_hint')}</p>
                    <a
                        href={`${base}/lists`}
                        className={buttonClasses('secondary', 'md', 'mt-3')}
                    >
                        {t('lists.new_list')}
                    </a>
                </section>
            )}

            {showGiftee && me.giftee && (
                <section className="mt-10">
                    {/* What they ticked and wrote about themselves, before their list. */}
                    {(me.giftee.interests.length > 0 || me.giftee.hints) && (
                        <div className="mb-8 max-w-2xl rounded-card border border-line bg-card p-5">
                            <h2 className="font-medium">{t('santa.their_tips', { name: me.giftee.name })}</h2>
                            {me.giftee.interests.length > 0 && (
                                <ul className="mt-2 flex flex-wrap gap-2">
                                    {me.giftee.interests.map((key) => (
                                        <li key={key} className="rounded-full border border-line px-3 py-1 text-sm text-ink-soft">
                                            {t(`santa.interest.${key}`)}
                                        </li>
                                    ))}
                                </ul>
                            )}
                            {me.giftee.hints && <p className="mt-3">{me.giftee.hints}</p>}
                        </div>
                    )}

                    <h2 className="text-lg font-medium">
                        {t('santa.their_list', { name: me.giftee.name })}
                    </h2>

                    {me.giftee.wishes.length === 0 ? (
                        <div className="mt-3 rounded-card border border-line bg-card p-6 text-sm">
                            <p>{t('santa.no_list', { name: me.giftee.name })}</p>
                            <a
                                href={`${base}/gift`}
                                className="mt-3 inline-block rounded-lg border border-line px-4 py-2"
                            >
                                {t('gift.title')}
                            </a>
                        </div>
                    ) : (
                        <ul className="mt-4 divide-y divide-line overflow-hidden rounded-card border border-line bg-card">
                            {me.giftee.wishes.map((wish) => (
                                <li key={wish.id} className="flex items-center gap-4 p-4">
                                    {wish.image && (
                                        <img
                                            src={wish.image}
                                            alt=""
                                            loading="lazy"
                                            className="h-14 w-14 shrink-0 object-contain"
                                        />
                                    )}
                                    <div className="min-w-0 flex-1">
                                        <p className="truncate text-sm font-medium">{wish.title}</p>
                                        {wish.price !== null && !wish.live && (
                                            <p className="text-sm text-ink-soft">
                                                {formatPrice(wish.price, market)}
                                            </p>
                                        )}
                                    </div>

                                    {/*
                                      Keep it on my own shortlist while I think.
                                      
                                      Claiming is a commitment to the group;
                                      this is a note to myself, and needing the
                                      first to do the second is what makes
                                      somebody claim early and regret it. Writes
                                      only to my list, so it tells the giftee
                                      nothing.
                                    */}
                                    {wish.groupId !== null && (
                                        <div className="shrink-0">
                                            <SaveToList groupId={wish.groupId} compact />
                                        </div>
                                    )}

                                    {/* Claiming still matters inside a group:
                                        families overlap, and several people may
                                        hold the same person's link. */}
                                    {wish.claimed ? (
                                        <span className="shrink-0 text-sm text-ink-soft">
                                            {t('lists.claimed_by_someone')}
                                        </span>
                                    ) : (
                                        <button
                                            type="button"
                                            onClick={() =>
                                                router.post(
                                                    `${base}/l/${wish.token}/claim/${wish.id}`,
                                                    {},
                                                    { preserveScroll: true },
                                                )
                                            }
                                            className="shrink-0 rounded-lg border border-line px-3 py-1.5 text-sm"
                                        >
                                            {me.giftee ? t('lists.claim_for', { name: me.giftee.name }) : t('lists.claim')}
                                        </button>
                                    )}
                                </li>
                            ))}
                        </ul>
                    )}

                    {/* Searches that fit the budget, from what they ticked. */}
                    {me.giftee.ideas.length > 0 && (
                        <div className="mt-8">
                            <h2 className="text-lg font-medium">{t('santa.ideas_title')}</h2>
                            <p className="mt-1 text-sm text-ink-soft">{t('santa.ideas_hint', { name: me.giftee.name })}</p>
                            <ul className="mt-3 flex flex-wrap gap-2">
                                {me.giftee.ideas.map((idea) => (
                                    <li key={idea.url}>
                                        <a href={idea.url} className={`inline-block ${chipClasses()}`}>
                                            {idea.label}
                                        </a>
                                    </li>
                                ))}
                            </ul>
                        </div>
                    )}

                    {!group.exchangePassed && (
                        <div className="max-w-2xl">
                            <AskBox baseUrl={mine} name={me.giftee.name} asked={me.giftee.asked} />
                        </div>
                    )}

                    {!me.done && (
                        <button
                            type="button"
                            onClick={() =>
                                router.post(
                                    `${base}/santa/${group.id}/me/${me.joinToken}/done`,
                                    {},
                                    { preserveScroll: true },
                                )
                            }
                            className="mt-6 rounded-lg border border-line px-4 py-2 text-sm"
                        >
                            {t('santa.mark_done')}
                        </button>
                    )}
                </section>
            )}

            {/* The friendly mails, off or on again; the assignment mail is not one of them. */}
            <p id="mails" className="mt-12 border-t border-line pt-6 text-sm text-ink-soft">
                <button
                    type="button"
                    onClick={() => router.post(`${mine}/mute`, me.muted ? { off: 1 } : {}, { preserveScroll: true })}
                    className="underline hover:text-ink"
                >
                    {me.muted ? t('santa.unmute_link') : t('santa.mute_link')}
                </button>
            </p>
            </div>
        </>
    )
}
