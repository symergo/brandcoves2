import { Head } from '@inertiajs/react'
import FeedbackForm from '../Components/FeedbackForm'
import { useTranslations } from '../useTranslations'

interface Props {
    /** The page they came from, prefilled and editable. Null if we could not tell. */
    path: string | null
}

/**
 * Tell us what is wrong.
 *
 * ## One required field
 *
 * The message. Everything else — the address, the page — is optional, and the
 * page is prefilled. A report form that opens on five required fields collects
 * reports from people who were already determined to file one, which is not the
 * population worth hearing from: the useful reports come from someone mildly
 * annoyed, thirty seconds before they give up and leave.
 *
 * ## The address says what it is for
 *
 * "Only to reply to this" is written next to the field rather than buried in
 * the privacy policy, because that is the question being asked at the moment the
 * cursor is in the box. It is optional and the form works without it.
 *
 * ## The honeypot
 *
 * `website` is hidden from people and from screen readers — `aria-hidden` plus
 * `tabIndex={-1}` — so anything in it was typed by something filling every input
 * on the page. It is not `display:none` alone: a field with no `autoComplete`
 * off can still be filled by a browser's own autofill, so it is named something
 * no autofill heuristic recognises as a real field of this form.
 */
export default function Feedback({ path }: Props) {
    const { t } = useTranslations()

    return (
        <>
            <Head title={t('feedback.title')} />

            <div className="mx-auto max-w-2xl px-4 py-10">
                {/*
                  The heading is the whole invitation, and nothing sits under it.

                  There used to be a paragraph listing four kinds of mistake —
                  a stale price, a dead link, the wrong brand, a machine-written
                  sentence — plus a heading above the box repeating the
                  question. Three pieces of copy in front of a form with one
                  field that matters, all of them saying "tell us what is
                  broken", on a page that also wants to hear that something is
                  good.
                */}
                <h1 className="text-3xl font-semibold tracking-tight">{t('feedback.title')}</h1>

                {/*
                  The confirmation is `FlashMessage`'s, and only its.

                  This page rendered `flash.status` itself as well, so sending
                  feedback printed "thanks, this arrived and somebody reads it"
                  twice, one under the other, in two different boxes. The layout
                  has drawn that channel since it was added; a page written
                  before that never lost its own copy.

                  What the local copy was for still holds and still happens: the
                  form stays open below the message rather than collapsing into
                  a thank-you, because somebody who has just reported one wrong
                  price often has a second one.
                */}
                <FeedbackForm path={path} />
            </div>
        </>
    )
}
