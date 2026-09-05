import { useTranslations } from '../useTranslations'
import { type Paragraph as ParagraphType, Paragraph } from './Parts'

export interface Narrative {
    sections: { heading: string; body: ParagraphType[] }[]
}

/**
 * The long copy below a results grid.
 *
 * Rendered after the products, never before them. A shopper came for products,
 * and several hundred words between them and the first card is a worse page for
 * a human — which Google has been explicit about for years, so it is not even a
 * trade against ranking.
 *
 * ## Everything here is editable now
 *
 * The sections, the questions, and the related-searches block at the end are all
 * `page_blocks` rows. This component knows only that a section has a heading and
 * some paragraphs; which sections exist, what they say, in what order, and
 * whether they appear at all is the editor's, in `/admin` → Page templates.
 *
 * That is why the FAQ `<dl>` is gone from here. A question is a heading and its
 * answer is the paragraph under it, so an editor builds one out of the two block
 * kinds they already have.
 *
 * The related-searches chips lived here the same way until 2026-09-05, when they
 * were removed outright — the trigram scan that drew them cost seconds on a cold
 * term. See docs/features/seo.md.
 *
 * A heading with no paragraphs under it never reaches this component: the server
 * drops the section, because a heading standing over nothing is not a shorter
 * page, it is a broken one.
 */
export default function PageNarrative({ narrative }: { narrative: Narrative }) {
    const { t } = useTranslations()

    if (narrative.sections.length === 0) {
        return null
    }

    return (
        <div className="mt-16 border-t border-line pt-10">
            <div className="grid gap-8 md:grid-cols-2 lg:grid-cols-3">
                {narrative.sections.map((section, i) => (
                    /*
                      Keyed by index, not by heading.
                      A heading can be empty — a section of paragraphs with no
                      title is legal — and two sections can legitimately share
                      one, at which point keying on the string breaks React's
                      reconciliation in a way that shows up as content swapping
                      between columns.
                    */
                    <section key={i}>
                        {section.heading !== '' && (
                            <h2 className="font-semibold">{section.heading}</h2>
                        )}
                        <div className="mt-2 space-y-2 text-sm leading-relaxed text-ink-soft">
                            {section.body.map((parts, j) => (
                                <Paragraph key={j} parts={parts} />
                            ))}
                        </div>
                    </section>
                ))}
            </div>

            <p className="mt-8 text-xs text-ink-soft">{t('footer.affiliate')}</p>
        </div>
    )
}
