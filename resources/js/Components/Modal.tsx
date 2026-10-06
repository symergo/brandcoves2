import { type ReactNode, useCallback, useEffect, useRef, useState } from 'react'
import Button from './Button'
import ToolIcon from './ToolIcon'
import { useBackCloses } from '../useBackCloses'
import { useTranslations } from '../useTranslations'

/**
 * A popup over the page: the native `<dialog>` opened with `showModal()`.
 *
 * ## Why one component (consistency review, round 2, 2026-09-27)
 *
 * The share popup on Mijn Coves, its add popup and the question's share popup
 * each wrote the same forty lines: a ref, an effect calling `showModal()`, a
 * click on the backdrop closing it, a title and a close button. The native
 * element is kept for the reasons `SignInDialog` gives (focus stays inside,
 * Escape closes it, the page behind is inert, and it sits in the top layer
 * wherever it is rendered, so a dialog opened from a menu item is not clipped
 * by the card the menu sits on).
 *
 * Mounted means open: a caller renders it when it should show and removes it
 * to close it, which is how every existing popup here already worked.
 *
 * ## Full screen on a phone (owner's rule, 2026-10-06)
 *
 * "Popups on mobile need to be full screen." Below `sm` a popup takes the
 * whole screen, square, with no backdrop showing round it, in the page's own
 * cream (2026-10-06: a white full screen read as a different site); from `sm`
 * up it is the white card in the middle it always was. The rule is here, in the one popup,
 * so no caller has to remember it. A short "are you sure?" (`alertdialog`)
 * stays a card: a full screen for one question and two buttons reads as a
 * new page, not a question about this one.
 *
 * ## Back closes it (owner, 2026-10-06)
 *
 * The phone's back button closes the popup on top rather than leaving the
 * page (`useBackCloses`, which says how and why Inertia is kept out of it).
 */
export default function Modal({
    title,
    label,
    onClose,
    width = 'md',
    role,
    children,
}: {
    /** The heading in the popup. Omitted for a bare confirmation. */
    title?: ReactNode
    /** The spoken name, when there is no text title or it is not a string. */
    label?: string
    onClose: () => void
    width?: 'sm' | 'md' | 'lg'
    /** `alertdialog` for a question that interrupts (ConfirmDialog). */
    role?: 'alertdialog'
    children: ReactNode
}) {
    const { t } = useTranslations()
    const ref = useRef<HTMLDialogElement>(null)

    useBackCloses(onClose)

    useEffect(() => {
        const el = ref.current

        if (el !== null && !el.open) {
            el.showModal()
        }
    }, [])

    const widths = {
        sm: 'w-[min(26rem,calc(100vw-2rem))]',
        md: 'w-[min(32rem,calc(100vw-2rem))]',
        lg: 'w-[min(36rem,calc(100vw-2rem))]',
    }
    const atSm = {
        sm: 'sm:w-[min(26rem,calc(100vw-2rem))]',
        md: 'sm:w-[min(32rem,calc(100vw-2rem))]',
        lg: 'sm:w-[min(36rem,calc(100vw-2rem))]',
    }

    /*
     * A section's title inside a popup (an h3, a fieldset's legend) reads as a
     * title: larger and bold, in ink. They were the body's size and weight, so
     * a popup of several parts read as one run of text (owner, 2026-10-06:
     * "make the section titles in the popups more clear"). Set here, once, so
     * every popup's sections follow without each form restyling its own.
     */
    const sectionTitles =
        '[&_h3]:text-base [&_h3]:font-semibold [&_h3]:text-ink [&_legend]:text-base [&_legend]:font-semibold [&_legend]:text-ink'

    const shape =
        role === 'alertdialog'
            ? `m-auto max-h-[calc(100dvh-2rem)] ${widths[width]} rounded-card border border-line`
            : `m-0 h-dvh max-h-none w-screen max-w-none sm:m-auto sm:h-auto sm:max-h-[calc(100dvh-2rem)] ${atSm[width]} sm:rounded-card sm:border sm:border-line`

    return (
        <dialog
            ref={ref}
            role={role}
            onClose={onClose}
            onClick={(e) => {
                // A press on the backdrop is a press on the dialog element itself.
                if (e.target === ref.current) {
                    onClose()
                }
            }}
            aria-label={label ?? (typeof title === 'string' ? title : undefined)}
            className={`${shape} overflow-x-hidden overflow-y-auto ${role === 'alertdialog' ? 'bg-card' : 'bg-cream sm:bg-card'} p-6 text-ink backdrop:bg-ink/40 ${sectionTitles}`}
        >
            {title !== undefined && (
                <div className="flex items-start justify-between gap-3">
                    <h2 className="min-w-0 text-lg font-semibold break-words">{title}</h2>
                    <button
                        type="button"
                        onClick={onClose}
                        aria-label={t('nav.close')}
                        className="-mt-1 -mr-2 flex h-9 w-9 shrink-0 items-center justify-center rounded-lg text-ink-soft hover:bg-line/40 hover:text-ink"
                    >
                        <ToolIcon name="close" className="h-4 w-4" />
                    </button>
                </div>
            )}
            {children}
        </dialog>
    )
}

export interface ConfirmOptions {
    /** The question, in full: what will happen, and whether it can be undone. */
    message: ReactNode
    /** The words on the button that acts ("Verwijderen", not "OK"). */
    confirmLabel?: string
    /** Red, for something that deletes or cannot be taken back. */
    danger?: boolean
}

/**
 * "Are you sure?", as the site's own popup instead of the browser's.
 *
 * `window.confirm()` was used in a dozen places: grey browser chrome, the
 * site's address as its title, "OK" as the button that deletes a list, and on
 * some phones a checkbox offering to silence the site's dialogs for good. This
 * says what the button does, in the site's colours, with the destructive one in
 * the danger colour and Cancel first to reach.
 */
export function ConfirmDialog({
    message,
    confirmLabel,
    danger = false,
    onConfirm,
    onCancel,
}: ConfirmOptions & { onConfirm: () => void; onCancel: () => void }) {
    const { t } = useTranslations()

    return (
        <Modal label={typeof message === 'string' ? message : undefined} onClose={onCancel} width="sm" role="alertdialog">
            <p className="text-sm">{message}</p>
            <div className="mt-5 flex flex-wrap justify-end gap-2">
                {/* Cancel first: it takes the focus when the popup opens, so a
                    stray Enter does not delete anything. */}
                <Button variant="secondary" onClick={onCancel} autoFocus>
                    {t('nav.cancel')}
                </Button>
                <Button variant={danger ? 'destructive' : 'primary'} onClick={onConfirm}>
                    {confirmLabel ?? t('nav.confirm')}
                </Button>
            </div>
        </Modal>
    )
}

/**
 * The confirmation as a promise, so a `window.confirm()` call site becomes
 * `if (await confirm({ … }))` with its logic unchanged:
 *
 *     const [confirm, confirmDialog] = useConfirm()
 *     …
 *     {confirmDialog}
 *
 * The dialog has to be rendered by the caller (the second value), because a
 * hook cannot render; with nothing pending it is null.
 */
export function useConfirm(): [(options: ConfirmOptions) => Promise<boolean>, ReactNode] {
    const [pending, setPending] = useState<(ConfirmOptions & { resolve: (ok: boolean) => void }) | null>(null)

    const confirm = useCallback(
        (options: ConfirmOptions) =>
            new Promise<boolean>((resolve) => {
                setPending({ ...options, resolve })
            }),
        [],
    )

    const settle = (ok: boolean) => {
        pending?.resolve(ok)
        setPending(null)
    }

    const dialog =
        pending === null ? null : (
            <ConfirmDialog
                message={pending.message}
                confirmLabel={pending.confirmLabel}
                danger={pending.danger}
                onConfirm={() => settle(true)}
                onCancel={() => settle(false)}
            />
        )

    return [confirm, dialog]
}
