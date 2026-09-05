import { Link } from '@inertiajs/react'

/**
 * The one place a placeholder's output becomes markup.
 *
 * Editors write `:brand_links`, never an anchor. The server resolves a body into
 * a list of parts carrying *data* — a label and a URL — and this maps each to a
 * component. There is no path from a textarea to an element, and nothing here is
 * ever `dangerouslySetInnerHTML`: an admin form that renders arbitrary markup is
 * one stored `<script>` away from being the worst hole in the site, reached
 * through the one screen we tell people is safe to hand over.
 *
 * Adding a placeholder that returns one of these shapes is a PHP change alone.
 * Adding one that needs a *new* shape is a branch here as well, and that is the
 * whole of the boundary.
 */

export type Part =
    | { t: 'text'; v: string }
    | { t: 'links'; items: { label: string; url: string }[] }
    | { t: 'chips'; items: { label: string; url: string }[] }

export type Paragraph = Part[]

export interface BlockPayload {
    kind: 'heading' | 'paragraph'
    parts: Paragraph
}

/** Does this paragraph draw a block of its own rather than a sentence? */
export function isWidget(parts: Paragraph): boolean {
    return parts.length === 1 && parts[0].t === 'chips'
}

/**
 * A row of pills.
 *
 * Server-rendered through SSR, so a crawler receives real anchors rather than a
 * comma-separated sentence — which is the only reason these are worth having on
 * an indexable page at all.
 *
 * ## Why they are nofollow
 *
 * The targets are generated `/search?q=…` URLs, and every one a crawler follows
 * logs itself: `SearchLog::record()` writes the term, and `RelatedSearches` draws
 * the next page's chips from a trigram scan over that same table. Followed, the
 * row feeds the query that renders it — more chips, more crawlable URLs, more
 * rows, a slower scan. Measured on production 2026-09-04, that scan had grown to
 * ~7s on the canonical search page and ~5s on a brand page, against 0.5s on
 * staging running the identical commit; dev never shows it because `search_log`
 * there holds 48 rows.
 *
 * `nofollow` is a hint rather than a directive — Google has said so since 2019 —
 * so this slows the loop rather than closing it. It is the cheap half; caching
 * the placeholder per (market, rotation key) is the half that fixes the latency.
 */
function Chips({ items }: { items: { label: string; url: string }[] }) {
    return (
        <ul className="mt-3 flex flex-wrap gap-2">
            {items.map((item) => (
                <li key={item.url}>
                    <Link
                        href={item.url}
                        rel="nofollow"
                        className="block rounded-full border border-line px-3 py-1.5 text-sm hover:border-ink"
                    >
                        {item.label}
                    </Link>
                </li>
            ))}
        </ul>
    )
}

/**
 * Inline links, comma-joined, with "and" left to the sentence around them.
 *
 * Punctuation stays out of here on purpose: the editor wrote the sentence and
 * knows whether it wants a comma, a full stop or nothing after the list.
 */
function Links({ items }: { items: { label: string; url: string }[] }) {
    return (
        <>
            {items.map((item, i) => (
                <span key={item.url}>
                    {i > 0 && ', '}
                    <Link href={item.url} className="underline hover:text-accent">
                        {item.label}
                    </Link>
                </span>
            ))}
        </>
    )
}

export default function Parts({ parts }: { parts: Paragraph }) {
    return (
        <>
            {parts.map((part, i) => {
                if (part.t === 'text') return <span key={i}>{part.v}</span>
                if (part.t === 'links') return <Links key={i} items={part.items} />
                return <Chips key={i} items={part.items} />
            })}
        </>
    )
}

/**
 * One paragraph.
 *
 * A widget is not wrapped in a `<p>` — a `<ul>` inside one is invalid HTML that
 * browsers silently repair by closing the paragraph early, which is exactly the
 * kind of bug that renders fine and breaks a crawler's parse. This is the render
 * half of the "a widget must be alone in its paragraph" rule the admin also
 * validates.
 */
export function Paragraph({ parts, className }: { parts: Paragraph; className?: string }) {
    if (parts.length === 0) return null

    if (isWidget(parts)) {
        return <Parts parts={parts} />
    }

    return (
        <p className={className}>
            <Parts parts={parts} />
        </p>
    )
}
