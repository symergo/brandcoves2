import { Head, Link, router, usePage } from '@inertiajs/react'
import { useState } from 'react'
import ManualItem from '../../Components/ManualItem'
import Pledge, { type Contributions } from '../../Components/Pledge'
import type { SharedProps } from '../../types'
import { formatPrice } from '../../types'
import { useTranslations } from '../../useTranslations'

interface Item {
    id: number
    title: string
    image: string | null
    price: number | null
    note: string | null
    url: string | null
    /** Off-site, for a hand-written item. Never an Inertia visit. */
    externalUrl: string | null
    inStock: boolean
    /** null for the list owner — they must never learn what is taken. */
    claimed: boolean | null
    claimedByMe: boolean
    /** Only ever non-null for the person who claimed it. */
    sent: boolean | null
    /**
     * Absent — not null — wherever there is nothing this viewer may know about
     * the money. The owner of a wish list never receives the key at all.
     */
    contributions?: Contributions
}

interface Result {
    id: number
    title: string
    image: string | null
    price: number | null
}

interface Props {
    list: {
        title: string
        description: string | null
        kind: string
        claimable: boolean
        recipient: string | null
        for: string | null
        heading: string
    }
    isOwner: boolean
    /** null for the owner — a count is claim state too. */
    progress: { claimed: number; total: number } | null
    items: Item[]
    canSuggest: boolean
    suggestTerm: string
    /** null before a search is run; `[]` once one found nothing. */
    results: Result[] | null
    /** Mirrored from the pledge endpoint, which re-checks it regardless. */
    canContribute: boolean
    /**
     * Null unless this list has an occasion on it. `address` is non-null only
     * for somebody who has claimed something — the server decides, and this
     * page renders what it is given.
     */
    registry: {
        occasion: string
        date: string | null
        address: string | null
        locked: boolean
    } | null
}

export default function SharedList({
    list,
    isOwner,
    progress,
    items,
    canSuggest,
    suggestTerm,
    results,
    canContribute,
    registry,
}: Props) {
    const page = usePage<SharedProps>()
    const { market } = page.props
    const { t } = useTranslations()
    /*
     * From the page, not from `window`.
     *
     * `window` does not exist while the server renders, so reading it here
     * threw and Inertia fell back to client-side rendering — silently, and on
     * precisely the three pages a stranger opens from a link they were sent:
     * this one, the quiz and the self-describe page. They arrived as an empty
     * shell that had to boot React before showing anything.
     */
    const token = page.url.split('?')[0].split('/').filter(Boolean).pop()
    const base = `/${market.key}`
    const [query, setQuery] = useState(suggestTerm)

    /*
     * A registry date is booked a long way out — a wedding eighteen months
     * ahead is ordinary — so the year is shown whenever it is not this one.
     * Adding it unconditionally makes every near date heavier than it needs
     * to be.
     */
    function registryDate(iso: string): string {
        const date = new Date(iso)

        return new Intl.DateTimeFormat(market.hrefLang, {
            day: 'numeric',
            month: 'short',
            ...(date.getFullYear() === new Date().getFullYear() ? {} : { year: 'numeric' }),
        }).format(date)
    }

    return (
        <>
            {/* A shared gift list must never be indexed: it is a private URL
                that happens to be unauthenticated. */}
            <Head title={list.heading}>
                <meta name="robots" content="noindex, nofollow" />
            </Head>

            <header>
                {/* Whose list it is, not what they filed it under. */}
                <h1 className="text-2xl font-semibold">{list.heading}</h1>
                {list.description && <p className="mt-2 text-ink-soft">{list.description}</p>}

                {list.claimable && !isOwner && (
                    <p className="mt-4 rounded-card border border-line bg-card p-4 text-sm">
                        {/*
                          Name the person, or say nothing about a person at all.
                          Falling back to the list *title* told visitors that
                          "Saved items" would not see who claimed what — and an
                          anonymous owner genuinely has no name to give.
                        */}
                        {list.for
                            ? t('lists.shared_intro', { name: list.for })
                            : t('lists.shared_intro_anon')}
                    </p>
                )}

                {isOwner && (
                    <p className="mt-4 rounded-card border border-amber/40 bg-amber/10 p-4 text-sm">
                        {t('lists.owner_view_note')}
                    </p>
                )}

                {/*
                  "3 of 11 claimed". The server has sent this since the strip
                  was specced and the page never drew it, so a visitor arriving
                  late had no way to tell a list that was mostly spoken for from
                  one nobody had touched — which is the difference between
                  choosing carefully and choosing quickly.

                  Null for the owner, never zero: the moment a zero stops being
                  zero they have learnt something.
                */}
                {progress !== null && progress.total > 0 && (
                    <p className="mt-4 text-sm text-ink-soft">
                        {t('lists.progress', {
                            claimed: String(progress.claimed),
                            total: String(progress.total),
                        })}
                    </p>
                )}

                {/*
                  The registry block.

                  The occasion and the date are why the list exists and are
                  shown to everybody holding the link. The address is not: it
                  appears once you have claimed something, which is the promise
                  `registry.address_hint` has made to the owner since the
                  feature shipped and which nothing implemented until now.

                  The locked state says the address is there rather than saying
                  nothing — otherwise somebody who has claimed nothing concludes
                  the owner forgot to add one.
                */}
                {registry !== null && (
                    <section className="mt-4 rounded-card border border-line bg-card p-4">
                        <p className="text-sm font-medium">
                            {registry.date
                                ? t('registry.occasion_on', {
                                      occasion: registry.occasion,
                                      date: registryDate(registry.date),
                                  })
                                : registry.occasion}
                        </p>

                        {registry.address !== null && (
                            <div className="mt-3">
                                <p className="text-xs font-medium text-ink-soft">{t('registry.send_to')}</p>
                                {/* An address, not a link. `pre-line` keeps the
                                    owner's line breaks and gives them nothing
                                    else. */}
                                <address className="mt-1 text-sm whitespace-pre-line not-italic">
                                    {registry.address}
                                </address>
                            </div>
                        )}

                        {registry.locked && (
                            <p className="mt-3 text-xs text-ink-soft">{t('registry.address_locked')}</p>
                        )}
                    </section>
                )}
            </header>

            <ul className="mt-8 grid gap-4 sm:grid-cols-2">
                {items.map((item) => (
                    <li
                        key={item.id}
                        className={`flex flex-col rounded-card border bg-card p-4 ${
                            item.claimed && !item.claimedByMe ? 'border-line opacity-60' : 'border-line'
                        }`}
                    >
                        <div className="flex gap-4">
                            {item.image && (
                                <img
                                    src={item.image}
                                    alt=""
                                    className="h-20 w-20 rounded object-contain"
                                    onError={(e) => { e.currentTarget.style.visibility = 'hidden' }}
                                />
                            )}

                            <div className="min-w-0 flex-1">
                                {item.url ? (
                                    <Link href={item.url} className="font-medium hover:underline">
                                        {item.title}
                                    </Link>
                                ) : item.externalUrl ? (
                                    /*
                                      Somebody else's link, on a page strangers
                                      open. `noopener` so the destination cannot
                                      reach back through `window.opener`,
                                      `noreferrer` so it is not told which list
                                      sent the visitor, and `nofollow` because
                                      we are not vouching for it. The scheme was
                                      settled server-side; this is the rest.
                                    */
                                    <a
                                        href={item.externalUrl}
                                        target="_blank"
                                        rel="nofollow noopener noreferrer"
                                        className="font-medium hover:underline"
                                    >
                                        {item.title}
                                    </a>
                                ) : (
                                    <span className="font-medium">{item.title}</span>
                                )}
                                {item.note && <p className="mt-1 text-sm text-ink-soft">{item.note}</p>}
                                {item.price !== null && (
                                    <p className="mt-1 font-semibold">{formatPrice(item.price, market)}</p>
                                )}
                            </div>
                        </div>

                        {/*
                          Claim controls are absent entirely for the owner —
                          `claimed` is null in their payload, so there is nothing
                          to render even if this branch were reached.
                        */}
                        {!isOwner && item.claimed !== null && (
                            <div className="mt-4">
                                {item.claimedByMe ? (
                                    /*
                                      Claiming was a dead end: you said you would
                                      get it and then had nowhere to say you had.
                                      The endpoint and the `sent` flag both
                                      existed; only the button was missing, so
                                      the strip above could never finish.
                                    */
                                    item.sent ? (
                                        <p className="w-full rounded-lg border border-sage bg-sage/10 px-4 py-2 text-center text-sm font-medium text-sage">
                                            {t('lists.sent')}
                                        </p>
                                    ) : (
                                        <div className="flex flex-col gap-2">
                                            <button
                                                onClick={() =>
                                                    router.post(
                                                        `${base}/l/${token}/sent/${item.id}`,
                                                        {},
                                                        { preserveScroll: true },
                                                    )
                                                }
                                                className="w-full rounded-lg border border-sage bg-sage/10 px-4 py-2 text-sm font-medium text-sage"
                                            >
                                                {t('lists.mark_sent')}
                                            </button>
                                            <button
                                                onClick={() =>
                                                    router.delete(`${base}/l/${token}/claim/${item.id}`, {
                                                        preserveScroll: true,
                                                    })
                                                }
                                                className="text-xs text-ink-soft underline hover:text-ink"
                                            >
                                                {t('lists.unclaim')}
                                            </button>
                                        </div>
                                    )
                                ) : item.claimed ? (
                                    <p className="w-full rounded-lg border border-line px-4 py-2 text-center text-sm text-ink-soft">
                                        {t('lists.claimed_by_someone')}
                                    </p>
                                ) : (
                                    <button
                                        onClick={() =>
                                            router.post(`${base}/l/${token}/claim/${item.id}`, {}, { preserveScroll: true })
                                        }
                                        className="w-full rounded-lg bg-accent px-4 py-2 text-sm font-medium text-white hover:bg-accent-dark"
                                    >
                                        {t('lists.claim')}
                                    </button>
                                )}
                            </div>
                        )}

                        {/*
                          Money, beside the claim controls rather than behind a
                          panel: it is a fact about this present, and the person
                          reading it is deciding about this present. Rendered
                          only when the server sent a payload — its absence is
                          the privacy rule doing its job, so there is deliberately
                          no fallback branch here.
                        */}
                        {item.contributions !== undefined && (
                            <Pledge
                                action={`${base}/l/${token}/pledge/${item.id}`}
                                contributions={item.contributions}
                                canContribute={canContribute}
                                price={item.price}
                            />
                        )}
                    </li>
                ))}
            </ul>

            {/*
              "I think you would like this."

              The other half of a feature that shipped with only one: the
              endpoint, its guards and the owner's accept/dismiss row all
              existed, and nothing on any page could send one — so the copy
              below ("Suggest something") sat in four language files, rendered
              nowhere, for as long as the feature has been live. Its tests
              passed throughout, because they POST to the endpoint directly.

              Below the list, never above it. Somebody arrived to see what this
              person wants; putting a search box first answers a question they
              have not asked yet, and the empty list is exactly the case where
              they scroll far enough to reach this anyway.
            */}
            {canSuggest && (
                <section className="mt-12 rounded-card border border-line bg-card p-6">
                    <h2 className="font-medium">{t('suggestions.invite')}</h2>
                    <p className="mt-1 text-sm text-ink-soft">{t('suggestions.invite_hint')}</p>

                    <form
                        className="mt-4 flex flex-wrap gap-2"
                        onSubmit={(e) => {
                            e.preventDefault()

                            /*
                              A GET back to this same URL, which re-renders the
                              page with `results`. One route, one token check —
                              a second endpoint would be a second place the
                              share token has to be resolved and gated.
                            */
                            router.get(
                                `${base}/l/${token}`,
                                { q: query },
                                { preserveState: true, preserveScroll: true },
                            )
                        }}
                    >
                        <input
                            value={query}
                            onChange={(e) => setQuery(e.target.value)}
                            placeholder={t('suggestions.search_placeholder')}
                            aria-label={t('suggestions.search_placeholder')}
                            className="min-w-0 flex-1 rounded-lg border border-line bg-cream px-3 py-2 text-sm"
                        />
                        <button type="submit" className="rounded-lg border border-line px-4 py-2 text-sm hover:border-ink">
                            {t('search.submit')}
                        </button>
                    </form>

                    {results !== null && results.length === 0 && (
                        <p className="mt-4 text-sm text-ink-soft">{t('suggestions.none_found')}</p>
                    )}

                    {/*
                      The thing somebody most wants to put forward is often the
                      thing we do not sell — a voucher, the local bike shop, one
                      particular edition of a book. Ending the search with "no
                      results" wastes the one moment they were willing to help.
                    */}
                    <div className="mt-4">
                        <ManualItem
                            action={`${base}/l/${token}/suggest`}
                            hint={t('suggestions.manual_hint')}
                        />
                    </div>

                    {results !== null && results.length > 0 && (
                        <ul className="mt-4 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                            {results.map((result) => (
                                <li key={result.id} className="flex flex-col rounded-card border border-line p-4">
                                    {result.image && (
                                        <img
                                            src={result.image}
                                            alt=""
                                            loading="lazy"
                                            className="mx-auto h-28 w-auto max-w-full object-contain"
                                            onError={(e) => { e.currentTarget.style.visibility = 'hidden' }}
                                        />
                                    )}
                                    <p className="mt-3 line-clamp-2 text-sm font-medium">{result.title}</p>
                                    {result.price !== null && (
                                        <p className="mt-1 text-sm text-ink-soft">
                                            {formatPrice(result.price, market)}
                                        </p>
                                    )}
                                    <button
                                        type="button"
                                        onClick={() =>
                                            router.post(
                                                `${base}/l/${token}/suggest`,
                                                { group_id: result.id },
                                                { preserveScroll: true },
                                            )
                                        }
                                        className="mt-3 w-full rounded-lg bg-accent px-3 py-1.5 text-sm font-medium text-white hover:bg-accent-dark"
                                    >
                                        {t('suggestions.suggest')}
                                    </button>
                                </li>
                            ))}
                        </ul>
                    )}
                </section>
            )}
        </>
    )
}
