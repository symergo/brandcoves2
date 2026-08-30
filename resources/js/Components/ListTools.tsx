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
        /** Is anybody else on this list? Most lists are private and solo. */
        hasCoGivers: boolean
        claimVisibility: string
        /** Whether the owner has asked to see what has been claimed. */
        ownerSeesClaims: boolean
        /** May somebody holding the link put things on the list? */
        linkCanAdd: boolean
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
    /*
     * Which panel is open is owned by the page, not by this row.
     *
     * Lifted when Share was a header button that had to open a panel down here.
     * That button has since moved into this row, so the state could come back —
     * it stays lifted because the page is the thing a future control (a
     * deep link, a flash message pointing at a panel) would reach for, and
     * moving it back and forth costs more than leaving it where it works.
     */
    panel: Panel | null
    onPanel: (panel: Panel | null) => void
}

export type Panel = 'share' | 'occasion' | 'quiz' | 'handover' | 'santa'

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
    panel: open,
    onPanel,
}: Props) {
    const { t } = useTranslations()
    const [handTo, setHandTo] = useState(handoverEmail ?? '')

    const shared = list.visibility !== 'private'

    // Only a wish list of your own is a registry; every kind may carry an
    // occasion. The delivery address is the half that stays behind this.
    const isRegistry = list.kind === 'mine'

    const tabs: { key: Panel; label: string; show: boolean }[] = [
        /*
         * Share sits in this row, always, and is the first thing in it.
         *
         * It used to appear here only once sharing was already on, with the
         * button that turns it on living up in the header beside Delete — a row
         * about administering the list. So the one control people came for was
         * in a different place before and after the single press that matters,
         * and the row of things you can do with a list did not include the main
         * one. `toggle()` below turns sharing on when it is off, so the button
         * means the same thing in both states.
         *
         * Kept visible to a collaborator on an already-shared list: they cannot
         * change visibility, but they can pass the link on.
         */
        { key: 'share', label: t('lists.share'), show: access.isOwner || (shared && Boolean(list.shareUrl)) },
        /*
         * A quiz asks "how well do you know **me**", so it only exists over a
         * wish list of your own.
         *
         * This was `shared && claimable`, which were the same thing as "mine"
         * until gift lists became claimable — at which point the tab appeared
         * on private research about a named person, offering to publish it as a
         * game. `ListQuizController` enforces the kind too; this is the mirror.
         */
        {
            key: 'quiz',
            label: t('quiz.badge'),
            show: shared && list.claimable && list.kind === 'mine',
        },
        /*
         * An occasion sits on any kind of list.
         *
         * This was `kind === 'mine'`, which made it the *registry* panel — and
         * the column, the validator and the shared page were all kind-agnostic
         * the whole time, so a birthday on a list about your father was storable
         * and renderable and simply had nowhere to be typed. Only the delivery
         * address inside the panel is registry-only; see the panel below.
         *
         * It was then folded into Share for a day and is a tab again. Setting
         * what a list is for is not the same errand as letting people see it —
         * you name the occasion months before you invite anybody, and often on
         * a list you never share at all — so under Share it was hidden behind a
         * word that means something else.
         *
         * Labelled with `registry.occasion` rather than `registry.badge`: a chip
         * in a scrolling row wants one word ("Gelegenheid"), and the panel it
         * opens carries the full "Speciale gelegenheid" as its heading.
         */
        { key: 'occasion', label: t('registry.occasion'), show: access.isOwner },
        { key: 'handover', label: t('handover.badge'), show: canHandOver },
        { key: 'santa', label: t('santa.title'), show: santaMemberships.length > 0 },
    ]

    /*
     * Ordered by what this kind is for, not by the order they were written in.
     *
     * A group list leads with the people, because it does nothing at all until
     * somebody else is on it. Everything else leads with Share. The array below
     * is a fixed order, so without this the tab that matters most for a kind
     * falls wherever it happens to.
     */
    const visible = tabs.filter((tab) => tab.show)

    function toggle(panel: Panel) {
        if (open === panel) {
            onPanel(null)

            return
        }

        /*
         * Opening this panel used to publish the list.
         *
         * That was right while the panel was only the link: sharing took two
         * presses in two places, people turned it on and left without the URL,
         * and collapsing them meant the button meant the same thing in both
         * states.
         *
         * It stopped being right when the panel absorbed the roster. An
         * invited sibling is something an owner adds to a list they have
         * **not** decided to share, and a tab that published the list as a
         * side effect of being opened would be a privacy change nobody asked
         * for, on the one page where privacy is the whole point.
         *
         * So the press is inside the panel now, where it is a button that says
         * what it does. The two-press objection is answered by that button
         * being the first thing in it rather than in a different place.
         */
        onPanel(panel)
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

                                    {/*
                                      Always attributed, even when we have no
                                      name.

                                      A suggestion may come from an anonymous
                                      cookie identity — somebody who followed
                                      the link and never signed up — and those
                                      arrived with `from: null` and rendered
                                      nothing at all. A message from nobody is
                                      worse than one from somebody unnamed, and
                                      the accept/dismiss decision is largely a
                                      judgement about who sent it.
                                    */}
                                    <p className="text-xs text-ink-soft">
                                        {s.from
                                            ? t('suggestions.from', { name: s.from })
                                            : t('suggestions.from_anonymous')}
                                    </p>

                                    {/*
                                      What they said about it.

                                      The field has been validated, stored and
                                      sent to the owner since the feature
                                      shipped, and rendered nowhere — so a note
                                      somebody wrote reached the payload and
                                      then vanished. Plain text, clamped, never
                                      a link: this is a stranger's writing.
                                    */}
                                    {s.note && (
                                        <p className="mt-1 line-clamp-2 text-xs text-ink-soft italic">
                                            {s.note}
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
                /*
                 * Scrolls rather than wraps on a phone. Four chips wrap to two
                 * rows on a narrow screen and push the list itself below the
                 * fold, which is the thing the page is for.
                 */
                <div className="-mx-1 flex gap-2 overflow-x-auto px-1 pb-1">
                    {visible.map((tab) => (
                        <button
                            key={tab.key}
                            type="button"
                            onClick={() => toggle(tab.key)}
                            aria-expanded={open === tab.key}
                            aria-controls="list-tools-panel"
                            className={`shrink-0 rounded-full border px-3 py-1.5 text-sm whitespace-nowrap transition ${
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
                <div id="list-tools-panel" className="mt-3 rounded-card border border-line bg-card p-4">
                    {open === 'share' && (
                        <div className="space-y-6">
                            {/*
                              The link, and the people who get it.

                              Share and People were two chips asking two halves
                              of one question — who else is looking at this
                              list — with the roster, the one thing a group
                              list cannot work without, filed furthest from the
                              button that shares it. They are one panel.

                              The occasion was folded in here too and has moved
                              back out to a chip of its own; see the `occasion`
                              tab. What varies is the sections, not the panel:
                              every kind gets the link, and only a list about
                              somebody else gets the people.
                            */}

                            {/*
                              Private lists land here too, now that this panel
                              is also where the roster lives — so it has to
                              offer the press rather than assume it has already
                              happened.
                            */}
                            {!list.shareUrl && access.isOwner && (
                                <div>
                                    <p className="text-xs text-ink-soft">{t('lists.share_hint')}</p>
                                    <button
                                        type="button"
                                        onClick={() =>
                                            router.patch(
                                                `${base}/lists/${list.id}`,
                                                { visibility: 'link' },
                                                { preserveScroll: true },
                                            )
                                        }
                                        className="mt-2 rounded-lg bg-accent px-4 py-2 text-sm font-medium text-white hover:bg-accent-dark"
                                    >
                                        {t('lists.share')}
                                    </button>
                                </div>
                            )}

                            {list.shareUrl && (
                            <div>
                            <ShareRow
                                url={list.shareUrl}
                                text={t('lists.share_text', { title: list.title })}
                                hint={t('lists.sharing_on')}
                            />

                            {/* Next to the link it revokes. In the header it was
                                a standing offer to break a link nobody was
                                looking at. */}
                            {access.isOwner && (
                                <button
                                    type="button"
                                    onClick={() =>
                                        router.patch(
                                            `${base}/lists/${list.id}`,
                                            { visibility: 'private' },
                                            { preserveScroll: true },
                                        )
                                    }
                                    className="mt-3 text-xs text-ink-soft underline hover:text-ink"
                                >
                                    {t('lists.disable_sharing')}
                                </button>
                            )}
                            </div>
                            )}

                            {/* Who else is on it — and, once somebody is, what
                                they can see of who claimed what. Every kind: a
                                wish list of your own is shared with people too,
                                and "can they see each other's claims" is a
                                question about those people rather than about
                                the kind of list. */}
                            {access.isOwner && (
                                <section className="border-t border-line pt-5">
                                    <h3 className="text-sm font-medium">{t('lists.collaborators')}</h3>
                            <div>
                                {/*
                                  Who sees who claimed what.

                                  In the People panel because it is a fact about the
                                  people, not about the list — and shown only once
                                  there ARE people. A privacy choice offered on a
                                  solo research list is a question about an audience
                                  of one, which reads as though somebody is already
                                  looking.

                                  **On a wish list of your own it is two options,
                                  not three.** Claims are hidden from you there
                                  whatever this says — invariant #4 is not a
                                  preference — so the third is not withheld, it
                                  is already permanently on. What remains is a
                                  real question and a useful one: can the people
                                  buying see each other's names, so two of them
                                  can settle it between themselves? They
                                  coordinate either way; names only make it
                                  easier, and you learn nothing from either
                                  answer.
                                */}
                                {list.claimable && list.hasCoGivers && (
                                <>
                                    {/*
                                      Two questions, and they are not the same
                                      question.

                                      They used to be one three-valued setting,
                                      in which "hide claims from me" was a value
                                      of a control otherwise about *names*. That
                                      made the third option mean something
                                      different depending on the kind of list,
                                      and made "show me claims, and let the
                                      others see each other's names" impossible
                                      to express at all.
                                    */}
                                    <fieldset className="mb-4 border-b border-line pb-4">
                                        <legend className="text-xs font-medium">
                                            {t('lists.claim_mine')}
                                        </legend>

                                        {/*
                                          Invariant #4 as a default rather than
                                          an absolute: a wish list hides by
                                          default because the surprise is the
                                          point, and nothing infers otherwise —
                                          only this press turns it on.
                                        */}
                                        <label className="mt-2 flex gap-2 text-sm">
                                            <input
                                                type="checkbox"
                                                className="mt-1"
                                                checked={list.ownerSeesClaims}
                                                onChange={(e) =>
                                                    router.patch(
                                                        `${base}/lists/${list.id}`,
                                                        { owner_sees_claims: e.target.checked },
                                                        { preserveScroll: true },
                                                    )
                                                }
                                            />
                                            <span>
                                                {t('lists.claim_mine_show')}
                                                <span className="block text-xs text-ink-soft">
                                                    {list.kind === 'mine'
                                                        ? t('lists.claim_mine_show_hint_mine')
                                                        : t('lists.claim_mine_show_hint_gift')}
                                                </span>
                                            </span>
                                        </label>
                                    </fieldset>

                                    <fieldset className="mb-4 border-b border-line pb-4">
                                        <legend className="text-xs font-medium">
                                            {t('lists.claim_privacy')}
                                        </legend>

                                        <div className="mt-2 space-y-2">
                                            {(['anonymous', 'named'] as const).map((value) => (
                                                <label key={value} className="flex gap-2 text-sm">
                                                    <input
                                                        type="radio"
                                                        name="claim_visibility"
                                                        className="mt-1"
                                                        checked={list.claimVisibility === value}
                                                        onChange={() =>
                                                            router.patch(
                                                                `${base}/lists/${list.id}`,
                                                                { claim_visibility: value },
                                                                { preserveScroll: true },
                                                            )
                                                        }
                                                    />
                                                    <span>
                                                        {t(`lists.claim_privacy_${value}`)}
                                                        {/* The consequence, not
                                                            the label: what is
                                                            being chosen is who
                                                            sees what. */}
                                                        <span className="block text-xs text-ink-soft">
                                                            {t(`lists.claim_privacy_${value}_hint`)}
                                                        </span>
                                                    </span>
                                                </label>
                                            ))}
                                        </div>
                                    </fieldset>
                                </>
                                )}

                                {/*
                                  Sharing is the link, not a list of addresses.

                                  This held a form asking for an email and a
                                  role, one person at a time, sitting directly
                                  under the share link that already grants the
                                  same thing to whoever it reaches. Two ways to
                                  let somebody in, one of which needed you to
                                  know their address and to do it again for each
                                  of them.

                                  The roster below survives it: nothing creates
                                  collaborators any more, and people who were
                                  granted access that way still have it, so the
                                  owner keeps a way to take it back.
                                */}
                                <p className="text-xs text-ink-soft">{t('lists.share_grants')}</p>

                                {/*
                                  The one right the link carries beyond looking
                                  and claiming, and the owner's to set.

                                  It was decided by kind — a list about somebody
                                  took additions straight on, a wish list queued
                                  them — which are good defaults and the wrong
                                  shape for the question. Whether you want
                                  additions turns on how well you know the
                                  people holding the link, and the kind cannot
                                  tell a family gift list from a wish list sent
                                  to forty colleagues.

                                  Off is the approval queue, not a refusal:
                                  what somebody adds goes where the owner can
                                  accept or dismiss it, which is what a wish
                                  list has always done.
                                */}
                                <label className="mt-3 flex gap-2 text-sm">
                                    <input
                                        type="checkbox"
                                        className="mt-1"
                                        checked={list.linkCanAdd}
                                        onChange={(e) =>
                                            router.patch(
                                                `${base}/lists/${list.id}`,
                                                { link_can_add: e.target.checked },
                                                { preserveScroll: true },
                                            )
                                        }
                                    />
                                    <span>
                                        {t('lists.link_can_add')}
                                        <span className="block text-xs text-ink-soft">
                                            {list.linkCanAdd
                                                ? t('lists.link_can_add_on')
                                                : t('lists.link_can_add_off')}
                                        </span>
                                    </span>
                                </label>

                                {collaborators.length > 0 && (
                                    <ul className="mt-3 space-y-2" aria-label={t('lists.invited_before')}>
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

                            </div>
                                </section>
                            )}
                        </div>
                    )}

                    {/*
                      Why the list exists, back behind a button of its own.

                      It spent a day as a section inside Share, on the argument
                      that "who is looking at this list" and "what are they
                      looking at it for" are one errand. They are related, but
                      they are not one press: the occasion is a thing you set
                      once, months before anybody is invited, and a private list
                      has one just as often as a shared one. Filing it under
                      Share hid it behind a word that means something else, and
                      left the panel three forms deep for the one person who
                      came to copy a link.

                      The button says Gelegenheid / Occasion — `registry.occasion`,
                      the same word the field inside it uses.
                    */}
                    {open === 'occasion' && (
                        <div>
                            <h3 className="text-sm font-medium">{t('registry.badge')}</h3>

                            <form
                                className="mt-3 grid gap-3 sm:grid-cols-2"
                                onSubmit={(e) => {
                                    e.preventDefault()
                                    const data = new FormData(e.currentTarget)
                                    router.patch(
                                        `${base}/lists/${list.id}`,
                                        {
                                            event_type: String(data.get('event_type') || ''),
                                            event_date: String(data.get('event_date') || ''),
                                            /*
                                             * Only when the field is on screen.
                                             *
                                             * `FormData.get` returns null for an
                                             * absent input, which becomes '' and
                                             * would *clear* a stored address every
                                             * time somebody edited the occasion on
                                             * a list that does not show the field.
                                             * Harmless today, since only a `mine`
                                             * list can have one — and exactly the
                                             * kind of thing that stops being
                                             * harmless the moment that changes.
                                             */
                                            ...(isRegistry
                                                ? {
                                                      delivery_address: String(
                                                          data.get('delivery_address') || '',
                                                      ),
                                                  }
                                                : {}),
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

                                {/*
                                  A registry, and only a registry.

                                  This is the owner's home address, and it is only
                                  ever appropriate on a list belonging to the person
                                  the parcel is for. A gift list about somebody else
                                  may carry an occasion and must never carry an
                                  address — which is why `Wishlist::isRegistry()`
                                  and `hasOccasion()` are two questions rather than
                                  one.
                                */}
                                {isRegistry && (
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
                                )}

                                <button
                                    type="submit"
                                    className="justify-self-start rounded-lg border border-line px-4 py-2 text-sm sm:col-span-2"
                                >
                                    {t('lists.save')}
                                </button>
                            </form>
                        </div>
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
