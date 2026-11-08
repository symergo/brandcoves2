import { Link } from '@inertiajs/react'
import ToolIcon from './ToolIcon'
import type { ReactNode, Ref } from 'react'
import type { Cents, CurrentMarket } from '../types'
import { formatPrice } from '../types'
import { useTranslations } from '../useTranslations'

/**
 * One thing on a list, drawn the same way wherever the list is read.
 *
 * ## Why this exists
 *
 * The owner's page and the shared page showed the same items in two different
 * shapes: a column of rows inside one bordered box on `/lists/{id}`, a
 * two-column grid of cards on `/l/{code}`. Thumbnails were 56px on one and 80px
 * on the other, the note had a top margin on one and not the other, and the
 * price said "Nu €39,99" in one place and "€39,99" in the other. Nothing chose
 * that — the two pages were written months apart and drifted.
 *
 * It matters more than it looks. A person meets the same list from both sides
 * within minutes of sharing it, and the two views not matching makes them
 * wonder whether they are looking at the same list.
 *
 * ## What is shared and what is not
 *
 * The **product half** is shared: image, title, the three ways a title can
 * link, the note and the price. That part is the same fact wherever it appears.
 *
 * The **actions** are not, and they should not be. The owner gets copy and
 * remove; a visitor gets claim, vote, chip in and save-to-my-list. Those are
 * genuinely different jobs, so they arrive as `aside` (beside the title) and
 * `children` (under the card) rather than being flattened into a prop soup that
 * pretends they are one control with many faces.
 */
export default function ListItemCard({
    title,
    image,
    url,
    externalUrl,
    buyUrl = null,
    note,
    price,
    was = null,
    market,
    muted = false,
    innerRef,
    className = '',
    aside,
    children,
    search = false,
}: {
    /**
     * A saved search ("Lenovo aanbiedingen", 2026-11-08): our own results page
     * rather than a product. Drawn with a search icon where the picture goes
     * and its title centred, and it opens in this tab, since it is this site.
     */
    search?: boolean
    title: string
    image: string | null
    /** An on-site product page. Inertia, because it is one. */
    url: string | null
    /** Somebody's hand-typed link, off-site. Never an Inertia visit. */
    externalUrl: string | null
    /** The link to follow: externalUrl as an affiliate link when the shop is one of ours. */
    buyUrl?: string | null
    note: string | null
    /** What it costs now. Null when nothing knows. */
    price: Cents | null
    /** What it cost when it was saved, if that is not the price now. */
    was?: Cents | null
    market: CurrentMarket
    /** Spoken for by somebody else: the card steps back without disappearing. */
    muted?: boolean
    innerRef?: Ref<HTMLLIElement>
    className?: string
    /** Beside the title — a save control, a remove button. */
    aside?: ReactNode
    /** Under the card — claim, vote, chip in. */
    children?: ReactNode
}) {
    const { t } = useTranslations()

    /*
     * Only when it actually moved, and only then does "now" mean anything.
     *
     * "Nu €39,99" on its own is a sentence about a comparison the reader cannot
     * see. With the old price struck through beside it, the word earns its
     * place; without one, the bare price says everything.
     */
    const moved = was !== null && was !== price

    return (
        <li
            ref={innerRef}
            /*
              Opacity was once the only signal that an item was taken. There is a
              label beside it, so it was not broken — but a fade is doing the
              work of a state, and it is the first thing lost to a bright screen
              outdoors or to anyone who does not perceive the difference. The
              border carries it; the fade reinforces.
            */
            className={`flex flex-col overflow-hidden rounded-card border bg-card p-3 lg:p-4 ${
                muted ? 'border-dashed border-ink-soft/40 opacity-70' : 'border-line'
            } ${className}`}
        >
            {/*
              A row at every width (owner, 2026-11-08: "the items on the lists
              on small screen view should also be horizontal rows, not big
              pictures with small text below"). From 2026-09-08 a phone showed
              tiles, two to a row, picture the card's width and two lines of
              title under it; the owner preferred the words readable to the
              picture large. Thumbnail, title and price, controls at the end.

              Without a picture the words sit in the middle of the row's
              height rather than at its top (2026-11-08).
            */}
            <div className={`relative flex gap-3 lg:gap-4 ${image ? '' : 'flex-1 items-center'}`}>
                {!image && search && externalUrl && (
                    <a
                        href={externalUrl}
                        tabIndex={-1}
                        aria-hidden
                        // No tile: the site's duotone icon on the card itself, like
                        // the list rows (design-system.md, owner 2026-11-08).
                        className="flex h-16 w-16 shrink-0 items-center justify-center text-accent lg:h-20 lg:w-20"
                    >
                        <ToolIcon name="search" className="h-9 w-9 lg:h-10 lg:w-10" duo />
                    </a>
                )}

                {image &&
                    (() => {
                        /*
                          The picture goes where the title goes: a tap on it
                          that did nothing read as a broken page (2026-09-08).
                          Same three cases as the title beside it: ours,
                          somebody else's, or nowhere.
                        */
                        const picture = (
                            <img
                                src={image}
                                alt=""
                                className="h-full w-full object-contain"
                                // A feed image that 404s left a broken-image glyph where a
                                // product should be. Hidden rather than removed, so the
                                // layout does not shift under everything below it.
                                onError={(e) => {
                                    e.currentTarget.style.visibility = 'hidden'
                                }}
                            />
                        )
                        const box = 'relative block h-16 w-16 shrink-0 rounded bg-white lg:h-20 lg:w-20'

                        return url ? (
                            <Link href={url} tabIndex={-1} aria-hidden className={box}>
                                {picture}
                            </Link>
                        ) : externalUrl ? (
                            <a
                                href={buyUrl ?? externalUrl}
                                target="_blank"
                                rel={buyUrl && buyUrl !== externalUrl ? 'sponsored nofollow noopener noreferrer' : 'nofollow noopener noreferrer'}
                                tabIndex={-1}
                                aria-hidden
                                className={box}
                            >
                                {picture}
                            </a>
                        ) : (
                            <div className={box}>{picture}</div>
                        )
                    })()}

                <div className="min-w-0 flex-1">
                    {/*
                      Clamped, because a feed title is written for a search engine
                      rather than a person: "OneOne 25W super snellader met 2
                      poorten + 1,5m sterke USB C kabel. PD lader. Oplader adapter
                      past op Sony WH-1000XM3, WH-1000XM4, …" ran to ten lines on a
                      phone and made one card four times the height of its
                      neighbours. Two lines under a picture, three beside one, is
                      enough to recognise a thing you have already seen, which is
                      what a list is for.
                    */}
                    {url ? (
                        <Link href={url} className="line-clamp-3 text-sm font-medium hover:underline lg:text-base">
                            {title}
                        </Link>
                    ) : search && externalUrl ? (
                        <a href={externalUrl} className="line-clamp-3 text-sm font-medium hover:underline lg:text-base">
                            {title}
                        </a>
                    ) : externalUrl ? (
                        /*
                          Somebody's own link, on a page strangers open. `noopener`
                          so the destination cannot reach back through
                          `window.opener`, `noreferrer` so it is not told which list
                          sent the visitor, and `nofollow` because we are not
                          vouching for it. The scheme was settled server-side; this
                          is the rest.
                        */
                        <a
                            href={buyUrl ?? externalUrl}
                            target="_blank"
                            rel={buyUrl && buyUrl !== externalUrl ? 'sponsored nofollow noopener noreferrer' : 'nofollow noopener noreferrer'}
                            className="line-clamp-3 text-sm font-medium hover:underline lg:text-base"
                        >
                            {title}
                        </a>
                    ) : (
                        <span className="line-clamp-3 text-sm font-medium lg:text-base">{title}</span>
                    )}

                    {note && <p className="mt-1 text-sm text-ink-soft">{note}</p>}

                    {/*
                      Price under the title, not in a column beside it — the shape
                      `AddProduct`'s search rows already use, so a product looks the
                      same when you pick it and after it is on the list. A
                      right-aligned column cost the title a third of a narrow screen
                      and set the price on its own baseline, so a long feed title
                      wrapped past a price floating level with its first line.
                    */}
                    {price !== null && (
                        <p className="mt-1 text-sm">
                            <span className="font-semibold">
                                {moved
                                    ? t('lists.price_now', { price: formatPrice(price, market) })
                                    : formatPrice(price, market)}
                            </span>

                            {moved && (
                                <span className="ml-2 text-xs text-ink-soft line-through">
                                    {formatPrice(was, market)}
                                </span>
                            )}
                        </p>
                    )}
                </div>

                {/*
                  At the end of the row, beside the title, at every width.
                */}
                {aside && (
                    <div className={`flex shrink-0 items-start gap-1 ${image ? 'self-start' : 'self-center'}`}>
                        {aside}
                    </div>
                )}
            </div>

            {children && <div className="pt-2">{children}</div>}
        </li>
    )
}
