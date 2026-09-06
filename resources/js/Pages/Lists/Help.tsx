import { Head, Link } from '@inertiajs/react'
import { useTranslations } from '../../useTranslations'

interface Props {
    shots: { find: string; choose: string; lists: string }
    urls: { search: string; lists: string; gift: string }
}

/**
 * How lists work, in three steps and three pictures.
 *
 * ## Ordered the way it happens, not the way it is built
 *
 * A list has to exist before anything can go on it, so the tidy explanation
 * starts with "make a list". Nobody does that. People find a product they like
 * and *then* want to keep it, and the interface is built for that order — the
 * save panel offers to create a list at the moment you need one. So the page
 * follows the same order, and "how do I make a list" is answered inside step
 * two rather than parked in front of it as homework.
 *
 * ## Every step has a picture, and the picture carries the step
 *
 * Written instructions for an interface are read at the moment somebody has
 * already failed to find something, and prose describing a small round button
 * on a card is slower than a photograph of it. The numbers are on the text so
 * the two cannot drift apart when one is edited.
 *
 * The images come from `scripts/help-screenshots.mjs`, which drives the real
 * site — see `ListHelpController` for why they are per language and why Spanish
 * borrows the English set.
 */
export default function ListHelp({ shots, urls }: Props) {
    const { t } = useTranslations()

    const steps: { title: string; body: string; shot: string; alt: string }[] = [
        {
            title: t('lists_help.step_find'),
            body: t('lists_help.step_find_body'),
            shot: shots.find,
            alt: t('lists_help.step_find_alt'),
        },
        {
            title: t('lists_help.step_save'),
            body: t('lists_help.step_save_body'),
            shot: shots.choose,
            alt: t('lists_help.step_save_alt'),
        },
        {
            title: t('lists_help.step_open'),
            body: t('lists_help.step_open_body'),
            shot: shots.lists,
            alt: t('lists_help.step_open_alt'),
        },
    ]

    return (
        <>
            <Head title={t('lists_help.seo_title')} />

            <div className="mx-auto max-w-3xl px-4 py-12">
                <h1 className="text-2xl font-semibold text-ink sm:text-3xl">{t('lists_help.title')}</h1>
                <p className="mt-3 text-ink-soft">{t('lists_help.intro')}</p>

                <ol className="mt-12 space-y-14">
                    {steps.map((step, index) => (
                        <li key={step.shot}>
                            <div className="flex items-baseline gap-3">
                                <span
                                    aria-hidden="true"
                                    className="flex h-7 w-7 shrink-0 items-center justify-center rounded-full bg-ink text-sm font-semibold text-card"
                                >
                                    {index + 1}
                                </span>
                                <h2 className="text-lg font-semibold text-ink">{step.title}</h2>
                            </div>

                            <p className="mt-2 ml-10 text-ink-soft">{step.body}</p>

                            {/*
                              The border matters: these are screenshots of a
                              cream page shown on a cream page, and without an
                              edge they read as part of this document rather
                              than as a picture of another one.
                            */}
                            <img
                                src={step.shot}
                                alt={step.alt}
                                loading="lazy"
                                className="mt-4 ml-10 w-full rounded-lg border border-line shadow-sm"
                            />
                        </li>
                    ))}
                </ol>

                <h2 className="mt-16 text-lg font-semibold text-ink">{t('lists_help.making_title')}</h2>
                <p className="mt-2 text-ink-soft">{t('lists_help.making_body')}</p>

                <ul className="mt-4 list-disc space-y-2 pl-5 text-ink-soft">
                    <li>{t('lists_help.making_from_save')}</li>
                    <li>{t('lists_help.making_from_lists')}</li>
                </ul>

                {/*
                  Two facts people ask about before they trust a list with
                  anything: who can see it, and whether the person it is for
                  finds out what has been bought. Both are answered here rather
                  than left to the privacy page, because they are the reason
                  somebody hesitates at this exact moment.
                */}
                <h2 className="mt-12 text-lg font-semibold text-ink">{t('lists_help.private_title')}</h2>
                <p className="mt-2 text-ink-soft">{t('lists_help.private_body')}</p>

                <div className="mt-12 flex flex-wrap gap-3">
                    <Link
                        href={urls.search}
                        className="rounded-lg bg-accent px-4 py-2 font-medium text-white hover:bg-accent-dark"
                    >
                        {t('lists_help.cta_search')}
                    </Link>
                    <Link
                        href={urls.lists}
                        className="rounded-lg border border-line px-4 py-2 font-medium hover:border-ink"
                    >
                        {t('lists_help.cta_lists')}
                    </Link>
                </div>
            </div>
        </>
    )
}
