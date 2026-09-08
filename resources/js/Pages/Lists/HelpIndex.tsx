import { Head, Link } from '@inertiajs/react'

interface Props {
    copy: { title: string; intro: string; cta_search: string; cta_lists: string }
    topics: { key: string; url: string; title: string; blurb: string }[]
    urls: { search: string; lists: string }
}

/**
 * How lists work: the index.
 *
 * Eight topics, in the order somebody meets the features, as numbered cards.
 * The numbers are the reading order for the person who has never saved
 * anything; everyone else scans the titles for the one thing they came to
 * ask. The words come from the server (`ListHelpController`), so this page
 * carries no strings of its own — see the controller for why the prose is not
 * in site.php.
 */
export default function ListHelpIndex({ copy, topics, urls }: Props) {
    return (
        <>
            <Head title={copy.title} />

            <div className="mx-auto max-w-3xl px-4 py-12">
                <h1 className="text-2xl font-semibold text-ink sm:text-3xl">{copy.title}</h1>
                <p className="mt-3 text-ink-soft">{copy.intro}</p>

                <ol className="mt-10 grid gap-3 sm:grid-cols-2">
                    {topics.map((topic, index) => (
                        <li key={topic.key}>
                            <Link
                                href={topic.url}
                                className="flex h-full gap-3 rounded-card border border-line bg-card p-4 transition hover:border-ink"
                            >
                                <span
                                    aria-hidden="true"
                                    className="flex h-7 w-7 shrink-0 items-center justify-center rounded-full bg-ink text-sm font-semibold text-card"
                                >
                                    {index + 1}
                                </span>
                                <span className="min-w-0">
                                    <span className="block font-medium text-ink">{topic.title}</span>
                                    <span className="mt-1 block text-sm text-ink-soft">{topic.blurb}</span>
                                </span>
                            </Link>
                        </li>
                    ))}
                </ol>

                <div className="mt-12 flex flex-wrap gap-3">
                    <Link
                        href={urls.search}
                        className="rounded-lg bg-accent px-4 py-2 font-medium text-white hover:bg-accent-dark"
                    >
                        {copy.cta_search}
                    </Link>
                    <Link
                        href={urls.lists}
                        className="rounded-lg border border-line px-4 py-2 font-medium hover:border-ink"
                    >
                        {copy.cta_lists}
                    </Link>
                </div>
            </div>
        </>
    )
}
