import { Link, router, usePage } from '@inertiajs/react'
import { useState } from 'react'
import InfoTip from './InfoTip'
import { useTranslations } from '../useTranslations'

/** Sent by WishlistController::publication(), to the owner only. */
export interface Publication {
    published: boolean
    /** An admin took it off the site; the owner cannot put it back. */
    hidden: boolean
    /** The public address, once it has one. */
    url: string | null
    /** The public title, or what we suggest before the first publish. */
    title: string
    showsOwner: boolean
    /** The first word of the name on the account, or null when it has none. */
    firstName: string | null
    /** What the page will say about who it is for: "For a dad", "Birthday". */
    about: string[]
    itemCount: number
    minItems: number
}

/**
 * Publish this list as a Community Cove (docs/features/community-coves.md).
 *
 * Its own section of the Share panel, below the link, because it is a
 * different act from sharing: the link goes to people you choose and lets
 * them claim; this puts a read-only copy where anybody can find it. Off until
 * pressed, and nothing about sharing turns it on.
 *
 * The section says what strangers will see before the press, since the one
 * mistake here is somebody thinking less is public than is.
 */
export default function PublishCove({ base, listId, state }: { base: string; listId: string; state: Publication }) {
    const { t } = useTranslations()
    const { errors } = usePage<{ errors: Record<string, string> }>().props
    const [title, setTitle] = useState(state.title)
    const [showOwner, setShowOwner] = useState(state.showsOwner)
    const [busy, setBusy] = useState(false)
    const tooFew = state.itemCount < state.minItems

    function publish(): void {
        setBusy(true)
        router.post(
            `${base}/lists/${listId}/publish`,
            { title, show_owner: showOwner },
            { preserveScroll: true, onFinish: () => setBusy(false) },
        )
    }

    function unpublish(): void {
        if (!confirm(t('community.unpublish_confirm'))) {
            return
        }

        setBusy(true)
        router.delete(`${base}/lists/${listId}/publish`, { preserveScroll: true, onFinish: () => setBusy(false) })
    }

    return (
        <section className="mt-6">
            <h3 className="flex flex-wrap items-center text-sm font-medium">
                {state.published && !state.hidden ? t('community.publish_on') : t('community.publish_heading')}
                <InfoTip className="ml-1">{t('community.publish_hint')}</InfoTip>
            </h3>

            {state.hidden ? (
                <p className="mt-1 text-xs text-danger">{t('community.hidden_by_admin')}</p>
            ) : (
                <>
                    <p className="mt-1 text-xs text-ink-soft">
                        {t('community.publish_shows', {
                            about: state.about.length > 0 ? state.about.join(', ') : t('community.a_list'),
                        })}
                    </p>

                    <label className="mt-3 block text-xs font-medium" htmlFor="public-title">
                        {t('community.public_title')}
                    </label>
                    <input
                        id="public-title"
                        type="text"
                        value={title}
                        maxLength={80}
                        onChange={(e) => setTitle(e.target.value)}
                        className="mt-1 w-full rounded-lg border border-line px-3 py-2 text-sm"
                    />
                    {errors?.title && <p className="mt-1 text-xs text-danger">{errors.title}</p>}

                    {state.firstName ? (
                        <label className="mt-3 flex items-center gap-2 text-sm">
                            <input type="checkbox" checked={showOwner} onChange={(e) => setShowOwner(e.target.checked)} />
                            {t('community.show_first_name', { name: state.firstName })}
                        </label>
                    ) : (
                        <p className="mt-3 text-xs text-ink-soft">{t('community.anonymous_no_name')}</p>
                    )}

                    {tooFew && !state.published && (
                        <p className="mt-3 text-xs text-ink-soft">{t('community.problem_items', { count: state.minItems })}</p>
                    )}

                    <div className="mt-3 flex flex-wrap items-center gap-3">
                        <button
                            type="button"
                            onClick={publish}
                            disabled={busy || (tooFew && !state.published) || title.trim() === ''}
                            className="rounded-lg bg-accent px-4 py-2 text-sm font-medium text-white hover:bg-accent-dark disabled:opacity-50"
                        >
                            {state.published ? t('community.update') : t('community.publish')}
                        </button>
                        {state.published && state.url && (
                            <Link href={state.url} className="text-sm text-accent-dark underline">
                                {t('community.view_public')}
                            </Link>
                        )}
                        {state.published && (
                            <button
                                type="button"
                                onClick={unpublish}
                                disabled={busy}
                                className="rounded-lg border border-line px-3 py-1.5 text-xs text-ink-soft hover:border-ink hover:text-ink"
                            >
                                {t('community.unpublish')}
                            </button>
                        )}
                    </div>
                </>
            )}
        </section>
    )
}
