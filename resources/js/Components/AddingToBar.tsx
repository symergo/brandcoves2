import { Link, usePage } from '@inertiajs/react'
import { addedCount, subscribe as subscribeToCount } from '../addingMode'
import { useSyncExternalStore } from 'react'
import type { SharedProps } from '../types'
import { useTranslations } from '../useTranslations'

/**
 * "Adding to Camping · 4 added · Done".
 *
 * The visible half of the adding mode, and the reason the mode is allowed to
 * live in the session at all. A session flag that silently changes where a
 * bookmark sends things would be a trap: you would find out days later by
 * looking at a list you did not mean to fill. A bar that is on every page for
 * as long as the mode is on cannot do that — the state is never further away
 * than the top of the screen, and the way out is in the same sentence.
 *
 * Sticky rather than fixed, so it does not sit on top of content on a short
 * page, and directly under the header so it reads as part of the chrome rather
 * than as something the page is saying.
 */
export default function AddingToBar() {
    const { savingTo, market } = usePage<SharedProps>().props
    const { t, n } = useTranslations()

    // Counted on the client: the server knows how many items the list holds,
    // not how many of them arrived during this run, and "4 added" is only
    // meaningful as the second thing.
    const added = useSyncExternalStore(subscribeToCount, addedCount, () => 0)

    if (!savingTo) return null

    return (
        <div className="sticky top-0 z-40 border-b border-sage/40 bg-sage/10">
            <div className="mx-auto flex max-w-6xl flex-wrap items-center gap-x-3 gap-y-1 px-4 py-2 text-sm">
                <span className="min-w-0 flex-1 truncate">
                    {t('lists.adding_to', { list: savingTo.title })}
                    {added > 0 && (
                        <span className="text-ink-soft">
                            {' · '}
                            {t('lists.added_count', { count: n(added) })}
                        </span>
                    )}
                </span>

                <Link
                    href={`/${market.key}/lists/${savingTo.id}`}
                    className="shrink-0 text-ink-soft hover:text-ink"
                >
                    {t('lists.view_list')}
                </Link>

                {/*
                  A plain link, because `done-adding` is a GET that clears the
                  caller's own session and sends them to the list. Nothing here
                  is worth a form: pressing it twice is idempotent, and the
                  worst a prefetch could do is end a mode the person is looking
                  at a button to end.
                */}
                <Link
                    href={`/${market.key}/done-adding`}
                    className="shrink-0 rounded-lg bg-accent px-3 py-1 text-xs font-medium text-white hover:bg-accent-dark"
                >
                    {t('lists.done_adding')}
                </Link>
            </div>
        </div>
    )
}
