import { Head, Link } from '@inertiajs/react'
import FeedbackForm from '../Components/FeedbackForm'
import { useTranslations } from '../useTranslations'

interface Props {
    guides: { key: string; url: string }[]
    path: string | null
}

/**
 * How the site works, and where to say it does not.
 *
 * ## The order is the odds
 *
 * Guides first, form second. Most people arriving here are stuck rather than
 * reporting a fault, and a form at the top of a help page asks them to describe
 * a problem they would rather just solve. Whoever the guides did not help
 * scrolls past two cards to reach it, which is a fair price for putting the
 * likely answer first.
 *
 * ## The form is the real one
 *
 * `FeedbackForm` is the component `/feedback` renders, not a copy of it. Two
 * forms posting to one endpoint drift: the honeypot gets added to one, the line
 * explaining what the address is for gets rewritten on the other.
 */
export default function Help({ guides, path }: Props) {
    const { t } = useTranslations()

    return (
        <>
            <Head title={t('help.title')} />

            <div className="mx-auto max-w-2xl px-4 py-10">
                {/*
                  The heading is the whole invitation.

                  A sentence under it restated what the two cards and the form
                  heading already say, in front of the answers somebody came for.
                  Same reasoning the feedback page recorded when its own three
                  pieces of preamble came off.
                */}
                <h1 className="text-3xl font-semibold tracking-tight text-ink">{t('help.title')}</h1>

                <h2 className="mt-10 text-lg font-semibold text-ink">{t('help.guides_heading')}</h2>

                <ul className="mt-4 grid gap-3 sm:grid-cols-2">
                    {guides.map((guide) => (
                        <li key={guide.key}>
                            <Link
                                href={guide.url}
                                className="block h-full rounded-card border border-line bg-card p-4 transition hover:border-ink"
                            >
                                <span className="font-medium text-ink">{t(`help.${guide.key}_title`)}</span>
                                <span className="mt-1 block text-sm text-ink-soft">
                                    {t(`help.${guide.key}_blurb`)}
                                </span>
                            </Link>
                        </li>
                    ))}
                </ul>

                {/*
                  A rule, not just space. Everything above answers "how does this
                  work"; everything below is for when the answer is "it does not".
                  Two different kinds of help stacked on one page need the seam
                  drawn, the same way the search rail draws it.

                  No heading and no preamble over the form. "Something wrong? A
                  price that is out of date, a dead link…" listed examples of
                  what to write directly above a box whose placeholder asks the
                  same question — two invitations for one field, and the reader
                  has to read both before typing a word. The placeholder does
                  the asking; the textarea keeps its screen-reader label, which
                  is the part a heading was carrying for people who cannot see
                  the box.
                */}
                <div className="mt-12 border-t border-line pt-10">
                    <FeedbackForm path={path} />
                </div>
            </div>
        </>
    )
}
