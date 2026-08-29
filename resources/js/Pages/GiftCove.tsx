import { Head, Link, usePage } from '@inertiajs/react'
import ToolIcon, { type ToolKey } from '../Components/ToolIcon'
import type { SharedProps } from '../types'
import { formatOccasionDate } from '../types'
import { useTranslations } from '../useTranslations'

interface Wishlist {
    id: string
    title: string
    items: number
    shared: boolean
    isDefault: boolean
    /** The occasion this one is for, already translated. Null on a plain list. */
    occasion: string | null
    occasionDate: string | null
    url: string
}

interface Props {
    signedIn: boolean
    /**
     * Every list I keep for myself, default first.
     *
     * Plural, and that is the change: one of them is where a one-tap save lands
     * and the rest are ordinary lists of mine — a wedding, a birthday, things I
     * want some day. Showing only the default one read as a limit rather than
     * as an omission.
     */
    wishlists: Wishlist[]
    counts: {
        giftLists: number
        people: number
        registries: number
        santa: number
        suggestions: number
    }
    santaGroups: { title: string; drawn: boolean; url: string }[]
    urls: { manual: string; gift: string; lists: string; santa: string }
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
export default function GiftCove({ signedIn, wishlists, counts, santaGroups, urls }: Props) {
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

    /*
     * The list a tool acts on when it acts on "your wishlist".
     *
     * The default one, which the server puts first — it is where a save lands
     * without being asked about, so it is the one somebody means when they have
     * not said which. The cards above reach every other one.
     */
    const first = wishlists[0] ?? null

    const tools: { key: ToolKey; href: string; badge: string | null }[] = [
        {
            key: 'wishlist',
            href: first?.url ?? urls.lists,
            badge:
                wishlists.length > 1
                    ? t('gift_cove.lists_count', { count: n(wishlists.length) })
                    : first
                      ? t('gift_cove.items_count', { count: n(first.items) })
                      : null,
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
        { key: 'registry', href: first?.url ?? urls.lists, badge: counts.registries ? n(counts.registries) : null },
        { key: 'quiz', href: first?.url ?? urls.lists, badge: null },
        { key: 'suggestions', href: urls.lists, badge: counts.suggestions ? n(counts.suggestions) : null },
        { key: 'whisperer', href: urls.gift, badge: counts.people ? n(counts.people) : null },
    ]

    return (
        <>
            <Head title={t('gift_cove.seo_title')} />

            <header className="max-w-2xl">
                <h1 className="text-2xl sm:text-3xl font-semibold tracking-tight">{t('gift_cove.title')}</h1>
                <p className="mt-3 text-lg text-ink-soft">{t('gift_cove.intro')}</p>
            </header>

            {/*
              Your own lists first, and with their state on them. "3 things
              saved, not shared yet" is a next action; a link labelled "My
              wishlist" is a filing cabinet.

              Plural, because you may keep several: the default one a save lands
              in, and any number beside it — a wedding, a birthday, a list of
              things you want some day. Only the first is highlighted, because
              it is the one a save reaches without being asked about, and the
              rest read as what they are rather than as competing defaults.
            */}
            {wishlists.length > 0 && (
                <section className="mt-8 max-w-2xl">
                    <h2 className="text-xs font-medium tracking-wide text-ink-soft uppercase">
                        {t('gift_cove.my_wishlists')}
                    </h2>

                    <ul className="mt-3 space-y-3">
                        {wishlists.map((list, i) => (
                            <li
                                key={list.id}
                                className={
                                    i === 0
                                        ? 'rounded-card border border-accent/40 bg-accent/5 p-6'
                                        : 'rounded-card border border-line bg-card p-4'
                                }
                            >
                                <div className="flex flex-wrap items-baseline gap-x-2">
                                    <h3 className={i === 0 ? 'text-lg font-medium' : 'font-medium'}>
                                        {list.title}
                                    </h3>
                                    {/* The occasion is the whole visible difference
                                        between two wish lists of mine. */}
                                    {list.occasion && (
                                        <span className="rounded-full bg-line/60 px-2 py-0.5 text-xs">
                                            {list.occasionDate
                                                ? t('registry.occasion_on', {
                                                      occasion: list.occasion,
                                                      date: formatOccasionDate(
                                                          list.occasionDate,
                                                          market,
                                                      ),
                                                  })
                                                : list.occasion}
                                        </span>
                                    )}
                                </div>

                                <p className="mt-1 text-sm text-ink-soft">
                                    {t('gift_cove.items_count', { count: n(list.items) })}
                                    {' · '}
                                    {list.shared ? t('lists.sharing_on') : t('lists.sharing_off')}
                                </p>

                                <Link
                                    href={list.url}
                                    className={
                                        i === 0
                                            ? 'mt-4 inline-block rounded-lg bg-accent px-4 py-2 text-sm font-medium text-white'
                                            : 'mt-2 inline-block text-sm text-accent underline'
                                    }
                                >
                                    {list.items === 0 ? t('gift_cove.start_list') : t('gift_cove.open_list')}
                                </Link>
                            </li>
                        ))}
                    </ul>

                    {/*
                      A second list for yourself, started from here.

                      `?new=mine` opens the create form on the right shape, the
                      same way the cards below open it on theirs — otherwise
                      this points at an index and leaves the reader to work out
                      which button begins the thing they just read about.
                    */}
                    <Link
                        href={`${urls.lists}?new=mine`}
                        className="mt-3 inline-block text-sm text-accent underline"
                    >
                        + {t('gift_cove.another_list')}
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
                      A real page now, not an anchor into the bottom of this
                      one. The manual is nine entries of three steps, and it was
                      sitting underneath the dashboard most visits come for —
                      two readers wanting opposite things on one page. Moving it
                      also gives the explanation an address that an email or a
                      search result can point at.
                    */}
                    <Link
                        href={urls.manual}
                        className="text-sm text-ink-soft underline hover:text-ink"
                    >
                        {t('gift_cove.manual_link')}
                    </Link>
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
