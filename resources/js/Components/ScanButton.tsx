import { useState } from 'react'
import { useTranslations } from '../useTranslations'
import BarcodeScanner from './BarcodeScanner'

interface Props {
    /**
     * The button's *skin* — border, background, padding — never the dialog's.
     * The button sits inside a search row and has to match the fields around
     * it, which differ between the pages that use it; the dialog is the same
     * object everywhere.
     *
     * Centring is not the caller's to supply: it is applied ahead of this
     * string so a caller cannot forget it and leave the icon sitting on the
     * text baseline, but stays first so a caller that really does pass a
     * display class still wins.
     */
    className?: string
    /**
     * Fill a field instead of leaving the page.
     *
     * Passed straight to `BarcodeScanner.onCode` — see there for why a picker
     * must not navigate. The dialog closes itself, because the answer has
     * arrived somewhere the visitor can see it.
     */
    onScan?: (gtin: string) => void
}

/**
 * A barcode inside a viewfinder.
 *
 * The button used to be the box-drawing character ▚, which is a half-shaded
 * block — it is not a picture of anything, and at button size it reads as a
 * rendering fault or a missing glyph rather than as an instruction. Being a text
 * character it also took its shape from whichever font the reader happens to
 * have, so it was not reliably even that.
 *
 * Bars alone would name the *object*; the corner brackets name the *action*,
 * which is what a button needs to say — this one opens a camera rather than
 * showing you a barcode. Four bars, not the six a real barcode has: below about
 * 1.5px of gap the whitespace closes up at 20px and the bars smear into a
 * single grey block, which is the failure the old glyph already had.
 *
 * Drawn to the same rules as ToolIcon — 24px grid, one stroke weight,
 * `currentColor` — so it inherits the button's text colour in both themes and
 * needs no dark-mode variant of its own.
 */
function ScanIcon({ className = 'h-5 w-5' }: { className?: string }) {
    return (
        <svg
            viewBox="0 0 24 24"
            className={className}
            fill="none"
            stroke="currentColor"
            strokeWidth={1.6}
            strokeLinecap="round"
            strokeLinejoin="round"
            aria-hidden
        >
            {/* The viewfinder, as four corners rather than a closed rectangle:
                a full border would read as a box or a card, and it is the gap
                between the corners that makes it a thing you aim. */}
            <path d="M3 8V5.5A2.5 2.5 0 0 1 5.5 3H8" />
            <path d="M16 3h2.5A2.5 2.5 0 0 1 21 5.5V8" />
            <path d="M21 16v2.5a2.5 2.5 0 0 1-2.5 2.5H16" />
            <path d="M8 21H5.5A2.5 2.5 0 0 1 3 18.5V16" />

            {/* The bars, shorter than the frame is tall, so they sit *inside*
                the viewfinder instead of colliding with the corners. */}
            <path d="M7.5 8.5v7M10.5 8.5v7M13.5 8.5v7M16.5 8.5v7" />
        </svg>
    )
}

/**
 * The scan button that sits beside a search field, and the dialog it opens.
 *
 * Scanning is a way of *entering a query* — the same intent as typing,
 * expressed with a camera — so it belongs wherever a query is entered, not in
 * the nav (see docs/features/barcode-scanner.md). That is more than one place:
 * the home page and the search page both open with a search box, and someone
 * standing in a shop should not have to run a search first to find the camera.
 *
 * Beside a field that owns its page — home, search — a scan navigates to the
 * results. Beside a *picker* — the add-to-list panel, the suggestion box on a
 * shared list, the answer composer, the discovery dial — it must not: the list
 * being added to, the half-typed answer and the dial settings all live on that
 * screen. Those callers pass `onScan` and search in place instead.
 *
 * Extracted rather than duplicated because the pieces that are easy to get
 * wrong — unmounting the scanner to release the camera, the backdrop click that
 * must not fire on a drag out of the panel, `type="button"` inside a real
 * <form> — are exactly the ones a second copy would quietly drop.
 */
export default function ScanButton({ className = 'rounded-lg border border-line px-4 py-3', onScan }: Props) {
    const { t } = useTranslations()
    const [open, setOpen] = useState(false)

    return (
        <>
            <button
                // Explicit, because the home page's form is a real
                // <form method="get">: the default `submit` would run an empty
                // search instead of opening the camera.
                type="button"
                onClick={() => setOpen(true)}
                className={`inline-flex items-center justify-center ${className}`}
                aria-label={t('scan.title')}
                title={t('scan.title')}
            >
                <ScanIcon />
            </button>

            {open && (
                <div
                    className="fixed inset-0 z-50 flex items-start justify-center bg-black/50 p-4 sm:items-center"
                    role="dialog"
                    aria-modal="true"
                    aria-label={t('scan.title')}
                    // Backdrop click closes. The check keeps a click that
                    // started inside the panel from closing it on mouse-up.
                    onMouseDown={(e) => {
                        if (e.target === e.currentTarget) setOpen(false)
                    }}
                >
                    <div className="w-full max-w-md rounded-lg bg-cream p-5 shadow-lg">
                        <div className="mb-3 flex items-baseline justify-between gap-4">
                            <h2 className="font-medium">{t('scan.title')}</h2>
                            <button
                                type="button"
                                onClick={() => setOpen(false)}
                                className="text-sm text-ink-soft underline"
                            >
                                {t('scan.close')}
                            </button>
                        </div>

                        {/*
                          Unmounted entirely when closed, which is what releases
                          the camera — the component stops its own stream on
                          unmount. Hiding it with CSS would leave the light on.
                        */}
                        <BarcodeScanner
                            autoStart
                            onCode={
                                onScan
                                    ? (gtin) => {
                                          setOpen(false)
                                          onScan(gtin)
                                      }
                                    : undefined
                            }
                        />
                    </div>
                </div>
            )}
        </>
    )
}
