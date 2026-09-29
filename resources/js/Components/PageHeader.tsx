import { Link } from '@inertiajs/react'
import type { ReactNode } from 'react'
import InfoTip from './InfoTip'

/**
 * The way back to the page above: "← Mijn Coves", "← Mijn mensen".
 *
 * One drawing (consistency review, round 2, 2026-09-27): the list page and a
 * person's page wrote it as a grey arrow link, the question page as an
 * underlined word without an arrow, and the Secret Santa pages had none.
 */
export function BackLink({ href, children }: { href: string; children: ReactNode }) {
    return (
        <Link href={href} className="inline-block text-sm text-ink-soft hover:text-ink">
            <span aria-hidden>←</span> {children}
        </Link>
    )
}

/**
 * The top of a page: the way back, the title with what sits beside it, the
 * explanation behind an (i), and the page's own actions on the right.
 *
 * ## Two sizes, and which page gets which
 *
 * The design system listed "three h1 tiers with no rule" as open. The rule
 * here: an overview (Mijn Coves, Mijn mensen, Secret Santa, a tool's start
 * page) is `lg`; one thing inside it (a list, a person, a group, a question)
 * is `md`, a step down, because it sits under a back link to the overview
 * and is read as part of it.
 *
 * ## The (i) goes under the title
 *
 * `note` is rendered through `InfoTip` in the title's row, so the opened
 * explanation lands under the title and not beside it (the owner's rule,
 * `data-infotip-note` in app.css).
 *
 * Built from what Mijn Coves and Mijn mensen looked like on 2026-09-27, not a
 * redesign: the title left, actions right and wrapping under it on a phone.
 */
export default function PageHeader({
    title,
    back,
    before,
    size = 'lg',
    icon,
    beside,
    note,
    actions,
    className = '',
    children,
}: {
    title: ReactNode
    back?: { href: string; label: string }
    /** A line above the title that has to be read first (who can see a question). */
    before?: ReactNode
    size?: 'lg' | 'md'
    /** A mark before the title (the tool's icon). */
    icon?: ReactNode
    /** Beside the title, on its line: a kind pill, the Santa badge. */
    beside?: ReactNode
    /** The explanation, behind an (i). */
    note?: ReactNode
    /** The page's own buttons, on the right. */
    actions?: ReactNode
    className?: string
    /** Under the title: a subtitle, a meta line. */
    children?: ReactNode
}) {
    const heading =
        size === 'lg' ? 'text-2xl font-semibold tracking-tight sm:text-3xl' : 'text-xl font-semibold tracking-tight sm:text-2xl'

    return (
        <header className={className}>
            {back && <BackLink href={back.href}>{back.label}</BackLink>}
            {before && <div className={back ? 'mt-3' : ''}>{before}</div>}
            <div className={`${back || before ? 'mt-1 ' : ''}flex flex-wrap items-start justify-between gap-x-4 gap-y-3`}>
                <div className="flex min-w-0 flex-1 flex-wrap items-center gap-x-2 gap-y-1">
                    {icon}
                    <h1 className={`${heading} break-words`}>{title}</h1>
                    {note && <InfoTip className="-ml-1">{note}</InfoTip>}
                    {beside}
                </div>
                {/*
                  On a phone the pages pass icon-only buttons (the label behind
                  `hidden sm:inline`), so they fit beside the title. Full labels
                  squeezed "Mijn Coves" onto two lines with a button across it
                  (owner's screenshot, 2026-09-29: "on mobile, buttons should be icons").
                */}
                {actions && <div className="flex flex-wrap gap-2">{actions}</div>}
            </div>
            {children}
        </header>
    )
}
