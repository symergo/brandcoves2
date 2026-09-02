import { usePage } from '@inertiajs/react'
import { useState } from 'react'
import type { SharedProps } from '../types'
import { useTranslations } from '../useTranslations'

export interface BoardMessage {
    id: number
    /** Typed per message: half the people here have no account to take one from. */
    name: string
    body: string
    /** ISO 8601, formatted in the reader's own market. */
    at: string | null
    mine: boolean
    /** Yours, or the list owner's to remove. Never who wrote it. */
    removable: boolean
}

export interface BoardState {
    canPost: boolean
    messages: BoardMessage[]
}

/**
 * The conversation beside a shared list.
 *
 * Everything the people around a list needed to say to each other happened in
 * the group chat the link was pasted into — a window with none of the facts in
 * it. So the page that knows what has been claimed, what the pot stands at and
 * what is still unspoken-for could not answer "shall we go halves on the
 * coat?", and the conversation that decides the buying ran somewhere else.
 *
 * ## One board, not comments per item
 *
 * A list is six cards, and a thread under each turns a page you scan into six
 * pages you read — the same reason the per-item pledge was dropped. The
 * conversation is about the list.
 *
 * ## Who sees it is not this component's business
 *
 * `board` arrives null for anybody who may not have one, and the page renders
 * no rail at all. On a wish list whose owner has not asked to see claims, that
 * is the owner: a board is claim state in prose, and this is the second place
 * that rule would be got wrong if it were asked here. See
 * App\Services\Wishlist\Board.
 */
export default function ListBoard({ board, action }: { board: BoardState; action: string }) {
    const { market, auth } = usePage<SharedProps>().props
    const { t } = useTranslations()

    const [body, setBody] = useState('')
    // Prefilled from the account when there is one, as the pledge form does:
    // a message is addressed to people, and most people sign their own name.
    const [name, setName] = useState(auth.user?.name ?? '')
    const [sending, setSending] = useState(false)

    /*
     * The thread, held here rather than read straight off the prop.
     *
     * Posting is a `fetch`, not an Inertia visit: a conversation that reloads
     * the page after every line is not a conversation, and nothing else on the
     * page changes because somebody typed a sentence — the products, the pot
     * and the claims are all exactly as they were. So the reply is appended and
     * a deletion is spliced out, and the page stays where the reader left it.
     *
     * Seeded from the prop and then owned locally. A later Inertia visit
     * remounts this component with the server's list, which is the reconciliation
     * — there is no long-lived divergence to manage.
     */
    const [messages, setMessages] = useState(board.messages)

    const csrf = () =>
        (document.querySelector('meta[name="csrf-token"]') as HTMLMetaElement | null)?.content ?? ''

    const post = async () => {
        if (body.trim() === '' || name.trim() === '') return

        setSending(true)

        try {
            const response = await fetch(action, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': csrf(),
                    // What makes the endpoint answer with the row rather than
                    // with a redirect.
                    Accept: 'application/json',
                },
                body: JSON.stringify({ body: body.trim(), display_name: name.trim() }),
            })

            if (!response.ok) return

            const data = await response.json()
            setMessages([...messages, data.message])
            setBody('')
        } finally {
            setSending(false)
        }
    }

    const remove = async (id: number) => {
        const response = await fetch(`${action}/${id}`, {
            method: 'DELETE',
            headers: { 'X-CSRF-TOKEN': csrf(), Accept: 'application/json' },
        })

        if (!response.ok) return

        setMessages(messages.filter((message) => message.id !== id))
    }

    return (
        <section className="rounded-card border border-line bg-card p-4">
            <h2 className="text-sm font-medium">{t('board.title')}</h2>
            <p className="mt-1 text-xs text-ink-soft">{t('board.hint')}</p>

            {messages.length === 0 ? (
                <p className="mt-4 text-sm text-ink-soft">{t('board.empty')}</p>
            ) : (
                <ul className="mt-4 space-y-4">
                    {messages.map((message) => (
                        <li key={message.id} className="group flex items-start gap-2">
                            <div className="min-w-0 flex-1">
                                <p className="flex items-baseline gap-2">
                                    <span className="text-sm font-medium">{message.name}</span>
                                    {message.at && (
                                        <span className="text-xs text-ink-soft">
                                            {new Date(message.at).toLocaleDateString(
                                                market.hrefLang,
                                                { day: 'numeric', month: 'short' },
                                            )}
                                        </span>
                                    )}
                                </p>

                                {/*
                                  `whitespace-pre-line`, and no markup at all.
                                  This is somebody's typing arriving through a
                                  link that can be forwarded anywhere; React
                                  escapes it, and the only formatting honoured
                                  is the line breaks they typed themselves.
                                */}
                                <p className="mt-0.5 text-sm whitespace-pre-line text-ink-soft">
                                    {message.body}
                                </p>
                            </div>

                            {/*
                              A bin at the right of the row, not the word
                              "Delete" under the message.

                              Under it, the word sat where the next message
                              begins and was read as part of the conversation —
                              a rail of four messages had four sentences and
                              four instructions interleaved. As an icon at the
                              end of the row it is where a per-row action
                              belongs and it stops competing with the writing.

                              The name is still spoken: `aria-label` and
                              `title`, so it is a labelled control for a screen
                              reader and a tooltip for everybody else. The same
                              treatment the list's own delete button gets in the
                              page header.
                            */}
                            {message.removable && (
                                <button
                                    type="button"
                                    onClick={() => remove(message.id)}
                                    aria-label={t('board.remove')}
                                    title={t('board.remove')}
                                    className="shrink-0 rounded p-1 text-ink-soft opacity-0 transition group-hover:opacity-100 hover:text-ink focus:opacity-100"
                                >
                                    <svg
                                        viewBox="0 0 24 24"
                                        className="h-4 w-4"
                                        fill="none"
                                        stroke="currentColor"
                                        strokeWidth={1.6}
                                        strokeLinecap="round"
                                        strokeLinejoin="round"
                                        aria-hidden
                                    >
                                        <path d="M4 7h16M9 7V5.5A1.5 1.5 0 0 1 10.5 4h3A1.5 1.5 0 0 1 15 5.5V7m2 0v12a1 1 0 0 1-1 1H8a1 1 0 0 1-1-1V7M10 11v6M14 11v6" />
                                    </svg>
                                </button>
                            )}
                        </li>
                    ))}
                </ul>
            )}

            {board.canPost && (
                <div className="mt-4 border-t border-line pt-4">
                    {/*
                      The name field, only when there is no account to take one
                      from. Asking a signed-in person to type the name we
                      already have is a field for nothing.
                    */}
                    {auth.user === null && (
                        <input
                            type="text"
                            value={name}
                            onChange={(e) => setName(e.target.value)}
                            maxLength={80}
                            placeholder={t('board.your_name')}
                            className="w-full rounded-lg border border-line bg-cream px-3 py-2 text-sm"
                        />
                    )}

                    <textarea
                        value={body}
                        onChange={(e) => setBody(e.target.value)}
                        rows={3}
                        maxLength={1000}
                        placeholder={t('board.placeholder')}
                        className={`w-full rounded-lg border border-line bg-cream p-3 text-sm ${
                            auth.user === null ? 'mt-2' : ''
                        }`}
                    />

                    <button
                        type="button"
                        onClick={post}
                        disabled={sending || body.trim() === '' || name.trim() === ''}
                        className="mt-2 w-full rounded-lg bg-ink px-3 py-2 text-sm text-cream disabled:opacity-50"
                    >
                        {t('board.post')}
                    </button>
                </div>
            )}
        </section>
    )
}
