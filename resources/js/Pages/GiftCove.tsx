import { Head, Link, usePage } from '@inertiajs/react'
import ToolIcon, { type ToolKey } from '../Components/ToolIcon'
import type { SharedProps } from '../types'
import { useTranslations } from '../useTranslations'

interface Props {
    signedIn: boolean
    mine: { id: string; title: string; items: number; shared: boolean; url: string } | null
    counts: {
        giftLists: number
        people: number
        registries: number
        santa: number
        suggestions: number
    }
    santaGroups: { title: string; drawn: boolean; url: string }[]
    urls: { gift: string; lists: string; santa: string }
}

/**
 * The Gift Cove.
 *
 * Every gifting tool in one place, each with a sentence saying what it is for.
 * They arrived one at a time and were each reachable from somewhere different,
 * so they were individually findable and collectively invisible — nobody could
 * see they were parts of one thing.
 *
 * The explanations are the point. "Secret Santa" needs none; "a list you build
 * for somebody and then hand over to them" needs one, and a tool nobody
 * understands is a tool nobody opens.
 *
 * ## Two layers, and why the second one exists
 *
 * A card answers *what is this for*, in one sentence, because that is the
 * question somebody scanning nine cards is asking. The manual below answers
 * *how do I do it*, which is a different question asked by a different person —
 * one who has already decided and now needs to know which button starts it.
 *
 * Answering both on the card was tried and is worse: nine tools each with four
 * lines of instructions is a wall, and the reader who wanted the one-line
 * version has to read past all of it. So the sentence stays on the card, the
 * steps go below, and the icon is the join — the same drawing in both places is
 * what tells you the manual entry you scrolled to is the card you pressed.
 *
 * Three steps each, and nothing else. Caveats, exceptions and the privacy rules
 * were drafted in beside them and taken back out: a manual entry that runs past
 * the point where the reader could have started is one they stop reading. The
 * rules the tools enforce are enforced whether or not this page explains them.
 *
 * The steps name what is actually on the screen ("press Share", "press
 * People"), never a paraphrase. A manual that describes a button by its purpose
 * rather than its label sends people hunting for a control they are looking
 * straight at.
 */
export default function GiftCove({ signedIn, mine, counts, santaGroups, urls }: Props) {
    const { market } = usePage<SharedProps>().props
    const { t, n } = useTranslations()
    const base = `/${market.key}`

    /*
     * Each card starts its tool, rather than describing it and then dropping
     * you on an index.
     *
     * Six of the nine used to point at `urls.lists`, so reading "a list you
     * build for somebody and then hand over to them" and pressing it got you a
     * page of your existing lists and no clue which button began that. The three
     * that begin with a list *for someone* now open the create form on that
     * shape; the ones that act on a list you already have open that list.
     */
    const forSomeone = `${urls.lists}?new=for_someone`

    const tools: { key: ToolKey; href: string; badge: string | null }[] = [
        {
            key: 'wishlist',
            href: mine?.url ?? urls.lists,
            badge: mine ? t('gift_cove.items_count', { count: n(mine.items) }) : null,
        },
        { key: 'giftlist', href: forSomeone, badge: counts.giftLists ? n(counts.giftLists) : null },
        /*
         * Buying together genuinely *starts* with a new list, and since group
         * lists became creatable that list is a group one — so the card, the
         * form it opens and the step that describes it now all say the same
         * thing. It used to open the "for someone else" shape while its first
         * step said "open a list you made for someone else".
         */
        { key: 'collab', href: `${urls.lists}?new=group`, badge: null },
        /*
         * Handover acts on a list you already have, and there is no single such
         * list — so this goes to My Lists, where they are. It used to open a
         * *create* form while its first step said "open the list".
         */
        { key: 'handover', href: urls.lists, badge: null },
        { key: 'santa', href: urls.santa, badge: counts.santa ? n(counts.santa) : null },
        { key: 'registry', href: mine?.url ?? urls.lists, badge: counts.registries ? n(counts.registries) : null },
        { key: 'quiz', href: mine?.url ?? urls.lists, badge: null },
        { key: 'suggestions', href: urls.lists, badge: counts.suggestions ? n(counts.suggestions) : null },
        { key: 'whisperer', href: urls.gift, badge: counts.people ? n(counts.people) : null },
    ]

    return (
        <>
            <Head title={t('gift_cove.seo_title')} />

            <header className="max-w-2xl">
                <h1 className="text-3xl font-semibold tracking-tight">{t('gift_cove.title')}</h1>
                <p className="mt-3 text-lg text-ink-soft">{t('gift_cove.intro')}</p>
            </header>

            {/*
              Your own list first, and with its state on it. "3 things saved,
              not shared yet" is a next action; a link labelled "My wishlist" is
              a filing cabinet.
            */}
            {mine && (
                <section className="mt-8 max-w-2xl rounded-card border border-accent/40 bg-accent/5 p-6">
                    <h2 className="text-lg font-medium">{mine.title}</h2>
                    <p className="mt-1 text-sm text-ink-soft">
                        {t('gift_cove.items_count', { count: n(mine.items) })}
                        {' · '}
                        {mine.shared ? t('lists.sharing_on') : t('lists.sharing_off')}
                    </p>
                    <Link
                        href={mine.url}
                        className="mt-4 inline-block rounded-lg bg-accent px-4 py-2 text-sm font-medium text-white"
                    >
                        {mine.items === 0 ? t('gift_cove.start_list') : t('gift_cove.open_list')}
                    </Link>
                </section>
            )}

            {!signedIn && (
                <p className="mt-6 max-w-2xl rounded-card border border-line bg-card p-4 text-sm text-ink-soft">
                    {t('lists.sign_in_hint')}{' '}
                    <Link href={`${base}/login`} className="underline">
                        {t('nav.sign_in')}
                    </Link>
                </p>
            )}

            <section className="mt-12">
                <div className="flex flex-wrap items-baseline justify-between gap-3">
                    <h2 className="text-lg font-medium">{t('gift_cove.tools')}</h2>
                    {/*
                      A plain anchor, not an Inertia Link: this goes to a place
                      on the page that is already loaded, and routing a visit to
                      fetch it again would scroll to the top on the way.
                    */}
                    <a href="#manual" className="text-sm text-ink-soft underline hover:text-ink">
                        {t('gift_cove.manual_link')}
                    </a>
                </div>

                <ul className="mt-4 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                    {tools.map((tool) => (
                        <li key={tool.key}>
                            <Link
                                href={tool.href}
                                className="group flex h-full flex-col rounded-card border border-line bg-card p-6 transition hover:border-ink"
                            >
                                <div className="flex items-start justify-between gap-3">
                                    <span className="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-accent/10 text-accent transition group-hover:bg-accent group-hover:text-white">
                                        <ToolIcon name={tool.key} className="h-5 w-5" />
                                    </span>
                                    {tool.badge && (
                                        <span className="shrink-0 rounded-full bg-accent/10 px-2 py-0.5 text-xs font-medium text-accent">
                                            {tool.badge}
                                        </span>
                                    )}
                                </div>
                                <h3 className="mt-4 font-medium">{t(`gift_cove.${tool.key}_title`)}</h3>
                                <p className="mt-2 text-sm text-ink-soft">
                                    {t(`gift_cove.${tool.key}_body`)}
                                </p>
                            </Link>
                        </li>
                    ))}
                </ul>
            </section>

            {/*
              The manual.

              Not an accordion. Collapsed steps are steps nobody reads, and the
              whole reason this section exists is that the one-line description
              above was not enough — hiding the longer answer behind a second
              press reproduces the problem it was written to solve.
            */}
            <section id="manual" className="mt-16 scroll-mt-8 border-t border-line pt-10">
                <h2 className="text-xl font-semibold tracking-tight">{t('gift_cove.manual')}</h2>
                <p className="mt-2 max-w-2xl text-ink-soft">{t('gift_cove.manual_intro')}</p>

                <div className="mt-8 grid gap-x-10 gap-y-10 md:grid-cols-2">
                    {tools.map((tool) => (
                        <article key={tool.key} id={`how-${tool.key}`} className="scroll-mt-8">
                            <div className="flex items-center gap-3">
                                <span className="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-accent/10 text-accent">
                                    <ToolIcon name={tool.key} className="h-5 w-5" />
                                </span>
                                <h3 className="font-medium">{t(`gift_cove.${tool.key}_title`)}</h3>
                            </div>

                            {/*
                              An `ol` with its own drawn markers rather than a
                              list-style disc. The order *is* the instruction,
                              so it has to survive as an ordered list for a
                              screen reader, and a reader who has done step two
                              has to find step three without re-reading step
                              one.
                            */}
                            <ol className="mt-4 space-y-3">
                                {[1, 2, 3].map((step) => (
                                    <li key={step} className="flex gap-3 text-sm leading-relaxed text-ink-soft">
                                        <span
                                            aria-hidden
                                            className="mt-0.5 flex h-5 w-5 shrink-0 items-center justify-center rounded-full border border-line text-[11px] font-medium text-ink"
                                        >
                                            {n(step)}
                                        </span>
                                        {t(`gift_cove.${tool.key}_step${step}`)}
                                    </li>
                                ))}
                            </ol>

                        </article>
                    ))}
                </div>
            </section>

            {santaGroups.length > 0 && (
                <section className="mt-12">
                    <h2 className="text-lg font-medium">{t('santa.title')}</h2>
                    <ul className="mt-4 grid gap-4 sm:grid-cols-2">
                        {santaGroups.map((group) => (
                            <li key={group.url}>
                                <Link
                                    href={group.url}
                                    className="block rounded-card border border-line bg-card p-4 transition hover:border-ink"
                                >
                                    <span className="font-medium">{group.title}</span>
                                    <span className="mt-1 block text-sm text-ink-soft">
                                        {group.drawn ? t('santa.drawn') : t('santa.not_drawn')}
                                    </span>
                                </Link>
                            </li>
                        ))}
                    </ul>
                </section>
            )}

            <p className="mt-12 max-w-2xl rounded-card border border-line bg-card p-4 text-sm text-ink-soft">
                {t('gift_cove.privacy')}
            </p>
        </>
    )
}
