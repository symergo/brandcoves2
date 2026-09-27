import type { ReactNode } from 'react'
import ToolIcon, { type ToolKey } from './ToolIcon'

/**
 * A section with nothing in it yet: a mark, one line saying so, and the way
 * to fill it.
 *
 * One drawing (consistency review, round 2, 2026-09-27). Mijn Coves, Mijn
 * mensen, a person's page and the question board each wrote their own box:
 * centred on one page and left-aligned on the next, `p-8`, `p-5`, `p-4`,
 * dashed on one, the action filled on one and outlined on another.
 *
 * `title` is the statement ("Nog geen lijsten"); `children` a second line,
 * kept on screen: an empty state says what is true, which is not the kind of
 * explanation that goes behind an (i). `quiet` is the dashed, left-aligned
 * version for a section inside a page (a person's page), where a bordered
 * centred card would weigh more than the sections around it that do have
 * content.
 */
export default function EmptyState({
    icon,
    title,
    children,
    action,
    quiet = false,
    className = '',
}: {
    icon?: ToolKey
    title?: ReactNode
    children?: ReactNode
    action?: ReactNode
    quiet?: boolean
    className?: string
}) {
    return (
        <div
            className={`${className} rounded-card border ${
                quiet ? 'border-dashed border-line p-4' : 'border-line bg-card p-8 text-center'
            }`}
        >
            {icon && (
                <span
                    aria-hidden
                    className={`mb-3 flex h-10 w-10 items-center justify-center rounded-full bg-accent/10 text-accent ${quiet ? '' : 'mx-auto'}`}
                >
                    <ToolIcon name={icon} className="h-5 w-5" />
                </span>
            )}
            {title && <p className="font-medium">{title}</p>}
            {children && <p className={`${title ? 'mt-1 ' : ''}text-sm text-ink-soft`}>{children}</p>}
            {action && <div className={`mt-4 flex flex-wrap gap-2 ${quiet ? '' : 'justify-center'}`}>{action}</div>}
        </div>
    )
}
