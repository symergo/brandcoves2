import { Head, Link } from '@inertiajs/react'
import type { ReactNode } from 'react'

interface Section {
    title: string
    body: string
    shot: { src: string; alt: string } | null
}

interface Props {
    topic: string
    title: string
    intro: string | null
    numbered: boolean
    sections: Section[]
    copy: { back: string; next: string; cta_search: string; cta_lists: string }
    index: string
    next: { key: string; url: string; title: string; blurb: string } | null
    urls: { search: string; lists: string }
}

/**
 * One topic of the list help: a title, an optional intro, sections, and the
 * way to the next topic.
 *
 * ## Numbered only where the order is the instruction
 *
 * The saving topic is three steps that happen in that order, and the numbers
 * carry the step. The other topics are facts about one capability, and a
 * number on "Delivery address" would promise a sequence that is not there.
 * The server says which kind a topic is.
 *
 * ## Every step of the first topic has a picture, and the picture carries it
 *
 * Written instructions for an interface are read at the moment somebody has
 * already failed to find something, and prose describing a small round button
 * on a card is slower than a photograph of it. The images come from
 * `scripts/help-screenshots.mjs`, which drives the real site — see
 * `ListHelpController` for why they are per language and why Spanish borrows
 * the English set.
 *
 * ## A body is paragraphs, a paragraph of "- " lines is a list, and
 * [words](url) is a link
 *
 * The prose lives in a language file as plain text, so it cannot carry
 * markup. Three shapes are enough for help text: a paragraph, a short list
 * of the ways to do one thing, and a link on the words a person would search
 * for. The server has already turned each link's path into the market's URL,
 * so an internal one is an Inertia link and anything else a plain anchor.
 * Anything richer would be an argument for a different tool, not a richer
 * parser.
 */
export default function ListHelpTopic({ title, intro, numbered, sections, copy, index, next, urls }: Props) {
    const Steps = numbered ? 'ol' : 'div'

    return (
        <>
            <Head title={title} />

            <div className="mx-auto max-w-3xl px-4 py-12">
                <Link href={index} className="text-sm text-ink-soft hover:text-ink">
                    ← {copy.back}
                </Link>

                <h1 className="mt-4 text-2xl font-semibold text-ink sm:text-3xl">{title}</h1>
                {intro && (
                    <p className="mt-3 text-ink-soft">
                        <Inline text={intro} />
                    </p>
                )}

                <Steps className="mt-10 space-y-12">
                    {sections.map((section, i) => (
                        <Section key={section.title} section={section} number={numbered ? i + 1 : null} />
                    ))}
                </Steps>

                {next && (
                    <Link
                        href={next.url}
                        className="mt-16 block rounded-card border border-line bg-card p-4 transition hover:border-ink"
                    >
                        <span className="block text-xs font-medium tracking-wide text-ink-soft uppercase">
                            {copy.next}
                        </span>
                        <span className="mt-1 block font-medium text-ink">{next.title}</span>
                        <span className="mt-1 block text-sm text-ink-soft">{next.blurb}</span>
                    </Link>
                )}

                <div className="mt-12 flex flex-wrap gap-3">
                    <Link
                        href={urls.search}
                        className="rounded-lg bg-accent px-4 py-2 font-medium text-white hover:bg-accent-dark"
                    >
                        {copy.cta_search}
                    </Link>
                    <Link
                        href={urls.lists}
                        className="rounded-lg border border-line px-4 py-2 font-medium hover:border-ink"
                    >
                        {copy.cta_lists}
                    </Link>
                </div>
            </div>
        </>
    )
}

function Section({ section, number }: { section: Section; number: number | null }) {
    const Wrapper = number === null ? 'section' : 'li'
    const indent = number === null ? '' : 'ml-10'

    return (
        <Wrapper>
            <div className="flex items-baseline gap-3">
                {number !== null && (
                    <span
                        aria-hidden="true"
                        className="flex h-7 w-7 shrink-0 items-center justify-center rounded-full bg-ink text-sm font-semibold text-card"
                    >
                        {number}
                    </span>
                )}
                <h2 className="text-lg font-semibold text-ink">{section.title}</h2>
            </div>

            <div className={`mt-2 space-y-3 text-ink-soft ${indent}`}>
                <Body text={section.body} />
            </div>

            {section.shot && (
                /*
                  The border matters: these are screenshots of a cream page
                  shown on a cream page, and without an edge they read as part
                  of this document rather than as a picture of another one.

                  Indented under the step number from `sm` up; `ml-10 w-full`
                  together were 100% plus 40px, which is the 8px of sideways
                  scroll every phone reader of this page got.
                */
                <img
                    src={section.shot.src}
                    alt={section.shot.alt}
                    loading="lazy"
                    className="mt-4 w-full rounded-lg border border-line shadow-sm sm:ml-10 sm:w-[calc(100%-2.5rem)]"
                />
            )}
        </Wrapper>
    )
}

function Body({ text }: { text: string }) {
    return (
        <>
            {text.split(/\n\s*\n/).map((block, i) => {
                const lines = block.split('\n').map((l) => l.trim())
                const isList = lines.length > 0 && lines.every((l) => l.startsWith('- '))

                return isList ? (
                    <ul key={i} className="list-disc space-y-2 pl-5">
                        {lines.map((l) => (
                            <li key={l}>
                                <Inline text={l.slice(2)} />
                            </li>
                        ))}
                    </ul>
                ) : (
                    <p key={i}>
                        <Inline text={block} />
                    </p>
                )
            })}
        </>
    )
}

/** Text with [words](url) turned into links. */
function Inline({ text }: { text: string }) {
    const parts: ReactNode[] = []
    const pattern = /\[([^\]]+)\]\(([^)\s]+)\)/g
    let last = 0
    let match: RegExpExecArray | null

    while ((match = pattern.exec(text)) !== null) {
        if (match.index > last) parts.push(text.slice(last, match.index))

        const [, words, href] = match
        const className = 'text-ink underline decoration-line underline-offset-2 hover:text-accent'

        parts.push(
            href.startsWith('/') ? (
                <Link key={match.index} href={href} className={className}>
                    {words}
                </Link>
            ) : (
                <a key={match.index} href={href} className={className}>
                    {words}
                </a>
            ),
        )
        last = match.index + match[0].length
    }

    if (last < text.length) parts.push(text.slice(last))

    return <>{parts}</>
}
