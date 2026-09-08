import { Link } from '@inertiajs/react'
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
    note,
    price,
    was = null,
    market,
    muted = false,
    innerRef,
    className = '',
    aside,
    children,
}: {
    title: string
    image: string | null
    /** An on-site product page. Inertia, because it is one. */
    url: string | null
    /** Somebody's hand-typed link, off-site. Never an Inertia visit. */
    externalUrl: string | null
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
            className={`flex flex-col overflow-hidden rounded-card border bg-card lg:p-4 ${
                muted ? 'border-dashed border-ink-soft/40 opacity-70' : 'border-line'
            } ${className}`}
        >
            {/*
              A tile on a phone, a row on a desktop.

              The row — thumbnail, three lines of title, controls — is the
              right shape at 490px and the wrong one at 170. Three attempts
              on 2026-09-08 moved the controls around inside the row and each
              cost something: the title got its width and the card got a strip
              of empty space, or the picture stayed a smudge. The owner's
              brief was "compact, large picture", and that is a tile: the
              picture is the card's width, square, with the controls sitting
              on it where a product card puts them; the words go underneath,
              two lines of title and the price; and the list shows two tiles
              to a row. A row of two tiles is shorter than two of the old rows
              and every picture is twice the size.

              From `lg` the same elements are the row they were: the two-column
              grid gives a card 490px there, and beside a picture that wide
              the words would be the afterthought.
            */}
            <div className="relative lg:flex lg:gap-4">
                {image && (
                    <div className="relative aspect-square w-full bg-white lg:aspect-auto lg:h-20 lg:w-20 lg:shrink-0 lg:rounded">
                        <img
                            src={image}
                            alt=""
                            className="h-full w-full object-contain p-2 lg:p-0"
                            // A feed image that 404s left a broken-image glyph where a
                            // product should be. Hidden rather than removed, so the
                            // layout does not shift under everything below it.
                            onError={(e) => {
                                e.currentTarget.style.visibility = 'hidden'
                            }}
                        />
                    </div>
                )}

                <div className="min-w-0 px-3 pt-2 pb-1 lg:flex-1 lg:p-0">
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
                        <Link href={url} className="line-clamp-2 text-sm font-medium hover:underline lg:line-clamp-3 lg:text-base">
                            {title}
                        </Link>
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
                            href={externalUrl}
                            target="_blank"
                            rel="nofollow noopener noreferrer"
                            className="line-clamp-2 text-sm font-medium hover:underline lg:line-clamp-3 lg:text-base"
                        >
                            {title}
                        </a>
                    ) : (
                        <span className="line-clamp-2 text-sm font-medium lg:line-clamp-3 lg:text-base">{title}</span>
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
                  On the picture, top right, where the product card keeps the same
                  control; beside the title from `lg`. The controls carry their
                  own background and blur, so they read on any picture. A manual
                  item with no picture has nothing to sit on, so there they go
                  under the words instead of over them.
                */}
                {aside && (
                    <div
                        className={`flex items-start gap-1 lg:static lg:shrink-0 lg:self-start lg:p-0 ${
                            image ? 'absolute top-2 right-2' : 'justify-end px-3 pb-2'
                        }`}
                    >
                        {aside}
                    </div>
                )}
            </div>

            {children && <div className="px-3 pb-3 lg:px-0 lg:pb-0">{children}</div>}
        </li>
    )
}
