import { Head, Link } from '@inertiajs/react'
import ToolIcon, { type ToolKey } from '../../Components/ToolIcon'
import { useTranslations } from '../../useTranslations'

/**
 * The manual: nine tools, three steps each.
 *
 * Lifted off `/gift-cove`, where it was the bottom half of a page whose top
 * half is a personalised dashboard. Two readers, opposite needs — one is here
 * to use a tool, the other to understand one — and the second was made to
 * scroll past the first.
 *
 * Three rules carried over unchanged, because each is the reason a plainer
 * version is worse:
 *
 * - **A step quotes the label that is really on the screen** — "press Share",
 *   "press Do the draw". Describing a control by its purpose instead of its
 *   name sends somebody hunting for a button they are looking straight at. The
 *   corollary is that renaming a control silently invalidates the step naming
 *   it, and only a human reading the page notices; `CopyMatchesCodeTest` holds
 *   the few that can be checked mechanically.
 * - **Three steps, no fourth line.** Caveats were drafted and taken back out.
 *   Every one is true and each is enforced by the tool whether or not this page
 *   mentions it, and an entry that runs past the point where the reader could
 *   have started is one they stop reading.
 * - **Not an accordion.** Collapsed steps are steps nobody reads, and hiding
 *   the longer answer behind a second press reproduces exactly the problem this
 *   page was written to solve.
 */
const TOOLS: ToolKey[] = [
    'wishlist',
    'giftlist',
    'collab',
    'handover',
    'santa',
    'registry',
    'quiz',
    'suggestions',
    'whisperer',
]

export default function HowItWorks({ backUrl }: { backUrl: string }) {
    const { t, n } = useTranslations()

    return (
        <>
            <Head title={t('gift_cove.manual')} />

            <header>
                <Link href={backUrl} className="text-sm text-ink-soft hover:text-ink">
                    ← {t('gift_cove.title')}
                </Link>
                <h1 className="mt-1 text-xl sm:text-2xl font-semibold tracking-tight">{t('gift_cove.manual')}</h1>
                <p className="mt-2 max-w-2xl text-ink-soft">{t('gift_cove.manual_intro')}</p>
            </header>

            <div className="mt-10 grid gap-x-10 gap-y-10 md:grid-cols-2">
                {TOOLS.map((tool) => (
                    <article key={tool} id={`how-${tool}`} className="scroll-mt-8">
                        <div className="flex items-center gap-3">
                            {/*
                              The same drawing as the card on the hub. That
                              repetition is load-bearing: it is what tells a
                              reader who followed the link that the entry they
                              are reading belongs to the card they pressed.
                            */}
                            <span className="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-accent/10 text-accent">
                                <ToolIcon name={tool} className="h-5 w-5" />
                            </span>
                            <h2 className="font-medium">{t(`gift_cove.${tool}_title`)}</h2>
                        </div>

                        {/*
                          An `ol` with its own drawn markers rather than a
                          list-style disc. The order *is* the instruction, so it
                          has to survive as an ordered list for a screen reader,
                          and a reader who has done step two has to find step
                          three without re-reading step one.
                        */}
                        <ol className="mt-4 space-y-3">
                            {[1, 2, 3].map((step) => (
                                <li
                                    key={step}
                                    className="flex gap-3 text-sm leading-relaxed text-ink-soft"
                                >
                                    <span
                                        aria-hidden
                                        className="mt-0.5 flex h-5 w-5 shrink-0 items-center justify-center rounded-full border border-line text-[11px] font-medium text-ink"
                                    >
                                        {n(step)}
                                    </span>
                                    {t(`gift_cove.${tool}_step${step}`)}
                                </li>
                            ))}
                        </ol>
                    </article>
                ))}
            </div>

            <p className="mt-12 border-t border-line pt-6">
                <Link href={backUrl} className="text-sm underline">
                    {t('gift_cove.manual_back')}
                </Link>
            </p>
        </>
    )
}
