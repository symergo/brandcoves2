import { Head, Link, usePage } from '@inertiajs/react'
import CoveIcon, { type CoveKey } from '../Components/CoveIcon'
import ListWizard from '../Components/ListWizard'
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
        /** Group gifts you are on. Counted server-side since they became
         *  creatable, and read by nothing until now. */
        groupLists: number
        giftLists: number
        people: number
        registries: number
        santa: number
        suggestions: number
        friends: number
    }
    santaGroups: { title: string; drawn: boolean; url: string }[]
    urls: {
        manual: string
        gift: string
        lists: string
        santa: string
        search: string
        ask: string
        notifications: string
        friends: string
        daily: string
        guides: string
        ideas: string
        surprise: string
    }
    /**
     * What the wizard can offer; empty for a visitor.
     *
     * One list of friends for both the person picker and the sharing step:
     * they were two lists for a while, and the picker's copy left out anybody
     * who already had a profile, which emptied it.
     */
    recipients: { id: string; name: string; birthday: string | null }[]
    friends: { id: number; name: string; recipientId: string | null; birthday: string | null }[]
    occasions: { value: string; label: string; date: string | null }[]
}

/**
 * The Gift Cove.
 *
 * Every gifting tool in one place, each with a sentence saying what it is for.
 * They arrived one at a time and were each reachable from somewhere different,
 * so they were individually findable and collectively invisible — nobody could
 * see they were parts of one thing.
 *
 * ## The wizard is the hero
 *
 * The page used to open on a title and a grid. A grid of sixteen explanations
 * is a reference, and nobody arrives wanting a reference: they arrive with a
 * person and an occasion, and the thing to do with those is make a list. So
 * the top of the page *is* making one — four questions, each explained before
 * it is asked, so that by the end the reader has met every option a list has
 * and has one. The grid underneath is for afterwards: what else is here, each
 * with a button that starts it.
 *
 * ## Two layers, and why the second one exists
 *
 * A card answers *what is this for*, in one sentence, because that is the
 * question somebody scanning cards is asking. The manual (its own page) answers
 * *how do I do it*, which is a different question asked by a different person —
 * one who has already decided and now needs to know which button starts it.
 * The icon is the join: the same drawing in both places is what tells you the
 * manual entry you scrolled to is the card you pressed.
 */

/**
 * The five questions somebody arrives with.
 *
 * Five one-line headings, no prose. The grouping is the explanation, and the
 * order is the order somebody arrives in: my own list, a list for somebody,
 * doing it with other people, finding a present, getting inspired. A band is
 * three or four cards, and the grid takes its column count from the band, so
 * every band is full rows and nothing sits alone under the others.
 */
type Band = 'own' | 'someone' | 'together' | 'find' | 'inspire'

const BANDS: Band[] = ['own', 'someone', 'together', 'find', 'inspire']

/**
 * A card is a list tool or a corner of the site; the icon says which set it is
 * from. Coves keep their own `CoveIcon` drawings — the vocabulary readers met in
 * the nav — and a second version here would be two pictures for one thing.
 */
interface Card {
    key: string
    icon: { tool: ToolKey } | { cove: CoveKey }
    href: string
    badge: string | null
    band: Band
}

export default function GiftCove({
    signedIn,
    wishlists,
    counts,
    santaGroups,
    urls,
    recipients,
    friends,
    occasions,
}: Props) {
    const { market } = usePage<SharedProps>().props
    const { t, n } = useTranslations()

    /*
     * Where a card's button goes.
     *
     * A card that describes a tool and then drops you on an index leaves the
     * reader to work out which of five buttons begins the thing they just
     * read about. So "for someone else" opens the create form on that shape,
     * and everything about my own list opens the first of them — the default
     * one, the list a save lands in without being asked.
     */
    const forSomeone = `${urls.lists}?new=for_someone`
    const first = wishlists[0] ?? null

    /*
     * Every badge is a bare count of the thing the card is about — how many
     * of these you have. A number in a circle is read without being parsed,
     * and it means the same thing on every card.
     */
    const cards: Card[] = [
        { key: 'wishlist', icon: { tool: 'wishlist' }, href: first?.url ?? `${urls.lists}?new=mine`, badge: wishlists.length ? n(wishlists.length) : null, band: 'own' },
        { key: 'registry', icon: { tool: 'registry' }, href: first?.url ?? urls.lists, badge: counts.registries ? n(counts.registries) : null, band: 'own' },
        { key: 'suggestions', icon: { tool: 'suggestions' }, href: first?.url ?? urls.lists, badge: counts.suggestions ? n(counts.suggestions) : null, band: 'own' },

        { key: 'giftlist', icon: { tool: 'giftlist' }, href: forSomeone, badge: counts.giftLists ? n(counts.giftLists) : null, band: 'someone' },
        /*
         * Buying separately for one person is a list for someone, shared:
         * everybody claims what they take, nobody doubles up. It is the
         * other use of the same shape, and the one most people mean when
         * they say "let's coordinate".
         */
        { key: 'split', icon: { tool: 'split' }, href: forSomeone, badge: null, band: 'someone' },
        /*
         * Handover acts on a list you already have, and there is no single such
         * list — so this goes to My Lists, where they are.
         */
        { key: 'handover', icon: { tool: 'handover' }, href: urls.lists, badge: null, band: 'someone' },

        // Building a list together is "adding allowed" on a shared list —
        // any kind — so it starts from the lists you have.
        { key: 'build', icon: { tool: 'build' }, href: urls.lists, badge: null, band: 'together' },
        /*
         * Buying together genuinely *starts* with a new list, and since group
         * lists became creatable that list is a group one — so the card, the
         * form it opens and the step that describes it all say the same thing.
         */
        { key: 'collab', icon: { tool: 'collab' }, href: `${urls.lists}?new=group`, badge: counts.groupLists ? n(counts.groupLists) : null, band: 'together' },
        { key: 'board', icon: { tool: 'board' }, href: urls.lists, badge: null, band: 'together' },
        { key: 'santa', icon: { tool: 'santa' }, href: urls.santa, badge: counts.santa ? n(counts.santa) : null, band: 'together' },
        { key: 'quiz', icon: { tool: 'quiz' }, href: first?.url ?? urls.lists, badge: null, band: 'together' },
        { key: 'friends', icon: { tool: 'friends' }, href: urls.friends, badge: counts.friends ? n(counts.friends) : null, band: 'together' },

        { key: 'whisperer', icon: { tool: 'whisperer' }, href: urls.gift, badge: counts.people ? n(counts.people) : null, band: 'find' },
        { key: 'search', icon: { tool: 'search' }, href: urls.search, badge: null, band: 'find' },
        { key: 'ask', icon: { cove: 'ask' }, href: urls.ask, badge: null, band: 'find' },
        { key: 'alerts', icon: { tool: 'alerts' }, href: urls.notifications, badge: null, band: 'find' },

        { key: 'daily', icon: { cove: 'daily' }, href: urls.daily, badge: null, band: 'inspire' },
        { key: 'guides', icon: { tool: 'guides' }, href: urls.guides, badge: null, band: 'inspire' },
        { key: 'ideas', icon: { cove: 'persona' }, href: urls.ideas, badge: null, band: 'inspire' },
        { key: 'surprise', icon: { cove: 'surprise' }, href: urls.surprise, badge: null, band: 'inspire' },
    ]

    return (
        <>
            <Head title={t('gift_cove.seo_title')} />

            <header>
                <h1 className="text-2xl sm:text-3xl font-semibold tracking-tight">{t('gift_cove.title')}</h1>
                <p className="mt-3 text-lg text-ink-soft">{t('gift_cove.intro')}</p>
            </header>

            {/*
              The wizard, first and for everybody.

              Signed in with lists already, it makes the next one; signed out,
              it is the explanation, and its last button is the sign-in. Nothing
              on this page is more useful to a first visit than making a list,
              and a page that explains lists above the place you make one has
              its two halves the wrong way round.
            */}
            <div className="mt-8">
                <ListWizard
                    signedIn={signedIn}
                    recipients={recipients}
                    friends={friends}
                    occasions={occasions}
                />
            </div>

            {/*
              Your own lists, with their state on them. "3 things saved, not
              shared yet" is a next action; a link labelled "My wishlist" is a
              filing cabinet. Only the first is highlighted, because it is the
              one a save reaches without being asked about.
            */}
            {wishlists.length > 0 && (
                <section className="mt-10">
                    <h2 className="text-xs font-medium tracking-wide text-ink-soft uppercase">
                        {t('gift_cove.my_wishlists')}
                    </h2>

                    <ul className="mt-3 space-y-3">
                        {wishlists.map((list, i) => (
                            <li
                                key={list.id}
                                className={
                                    i === 0
                                        ? 'rounded-card border border-accent/40 bg-accent/5 p-5'
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
                                                      date: formatOccasionDate(list.occasionDate, market),
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
                                            ? 'mt-3 inline-block rounded-lg bg-accent px-4 py-2 text-sm font-medium text-white'
                                            : 'mt-2 inline-block text-sm text-accent underline'
                                    }
                                >
                                    {list.items === 0 ? t('gift_cove.start_list') : t('gift_cove.open_list')}
                                </Link>
                            </li>
                        ))}
                    </ul>
                </section>
            )}

            <section className="mt-12">
                <div className="flex flex-wrap items-baseline justify-between gap-3">
                    <div>
                        <h2 className="text-lg font-medium">{t('gift_cove.tools')}</h2>
                        <p className="mt-1 text-sm text-ink-soft">{t('gift_cove.tools_intro')}</p>
                    </div>
                    {/*
                      A real page now, not an anchor into the bottom of this
                      one: the manual is nine entries of three steps, and it
                      wants an address an email or a search result can point at.
                    */}
                    <Link href={urls.manual} className="text-sm text-ink-soft underline hover:text-ink">
                        {t('gift_cove.manual_link')}
                    </Link>
                </div>

                {BANDS.map((band) => {
                    const inBand = cards.filter((card) => card.band === band)

                    if (inBand.length === 0) {
                        return null
                    }

                    // Four across when the band divides by four, three
                    // otherwise: 3, 3, 6, 4, 4 all come out as full rows.
                    const columns = inBand.length % 4 === 0 ? 'lg:grid-cols-4' : 'lg:grid-cols-3'

                    return (
                        <div key={band} className="mt-8">
                            <h3 className="text-xs font-medium tracking-wide text-ink-soft uppercase">
                                {t(`gift_cove.band_${band}`)}
                            </h3>

                            <ul className={`mt-3 grid gap-4 sm:grid-cols-2 ${columns}`}>
                                {inBand.map((card) => (
                                    <li key={card.key}>
                                        <Link
                                            href={card.href}
                                            className="group flex h-full flex-col rounded-card border border-line bg-card p-5 transition hover:border-ink"
                                        >
                                            <div className="flex items-start justify-between gap-3">
                                                <span className="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-accent/10 text-accent transition group-hover:bg-accent group-hover:text-white">
                                                    {'tool' in card.icon ? (
                                                        <ToolIcon name={card.icon.tool} className="h-5 w-5" />
                                                    ) : (
                                                        <CoveIcon name={card.icon.cove} className="h-5 w-5" />
                                                    )}
                                                </span>
                                                {/*
                                                  A circle, sized like the icon opposite it, so
                                                  it reads as a badge; `tabular-nums` keeps 8
                                                  and 11 the same width across a grid.
                                                */}
                                                {card.badge && (
                                                    <span className="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-accent/10 text-base font-semibold text-accent tabular-nums">
                                                        {card.badge}
                                                    </span>
                                                )}
                                            </div>
                                            <h4 className="mt-4 font-medium">{t(`gift_cove.${card.key}_title`)}</h4>
                                            <p className="mt-2 text-sm text-ink-soft">{t(`gift_cove.${card.key}_body`)}</p>
                                            {/*
                                              The button, last and pushed to the bottom so a
                                              row of cards has its buttons on one line. It is
                                              part of the same link as the card: one target,
                                              two ways to see it.
                                            */}
                                            <span className="mt-auto pt-4 text-sm font-medium text-accent group-hover:underline">
                                                {t(`gift_cove.${card.key}_cta`)} →
                                            </span>
                                        </Link>
                                    </li>
                                ))}
                            </ul>
                        </div>
                    )
                })}
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
        </>
    )
}
