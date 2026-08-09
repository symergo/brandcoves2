import { Head } from '@inertiajs/react'
import { useTranslations } from '../useTranslations'

interface Props {
    page: string
    title: string
    summary: string
    /** Rendered server-side from markdown we wrote. Not user input. */
    html: string
    updated: string | null
    /** True when this language has no translation yet and the English text is shown. */
    untranslated: boolean
}

/**
 * About, privacy and terms.
 *
 * `dangerouslySetInnerHTML` is safe here in the one case where that is true: the
 * HTML comes from markdown files in this repository, rendered server-side. There
 * is no user input anywhere in the path. The alternative — a markdown renderer in
 * the bundle — would ship a parser to every visitor to render three static pages.
 *
 * The prose styles are declared here rather than pulled from a typography plugin,
 * so these pages inherit the site's own type scale instead of a second one.
 */
export default function Legal({ title, summary, html, updated, untranslated }: Props) {
    const { t } = useTranslations()

    return (
        <>
            <Head title={title} />

            <article className="mx-auto max-w-2xl">
                <h1 className="text-3xl font-semibold tracking-tight sm:text-4xl">{title}</h1>
                {summary && <p className="mt-3 text-lg text-ink-soft">{summary}</p>}

                {updated && (
                    <p className="mt-2 text-sm text-ink-soft">
                        {t('legal.updated', { date: updated })}
                    </p>
                )}

                {/*
                  Said plainly rather than hidden. A legal page silently served in
                  the wrong language reads as an oversight; one that says it is the
                  English text pending translation is at least honest about what
                  the reader is looking at.
                */}
                {untranslated && (
                    <p className="mt-5 rounded-card border border-line bg-card p-4 text-sm text-ink-soft">
                        {t('legal.untranslated')}
                    </p>
                )}

                <div
                    className="
                        mt-8 leading-relaxed
                        [&_h2]:mt-10 [&_h2]:text-xl [&_h2]:font-semibold [&_h2]:tracking-tight
                        [&_h3]:mt-6 [&_h3]:font-semibold
                        [&_p]:mt-4
                        [&_ul]:mt-4 [&_ul]:list-disc [&_ul]:space-y-1 [&_ul]:pl-5
                        [&_li>p]:mt-0
                        [&_a]:text-accent [&_a]:underline
                        [&_strong]:font-semibold
                        [&_table]:mt-4 [&_table]:w-full [&_table]:text-sm
                        [&_td]:border-t [&_td]:border-line [&_td]:py-2 [&_td]:pr-4
                        [&_th]:hidden
                        [&_em]:italic
                    "
                    dangerouslySetInnerHTML={{ __html: html }}
                />
            </article>
        </>
    )
}
