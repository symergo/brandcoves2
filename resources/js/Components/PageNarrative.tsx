import { Link } from '@inertiajs/react'
import { useTranslations } from '../useTranslations'

export interface Narrative {
    sections: { heading: string; body: string[] }[]
    faq: { q: string; a: string }[]
    related: { term: string; url: string }[]
}

/**
 * The long copy below a results grid.
 *
 * Rendered after the products, never before them. A shopper came for products,
 * and several hundred words between them and the first card is a worse page for
 * a human — which Google has been explicit about for years, so it is not even a
 * trade against ranking.
 *
 * The FAQ is plain markup rather than a `<details>` accordion. Collapsed answers
 * are still indexed, but they are also still hidden, and the point of putting
 * them on the page at all is that a reader can see the answer that the FAQPage
 * structured data claims is there.
 */
export default function PageNarrative({
    narrative,
    faqHeading,
    relatedHeading,
    relatedIntro,
}: {
    narrative: Narrative
    faqHeading: string
    relatedHeading: string
    relatedIntro: string
}) {
    const { t } = useTranslations()

    if (
        narrative.sections.length === 0 &&
        narrative.faq.length === 0 &&
        narrative.related.length === 0
    ) {
        return null
    }

    return (
        <div className="mt-16 border-t border-line pt-10">
            {narrative.sections.length > 0 && (
                <div className="grid gap-8 md:grid-cols-2 lg:grid-cols-3">
                    {narrative.sections.map((section) => (
                        <section key={section.heading}>
                            <h2 className="font-semibold">{section.heading}</h2>
                            <div className="mt-2 space-y-2 text-sm leading-relaxed text-ink-soft">
                                {section.body.map((paragraph) => (
                                    <p key={paragraph}>{paragraph}</p>
                                ))}
                            </div>
                        </section>
                    ))}
                </div>
            )}

            {narrative.faq.length > 0 && (
                <section className="mt-10" aria-labelledby="narrative-faq">
                    <h2 id="narrative-faq" className="text-xl font-semibold tracking-tight">
                        {faqHeading}
                    </h2>
                    <dl className="mt-4 grid gap-6 md:grid-cols-2">
                        {narrative.faq.map((item) => (
                            <div key={item.q}>
                                <dt className="font-medium">{item.q}</dt>
                                <dd className="mt-1 text-sm leading-relaxed text-ink-soft">{item.a}</dd>
                            </div>
                        ))}
                    </dl>
                </section>
            )}

            {narrative.related.length > 0 && (
                <section className="mt-10" aria-labelledby="narrative-related">
                    <h2 id="narrative-related" className="font-semibold">
                        {relatedHeading}
                    </h2>
                    <p className="mt-1 text-sm text-ink-soft">{relatedIntro}</p>
                    {/*
                      Real searches from our own log, not a keyword tool's
                      guesses — and the outbound links that stop this page being
                      a leaf a crawler reaches and stops at.
                    */}
                    <ul className="mt-3 flex flex-wrap gap-2">
                        {narrative.related.map((item) => (
                            <li key={item.url}>
                                <Link
                                    href={item.url}
                                    className="block rounded-full border border-line px-3 py-1.5 text-sm hover:border-ink"
                                >
                                    {item.term}
                                </Link>
                            </li>
                        ))}
                    </ul>
                </section>
            )}

            <p className="mt-8 text-xs text-ink-soft">{t('footer.affiliate')}</p>
        </div>
    )
}
