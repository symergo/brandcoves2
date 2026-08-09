import { router } from '@inertiajs/react'
import { useState } from 'react'
import ShareRow from './ShareRow'
import { useTranslations } from '../useTranslations'

interface Collaborator {
    id: number
    name: string | null
    role: string
}

interface Suggestion {
    id: number
    title: string
    image: string | null
    price: number | null
    note: string | null
    from: string | null
}

interface Membership {
    groupId: string
    title: string
    attached: boolean
}

interface Props {
    base: string
    list: {
        id: string
        title: string
        kind: string
        claimable: boolean
        visibility: string
        shareUrl: string | null
        recipient: { name: string } | null
        eventType: string | null
        eventDate: string | null
    }
    access: { isOwner: boolean; canEdit: boolean }
    collaborators: Collaborator[]
    suggestions: Suggestion[]
    canHandOver: boolean
    handoverEmail: string | null
    registryOptions: { value: string; label: string }[]
    deliveryAddress: string | null
    quizUrl: string | null
    quizPlays: number
    santaMemberships: Membership[]
}

type Panel = 'share' | 'quiz' | 'registry' | 'people' | 'handover' | 'santa'

/**
 * Everything you can do with a list, behind one row of buttons.
 *
 * The page had grown a panel per feature — share, quiz, Secret Santa,
 * suggestions, registry, handover, co-givers — each permanently open, each with
 * a heading *and* a paragraph explaining itself. Nine tools' worth of prose sat
 * above the one thing the page is for, which is the list.
 *
 * The explanations are not gone; they moved inside the panel they belong to,
 * where the person who opened it is asking the question they answer. One panel
 * is open at a time, because these are alternatives rather than a checklist.
 *
 * Suggestions are the exception and stay visible: a pending suggestion is a
 * message somebody sent, and a message behind a button is a message missed.
 */
export default function ListTools({
    base,
    list,
    access,
    collaborators,
    suggestions,
    canHandOver,
    handoverEmail,
    registryOptions,
    deliveryAddress,
    quizUrl,
    quizPlays,
    santaMemberships,
}: Props) {
    const { t } = useTranslations()
    const [open, setOpen] = useState<Panel | null>(null)
    const [invite, setInvite] = useState('')
    const [role, setRole] = useState('viewer')
    const [handTo, setHandTo] = useState(handoverEmail ?? '')

    const shared = list.visibility !== 'private'

    const tabs: { key: Panel; label: string; show: boolean }[] = [
        { key: 'share', label: t('lists.share'), show: shared && Boolean(list.shareUrl) },
        { key: 'quiz', label: t('quiz.badge'), show: shared && list.claimable },
        { key: 'registry', label: t('registry.badge'), show: access.isOwner && list.kind === 'mine' },
        {
            key: 'people',
            label: t('lists.collaborators'),
            show: access.isOwner && list.kind === 'for_someone',
        },
        { key: 'handover', label: t('handover.badge'), show: canHandOver },
        { key: 'santa', label: t('santa.title'), show: santaMemberships.length > 0 },
    ]

    const visible = tabs.filter((tab) => tab.show)

    function toggle(panel: Panel) {
        setOpen((current) => (current === panel ? null : panel))
    }

    return (
        <div className="mt-6">
            {/*
              Pending suggestions stay in the open. Everything else is a thing
              you go looking for; this is a thing somebody sent you.
            */}
            {access.isOwner && suggestions.length > 0 && (
                <section className="mb-4 rounded-card border border-accent/40 bg-accent/5 p-4">
                    <h2 className="text-sm font-medium">{t('suggestions.heading')}</h2>

                    <ul className="mt-3 space-y-3">
                        {suggestions.map((s) => (
                            <li key={s.id} className="flex items-center gap-3">
                                {s.image && (
                                    <img src={s.image} alt="" loading="lazy" className="h-12 w-12 object-contain" />
                                )}
                                <div className="min-w-0 flex-1">
                                    <p className="truncate text-sm font-medium">{s.title}</p>
                                    {s.from && (
                                        <p className="text-xs text-ink-soft">
                                            {t('suggestions.from', { name: s.from })}
                                        </p>
                                    )}
                                </div>
                                <button
                                    type="button"
                                    onClick={() =>
                                        router.post(`${base}/suggestions/${s.id}/accept`, {}, { preserveScroll: true })
                                    }
                                    className="rounded-lg border border-sage px-3 py-1.5 text-xs text-sage"
                                >
                                    {t('suggestions.accept')}
                                </button>
                                <button
                                    type="button"
                                    onClick={() => router.delete(`${base}/suggestions/${s.id}`, { preserveScroll: true })}
                                    className="text-xs text-ink-soft hover:text-ink"
                                >
                                    {t('suggestions.dismiss')}
                                </button>
                            </li>
                        ))}
                    </ul>
                </section>
            )}

            {visible.length > 0 && (
                <div className="flex flex-wrap gap-2">
                    {visible.map((tab) => (
                        <button
                            key={tab.key}
                            type="button"
                            onClick={() => toggle(tab.key)}
                            aria-expanded={open === tab.key}
                            className={`rounded-full border px-3 py-1.5 text-sm transition ${
                                open === tab.key
                                    ? 'border-accent bg-accent/10 text-accent'
                                    : 'border-line hover:border-ink'
                            }`}
                        >
                            {tab.label}
                        </button>
                    ))}
                </div>
            )}

            {open !== null && (
                <div className="mt-3 rounded-card border border-line bg-card p-4">
                    {open === 'share' && list.shareUrl && (
                        <ShareRow
                            url={list.shareUrl}
                            text={t('lists.share_text', { title: list.title })}
                            hint={t('lists.sharing_on')}
                        />
                    )}

                    {open === 'quiz' && (
                        <div>
                            <h3 className="text-sm font-medium">{t('quiz.own_title')}</h3>

                            {quizUrl ? (
                                <>
                                    <p className="mt-1 text-xs text-ink-soft">
                                        {quizPlays > 0
                                            ? t('quiz.played', { count: String(quizPlays) })
                                            : t('quiz.created')}
                                    </p>
                                    <div className="mt-2">
                                        <ShareRow url={quizUrl} text={t('quiz.share_text')} />
                                    </div>
                                    <a
                                        href={quizUrl}
                                        target="_blank"
                                        rel="noopener noreferrer"
                                        className="mt-2 inline-block text-sm underline"
                                    >
                                        {t('quiz.open')}
                                    </a>
                                </>
                            ) : (
                                <>
                                    <p className="mt-1 text-xs text-ink-soft">{t('quiz.intro_own')}</p>
                                    <button
                                        type="button"
                                        onClick={() =>
                                            router.post(`${base}/lists/${list.id}/quiz`, {}, { preserveScroll: true })
                                        }
                                        className="mt-2 rounded-lg border border-line px-4 py-2 text-sm hover:border-ink"
                                    >
                                        {t('quiz.create')}
                                    </button>
                                </>
                            )}
                        </div>
                    )}

                    {open === 'registry' && (
                        <form
                            className="grid gap-3 sm:grid-cols-2"
                            onSubmit={(e) => {
                                e.preventDefault()
                                const data = new FormData(e.currentTarget)
                                router.patch(
                                    `${base}/lists/${list.id}`,
                                    {
                                        event_type: String(data.get('event_type') || ''),
                                        event_date: String(data.get('event_date') || ''),
                                        delivery_address: String(data.get('delivery_address') || ''),
                                    },
                                    { preserveScroll: true },
                                )
                            }}
                        >
                            <p className="text-xs text-ink-soft sm:col-span-2">{t('registry.hint')}</p>

                            <label className="block text-sm">
                                {t('registry.occasion')}
                                <select
                                    name="event_type"
                                    defaultValue={list.eventType ?? ''}
                                    className="mt-1 w-full rounded-lg border border-line px-3 py-2 text-sm"
                                >
                                    <option value="">{t('registry.none')}</option>
                                    {registryOptions.map((o) => (
                                        <option key={o.value} value={o.value}>
                                            {o.label}
                                        </option>
                                    ))}
                                </select>
                            </label>

                            <label className="block text-sm">
                                {t('registry.date')}
                                <input
                                    type="date"
                                    name="event_date"
                                    defaultValue={list.eventDate ?? ''}
                                    className="mt-1 w-full rounded-lg border border-line px-3 py-2 text-sm"
                                />
                            </label>

                            <label className="block text-sm sm:col-span-2">
                                {t('registry.address')}
                                <textarea
                                    name="delivery_address"
                                    rows={2}
                                    defaultValue={deliveryAddress ?? ''}
                                    className="mt-1 w-full rounded-lg border border-line px-3 py-2 text-sm"
                                />
                                <span className="mt-1 block text-xs text-ink-soft">
                                    {t('registry.address_hint')}
                                </span>
                            </label>

                            <button
                                type="submit"
                                className="justify-self-start rounded-lg border border-line px-4 py-2 text-sm sm:col-span-2"
                            >
                                {t('lists.save')}
                            </button>
                        </form>
                    )}

                    {open === 'people' && (
                        <div>
                            <p className="text-xs text-ink-soft">{t('lists.invite_hint')}</p>

                            {collaborators.length > 0 && (
                                <ul className="mt-3 space-y-2">
                                    {collaborators.map((c) => (
                                        <li key={c.id} className="flex items-center justify-between gap-3 text-sm">
                                            <span>
                                                {c.name}
                                                <span className="ml-2 text-xs text-ink-soft">
                                                    {c.role === 'editor'
                                                        ? t('lists.role_editor')
                                                        : t('lists.role_viewer')}
                                                </span>
                                            </span>
                                            <button
                                                type="button"
                                                onClick={() =>
                                                    router.delete(
                                                        `${base}/lists/${list.id}/collaborators/${c.id}`,
                                                        { preserveScroll: true },
                                                    )
                                                }
                                                className="text-xs text-ink-soft hover:text-ink"
                                            >
                                                {t('lists.remove')}
                                            </button>
                                        </li>
                                    ))}
                                </ul>
                            )}

                            <form
                                className="mt-3 flex flex-wrap gap-2"
                                onSubmit={(e) => {
                                    e.preventDefault()
                                    router.post(
                                        `${base}/lists/${list.id}/collaborators`,
                                        { email: invite, role },
                                        { preserveScroll: true, onSuccess: () => setInvite('') },
                                    )
                                }}
                            >
                                <input
                                    type="email"
                                    required
                                    value={invite}
                                    onChange={(e) => setInvite(e.target.value)}
                                    placeholder="name@example.com"
                                    className="min-w-0 flex-1 rounded-lg border border-line px-3 py-2 text-sm"
                                />
                                <select
                                    value={role}
                                    onChange={(e) => setRole(e.target.value)}
                                    className="rounded-lg border border-line px-2 py-2 text-sm"
                                >
                                    <option value="viewer">{t('lists.role_viewer')}</option>
                                    <option value="editor">{t('lists.role_editor')}</option>
                                </select>
                                <button type="submit" className="rounded-lg border border-line px-4 py-2 text-sm">
                                    {t('lists.invite_collaborator')}
                                </button>
                            </form>
                        </div>
                    )}

                    {open === 'handover' && (
                        <form
                            className="flex flex-wrap gap-2"
                            onSubmit={(e) => {
                                e.preventDefault()

                                if (confirm(t('handover.confirm', { name: handTo }))) {
                                    router.post(`${base}/lists/${list.id}/handover`, { email: handTo })
                                }
                            }}
                        >
                            <p className="w-full text-xs text-ink-soft">
                                {t('handover.hint', { name: list.recipient?.name ?? '' })}
                            </p>
                            <input
                                type="email"
                                required
                                value={handTo}
                                onChange={(e) => setHandTo(e.target.value)}
                                placeholder="name@example.com"
                                className="min-w-0 flex-1 rounded-lg border border-line px-3 py-2 text-sm"
                            />
                            <button
                                type="submit"
                                className="rounded-lg border border-line px-4 py-2 text-sm hover:border-ink"
                            >
                                {t('handover.action')}
                            </button>
                        </form>
                    )}

                    {open === 'santa' && (
                        <div>
                            <p className="text-xs text-ink-soft">{t('santa.attach_hint')}</p>
                            <ul className="mt-3 space-y-2">
                                {santaMemberships.map((m) => (
                                    <li key={m.groupId} className="flex items-center justify-between gap-3">
                                        <span className="text-sm">{m.title}</span>
                                        <button
                                            type="button"
                                            onClick={() =>
                                                router.post(
                                                    `${base}/santa/${m.groupId}/list`,
                                                    { wishlist_id: m.attached ? null : list.id },
                                                    { preserveScroll: true },
                                                )
                                            }
                                            className={`rounded-lg border px-3 py-1.5 text-xs ${
                                                m.attached
                                                    ? 'border-sage bg-sage/10 text-sage'
                                                    : 'border-line hover:border-ink'
                                            }`}
                                        >
                                            {m.attached ? t('santa.list_attached_short') : t('santa.attach_list')}
                                        </button>
                                    </li>
                                ))}
                            </ul>
                        </div>
                    )}
                </div>
            )}
        </div>
    )
}
