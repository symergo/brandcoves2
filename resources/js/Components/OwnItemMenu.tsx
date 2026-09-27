import { router } from '@inertiajs/react'
import { useState } from 'react'
import type { CopyTarget } from './CopyToList'
import Menu, { MenuItem, MenuSeparator, MoreButtonContent } from './Menu'
import ToolIcon from './ToolIcon'
import { markSaved } from '../savedItems'
import { removeWithUndo } from '../pendingRemovals'
import { useTranslations } from '../useTranslations'

/**
 * The "⋯" on an item of your own list: edit, copy to another list, remove.
 *
 * ## Why not the bookmark (owner's audit, 2026-09-26)
 *
 * Every item on your own list showed a filled bookmark, because the item is by
 * definition saved — on this list. A bookmark answers "is this saved?", and on
 * your own list the answer is always yes, so every card carried the same green
 * mark saying nothing. What the owner does with an item here is different: fix
 * what they typed, put it on another list too, take it off. Those are three
 * actions, and a menu of actions is what a "⋯" means everywhere else.
 *
 * The bookmark stays everywhere it answers a real question: product cards,
 * other people's lists, and for a collaborator who adds to a list that is not
 * theirs.
 *
 * ## What each item does, unchanged from before
 *
 * - **Edit** — only on a hand-written item (`EditManualItem`, opened under the
 *   card). A catalogue item's title and price are what the feed said.
 * - **Copy to another list** — `ItemTransferController::between`, the endpoint
 *   `CopyToList` has always used for hand-written items. It now serves catalogue
 *   items here too, where the bookmark's picker did the same job: the copy keeps
 *   the note, and a product already on the target list is not doubled
 *   (`ItemMover::copy`). The saved-items cache is told, as `CopyToList` does.
 * - **Remove** — `DELETE /list-items/{id}`, the owner's alone, as it was from
 *   the bin (hand-written) and from unticking the bookmark (catalogue). No
 *   confirmation, as neither had one, and since 2026-09-27 an Undo instead:
 *   the item leaves the page at once and "Van … gehaald · Ongedaan maken"
 *   shows for six seconds before the request is sent (`pendingRemovals.ts`).
 *   The cache is told so a bookmark elsewhere does not still claim the
 *   product is here.
 */
export default function OwnItemMenu({
    base,
    listId,
    itemId,
    title,
    groupId,
    manual,
    targets,
    onEdit,
    listTitle,
}: {
    base: string
    listId: string
    /** The list's name, for "Van … gehaald" in the Undo message. */
    listTitle: string
    itemId: number
    title: string
    groupId: number | null
    manual: boolean
    /** Lists this person may write to, minus this one. */
    targets: CopyTarget[]
    onEdit: () => void
}) {
    const { t } = useTranslations()
    const [view, setView] = useState<'main' | 'copy' | 'new'>('main')
    const [name, setName] = useState('')
    const [sending, setSending] = useState(false)

    const copy = (close: () => void, to: string | null, newList?: string) => {
        setSending(true)
        router.post(
            `${base}/lists/${listId}/items/${itemId}/copy`,
            newList === undefined ? { to } : { new_list: newList },
            {
                preserveScroll: true,
                onSuccess: () => groupId !== null && markSaved(groupId, to),
                onFinish: () => {
                    setSending(false)
                    setName('')
                    close()
                },
            },
        )
    }

    return (
        <span className="relative z-20 inline-flex">
            <Menu
                label={t('lists.item_menu', { title })}
                view={view}
                width={256}
                onClose={() => {
                    setView('main')
                    setName('')
                }}
                button={<MoreButtonContent />}
                buttonClassName="flex h-10 w-10 items-center justify-center rounded-full border border-line bg-card/90 text-ink shadow-sm backdrop-blur transition hover:border-ink lg:h-9 lg:w-9"
            >
                {(close) =>
                    view === 'main' ? (
                        <>
                            {manual && (
                                <MenuItem
                                    icon={<ToolIcon name="edit" className="h-4 w-4" />}
                                    onSelect={() => {
                                        close()
                                        onEdit()
                                    }}
                                >
                                    {t('lists.edit_item')}
                                </MenuItem>
                            )}
                            {/* Nowhere to copy to is no item, not a dead one. */}
                            {targets.length > 0 && (
                                <MenuItem
                                    icon={<ToolIcon name="copy" className="h-4 w-4" />}
                                    onSelect={() => setView('copy')}
                                >
                                    <span className="flex items-center justify-between gap-2">
                                        {t('lists.copy_to')}
                                        <ToolIcon name="chevron" className="h-3.5 w-3.5 -rotate-90 text-ink-soft" />
                                    </span>
                                </MenuItem>
                            )}
                            {(manual || targets.length > 0) && <MenuSeparator />}
                            <MenuItem
                                danger
                                icon={<ToolIcon name="trash" className="h-4 w-4" />}
                                onSelect={() => {
                                    close()
                                    removeWithUndo({ base, listId, listTitle, itemId, groupId, t })
                                }}
                            >
                                {t('lists.remove_item')}
                            </MenuItem>
                        </>
                    ) : view === 'copy' ? (
                        <>
                            <p className="px-3 py-2 text-xs text-ink-soft">{t('lists.copy_to_which')}</p>
                            {targets.map((target) => (
                                <MenuItem key={target.id} disabled={sending} onSelect={() => copy(close, target.id)}>
                                    <span className="block truncate">{target.title}</span>
                                </MenuItem>
                            ))}
                            <MenuItem onSelect={() => setView('new')}>
                                <span className="text-accent">+ {t('lists.new_list')}</span>
                            </MenuItem>
                        </>
                    ) : (
                        /*
                          A list named on the spot, as `CopyToList` offers. A
                          text field inside a menu: the arrow keys are left to
                          the field (see `Menu`), Enter copies, Escape closes.
                        */
                        <form
                            className="p-2"
                            onSubmit={(e) => {
                                e.preventDefault()

                                if (name.trim() !== '') copy(close, null, name.trim())
                            }}
                        >
                            <label className="block text-xs font-medium">
                                {t('lists.list_name')}
                                <input
                                    autoFocus
                                    required
                                    maxLength={120}
                                    value={name}
                                    onChange={(e) => setName(e.target.value)}
                                    className="mt-1 w-full rounded border border-line bg-cream px-2 py-1.5 text-sm font-normal"
                                />
                            </label>
                            <button
                                type="submit"
                                disabled={sending}
                                className="mt-2 w-full rounded bg-accent px-2 py-1.5 text-sm font-medium text-white hover:bg-accent-dark disabled:opacity-50"
                            >
                                {t('lists.create')}
                            </button>
                        </form>
                    )
                }
            </Menu>
        </span>
    )
}
