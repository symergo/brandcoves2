import { Link, router, usePage } from '@inertiajs/react'
import { type ReactNode, useState } from 'react'
import Badge from './Badge'
import Button, { fieldClasses } from './Button'
import InfoTip from './InfoTip'
import ListKindBadge, { kindIcons, type ListKind } from './ListKindBadge'
import ListName from './ListName'
import Menu, { MenuItem, MenuSeparator } from './Menu'
import { budgetLabel, DayMonth, InvitePerson, monthDay } from './PersonParts'
import ShareRow from './ShareRow'
import ToolIcon from './ToolIcon'
import type { Cents, SharedProps } from '../types'
import { formatBudget, formatDay } from '../types'
import { useTranslations } from '../useTranslations'

export interface Option {
    value: string
    label: string
}

export interface ProfileList {
    id: string
    title: string
    kind: ListKind
    url: string
    eventDate: string | null
}

export interface Profile {
    relationshipLabel: string | null
    /** `MM-DD`, never a year. */
    birthday: string | null
    isFriend: boolean
    about: {
        interests: Option[]
        vibe: string | null
        values: string[]
        ageBand: string | null
        avoid: string[]
        budgetMin: Cents | null
        budgetMax: Cents | null
        /** `self`: they said it themselves, through their own link. `suggested`: you did. */
        tasteSource: 'self' | 'suggested' | null
    }
    theirLists: ProfileList[]
    listsForThem: ProfileList[]
    /** Friends only; null for somebody without an account. See App\Services\Social\InCommon. */
    together: {
        /** Lists they are making for somebody else that reached you. */
        lists: (ProfileList & { forName: string | null })[]
        /** Group gifts you are both in. Their part is here only where the list would show it to you. */
        groups: (ProfileList & { forName: string | null; role: 'organiser' | 'contributes' })[]
        /** Secret Santa groups you are both in. Membership only, never the draw. */
        santa: { id: string; title: string; date: string | null; url: string }[]
    } | null
}

export interface ProfileOptions {
    interests: Option[]
    vibes: Option[]
    values: string[]
    ages: Option[]
    relationships: Option[]
}

export interface ProfilePerson {
    id: string
    name: string
    relationship: string | null
    birthday: { day: number; month: number } | null
    isLinked: boolean
}

export interface ProfileUrls {
    finder: string
    taste: string
    ask: string
    recipient: string
    people: string
    selfDescribe: string | null
    /** `POST /friends`, while no account is behind this person; null otherwise. */
    invite: string | null
}

/** Find a gift keeps a learned "not this interest" as `interest:<value>` in avoid. */
const LEARNED = 'interest:'

/** The server's cap on interests (RecipientTasteRequest), shown by refusing the ninth. */
const MAX_INTERESTS = 8

const chip = (on: boolean) =>
    `inline-flex min-h-11 items-center rounded-full border px-3 py-1 text-sm sm:min-h-0 ${
        on ? 'border-accent bg-accent/10 text-accent-dark' : 'border-line hover:border-ink'
    }`

/**
 * The top of a person's page: who they are, what you know about them, and
 * their lists.
 *
 * Until 2026-09-27 this page was "Cadeaus voor Mama": two buttons and a gift
 * history. What the site knew about her could only be seen inside Find a
 * gift. Now it is a profile, in the order the owner asked for: the person,
 * what you know (editable here), their own wish lists, the lists you are
 * making for them. Every section is left out when it would be empty.
 */
export default function PersonProfile({
    person,
    profile,
    options,
    urls,
    groupLists,
}: {
    person: ProfilePerson
    profile: Profile
    options: ProfileOptions
    urls: ProfileUrls
    groupLists: number
}) {
    const { market } = usePage<SharedProps>().props
    const { t } = useTranslations()
    const errors = usePage<SharedProps>().props.errors as Record<string, string> | undefined
    // One panel at a time, like the list page's tools: these are alternatives.
    // A refused delete comes back with its panel open, so the reason is seen.
    const [panel, setPanel] = useState<'details' | 'about' | 'link' | 'invite' | 'delete' | null>(errors?.person ? 'delete' : null)
    const toggle = (next: typeof panel) => setPanel(panel === next ? null : next)

    const about = profile.about
    // "Samen met": drawn only when there is something in it.
    const together =
        profile.together !== null &&
        profile.together.lists.length + profile.together.groups.length + profile.together.santa.length > 0
            ? profile.together
            : null
    const money = (cents: Cents) => formatBudget(cents, market)

    const interestLabel = (value: string) => options.interests.find((o) => o.value === value)?.label ?? value
    const avoidLabel = (word: string) => (word.startsWith(LEARNED) ? interestLabel(word.slice(LEARNED.length)) : word)
    const budget = budgetLabel(about.budgetMin, about.budgetMax, t, money)

    const facts: { label: string; items: string[] }[] = [
        { label: t('people.field_interests'), items: about.interests.map((i) => i.label) },
        { label: t('people.field_vibe'), items: about.vibe ? [options.vibes.find((v) => v.value === about.vibe)?.label ?? about.vibe] : [] },
        { label: t('people.field_values'), items: about.values.map((v) => t(`gift.values.${v}`)) },
        { label: t('people.field_age'), items: about.ageBand ? [options.ages.find((a) => a.value === about.ageBand)?.label ?? about.ageBand] : [] },
        { label: t('people.field_budget'), items: budget === null ? [] : [budget] },
        { label: t('people.field_avoid'), items: about.avoid.map(avoidLabel) },
    ].filter((fact) => fact.items.length > 0)

    const secondary = 'inline-flex min-h-11 items-center justify-center gap-2 rounded-lg border border-line px-3 py-2 text-sm hover:border-ink sm:min-h-0'
    const textButton = 'text-sm text-accent-dark underline underline-offset-2 hover:text-ink'

    // The link that lets them fill in their own taste, shown inside "Over"
    // because that is what it fills (owner's review, 2026-09-27).
    const linkPanel = panel === 'link' && urls.selfDescribe !== null && (
        <div className="mt-4 rounded-card border border-line bg-card p-4">
            <p className="flex flex-wrap items-center text-sm font-medium">
                {t('people.send_profile_link')}
                <InfoTip>{t('people.send_profile_link_tip', { name: person.name })}</InfoTip>
            </p>
            <div className="mt-3">
                <ShareRow url={urls.selfDescribe} text={t('recipients.ask_them')} />
            </div>
        </div>
    )

    return (
        <>
            {/*
              The owner's review of 2026-09-27 (a phone screenshot): seven equal
              buttons in four rows before anything about the person. Now each
              kind of action has one place:
              - ways to an idea: the row under the name (Cadeau vinden filled);
              - reaching them: "Niet op GiftCoves · Nodig uit" beside the name,
                where "op GiftCoves" stands for a friend;
              - their taste and the link to let them fill it in: "Over";
              - housekeeping (name, birthday, delete): the ⋯ menu, so the red
                button is not the fourth thing a visitor reads.
            */}
            <header>
                {/* The way back, drawn like a list's "← Mijn Coves" (owner, 2026-09-27). */}
                <Link href={urls.people} className="text-sm text-ink-soft hover:text-ink">
                    ← {t('people.title')}
                </Link>
                <div className="mt-1 flex items-start gap-3">
                    <span
                        aria-hidden
                        className="flex h-12 w-12 shrink-0 items-center justify-center rounded-full bg-accent/10 text-lg font-semibold text-accent"
                    >
                        {person.name.slice(0, 1).toUpperCase()}
                    </span>
                    <div className="min-w-0 flex-1">
                        <h1 className="text-2xl font-semibold tracking-tight break-words sm:text-3xl">{person.name}</h1>
                        <p className="mt-0.5 flex flex-wrap items-center gap-x-2 gap-y-1 text-sm text-ink-soft">
                            {profile.relationshipLabel !== null &&
                                profile.relationshipLabel.toLowerCase() !== person.name.toLowerCase() && <span>{profile.relationshipLabel}</span>}
                            {profile.birthday !== null ? (
                                <span className="inline-flex items-center">
                                    <ToolIcon name="cake" className="mr-1 h-4 w-4 shrink-0" />
                                    {formatDay(profile.birthday, market)}
                                </span>
                            ) : (
                                <button type="button" onClick={() => toggle('details')} className={`${textButton} inline-flex items-center`}>
                                    <ToolIcon name="cake" className="mr-1 h-4 w-4 shrink-0" />
                                    {t('friends.add_birthday')}
                                </button>
                            )}
                            {profile.isFriend ? (
                                <Badge tone="accent">{t('people.on_giftcoves')}</Badge>
                            ) : (
                                urls.invite !== null && (
                                    <span className="inline-flex items-center gap-1.5">
                                        <span>{t('people.not_on_giftcoves')}</span>
                                        <span aria-hidden>·</span>
                                        <button
                                            type="button"
                                            aria-expanded={panel === 'invite'}
                                            onClick={() => toggle('invite')}
                                            className={`${textButton} inline-flex items-center gap-1`}
                                        >
                                            <ToolIcon name="friends" className="h-4 w-4" />
                                            {t('people.invite_short')}
                                        </button>
                                    </span>
                                )
                            )}
                        </p>
                    </div>
                    <Menu
                        label={t('people.more_label', { name: person.name })}
                        button={<ToolIcon name="more" className="h-5 w-5" />}
                        buttonClassName="inline-flex h-11 w-11 shrink-0 items-center justify-center rounded-lg border border-line hover:border-ink sm:h-9 sm:w-9"
                    >
                        {(close) => (
                            <>
                                <MenuItem
                                    onSelect={() => {
                                        close()
                                        setPanel('details')
                                    }}
                                    icon={<ToolIcon name="edit" className="h-4 w-4" />}
                                >
                                    {t('people.edit_details')}
                                </MenuItem>
                                <MenuSeparator />
                                <MenuItem
                                    danger
                                    onSelect={() => {
                                        close()
                                        setPanel('delete')
                                    }}
                                    icon={<ToolIcon name="trash" className="h-4 w-4" />}
                                >
                                    {t('people.delete')}
                                </MenuItem>
                            </>
                        )}
                    </Menu>
                </div>

                {/*
                  The ways to an idea. On a phone the filled button takes the
                  row and the other two share the next one, so nothing wraps
                  into a ragged third line.
                */}
                <div className="mt-4 grid grid-cols-2 gap-2 sm:flex sm:flex-wrap">
                    <Link
                        href={urls.finder}
                        className="col-span-2 inline-flex min-h-11 items-center justify-center gap-2 rounded-lg bg-accent px-4 py-2 text-sm font-medium text-white hover:bg-accent-dark sm:min-h-0"
                    >
                        <ToolIcon name="whisperer" className="h-4 w-4" />
                        {t('people.find_gift')}
                    </Link>
                    <Link href={urls.taste} className={secondary}>
                        <ToolIcon name="taste" className="h-4 w-4" />
                        {t('people.taste')}
                    </Link>
                    <Link href={urls.ask} className={secondary}>
                        <ToolIcon name="board" className="h-4 w-4" />
                        {t('people.ask')}
                    </Link>
                </div>

                {panel === 'invite' && urls.invite !== null && (
                    <InvitePerson
                        url={urls.invite}
                        personId={person.id}
                        name={person.name}
                        birthday={profile.birthday}
                        onDone={() => setPanel(null)}
                    />
                )}

                {panel === 'details' && (
                    <DetailsForm person={person} options={options} url={urls.recipient} onDone={() => setPanel(null)} />
                )}

                {panel === 'delete' && (
                    <div className="mt-4 rounded-card border border-danger/40 bg-card p-4 text-sm" role="alertdialog" aria-labelledby="person-delete">
                        {groupLists > 0 || errors?.person ? (
                            <p id="person-delete">{errors?.person ?? t('people.delete_has_group', { name: person.name })}</p>
                        ) : (
                            <>
                                <p id="person-delete">{t('people.delete_confirm', { name: person.name })}</p>
                                <div className="mt-3 flex flex-wrap gap-2">
                                    <Button
                                        variant="danger"
                                        onClick={() => router.delete(urls.recipient, { data: { then: 'people' } })}
                                    >
                                        {t('people.delete_yes')}
                                    </Button>
                                    <Button variant="secondary" onClick={() => setPanel(null)}>
                                        {t('people.cancel')}
                                    </Button>
                                </div>
                            </>
                        )}
                    </div>
                )}
            </header>

            {/*
              What you know. Always drawn: with nothing in it, it is not an
              empty box but the question the page exists for, with the two ways
              to answer it (fill it in yourself, or let them). Until the owner's
              review this was a lone button floating under the actions.
            */}
            <Section
                title={t('people.about', { name: person.name })}
                tip={
                    about.tasteSource === 'self'
                        ? t('people.source_self_tip', { name: person.name })
                        : t('people.source_you_tip', { name: person.name })
                }
                aside={
                    facts.length > 0 && panel !== 'about' ? (
                        <>
                            {about.tasteSource !== null && (
                                <span className="text-xs text-ink-soft">
                                    {about.tasteSource === 'self' ? t('people.source_self', { name: person.name }) : t('people.source_you')}
                                </span>
                            )}
                            <button type="button" onClick={() => setPanel('about')} className={textButton}>
                                {t('people.edit')}
                            </button>
                        </>
                    ) : undefined
                }
            >
                {panel === 'about' ? (
                    <AboutForm person={person} profile={profile} options={options} url={urls.recipient} onDone={() => setPanel(null)} />
                ) : facts.length === 0 ? (
                    <div className="rounded-card border border-dashed border-line p-4">
                        <p className="text-sm text-ink-soft">{t('people.nothing_known', { name: person.name })}</p>
                        <div className="mt-3 flex flex-wrap gap-2">
                            <button type="button" onClick={() => setPanel('about')} className={secondary}>
                                <ToolIcon name="edit" className="h-4 w-4" />
                                {t('people.fill_in_yourself')}
                            </button>
                            {urls.selfDescribe !== null && (
                                <button type="button" aria-expanded={panel === 'link'} onClick={() => toggle('link')} className={secondary}>
                                    <ToolIcon name="link" className="h-4 w-4" />
                                    {t('people.let_them_fill_in', { name: person.name })}
                                </button>
                            )}
                        </div>
                        {linkPanel}
                    </div>
                ) : (
                    <>
                        <dl className="space-y-2">
                            {facts.map((fact) => (
                                <div key={fact.label} className="flex flex-wrap items-baseline gap-x-3 gap-y-1">
                                    <dt className="w-32 shrink-0 text-xs font-medium text-ink-soft">{fact.label}</dt>
                                    <dd className="flex min-w-0 flex-1 flex-wrap gap-1.5">
                                        {fact.items.map((item) => (
                                            <span key={item} className="rounded-full bg-line/40 px-2.5 py-0.5 text-sm">
                                                {item}
                                            </span>
                                        ))}
                                    </dd>
                                </div>
                            ))}
                        </dl>
                        {/* They have not said it themselves yet: offer the link that lets them. */}
                        {urls.selfDescribe !== null && about.tasteSource !== 'self' && (
                            <button type="button" aria-expanded={panel === 'link'} onClick={() => toggle('link')} className={`${textButton} mt-4 inline-flex items-center gap-1`}>
                                <ToolIcon name="link" className="h-4 w-4" />
                                {t('people.let_them_add', { name: person.name })}
                            </button>
                        )}
                        {linkPanel}
                    </>
                )}
            </Section>

            {profile.theirLists.length > 0 && (
                <Section title={t('people.their_wishlists', { name: person.name })} tip={t('people.their_wishlists_tip', { name: person.name })}>
                    <Lists lists={profile.theirLists} format={(iso) => formatDay(iso, market, { year: true })} external />
                </Section>
            )}

            {/*
              Your lists for them, with what you do to a list from here (owner,
              2026-09-27): share it, ask others about it, its settings, and a
              new list for them. Drawn even with no list yet, because "start a
              list for Bart" is exactly what this section is for.
            */}
            <Section
                title={t('people.lists_for', { name: person.name })}
                tip={t('people.lists_for_tip', { name: person.name })}
                aside={
                    <Menu
                        label={t('people.new_list', { name: person.name })}
                        button={
                            <>
                                <ToolIcon name="plus" className="h-4 w-4 shrink-0" />
                                <span>{t('people.new_list', { name: person.name })}</span>
                            </>
                        }
                        buttonClassName={`${secondary} gap-1.5`}
                    >
                        {(close) => (
                            <>
                                <MenuItem
                                    onSelect={() => {
                                        close()
                                        router.post(`/${market.key}/lists`, { recipient_id: person.id })
                                    }}
                                    icon={<ToolIcon name={kindIcons.for_someone} className="h-4 w-4" />}
                                >
                                    {t('lists.kind_for_someone')}
                                </MenuItem>
                                <MenuItem
                                    onSelect={() => {
                                        close()
                                        router.post(`/${market.key}/lists`, { recipient_id: person.id, together: true })
                                    }}
                                    icon={<ToolIcon name={kindIcons.group} className="h-4 w-4" />}
                                >
                                    {t('lists.kind_group')}
                                </MenuItem>
                            </>
                        )}
                    </Menu>
                }
            >
                {profile.listsForThem.length > 0 ? (
                    <Lists lists={profile.listsForThem} format={(iso) => formatDay(iso, market, { year: true })} actions />
                ) : (
                    <p className="text-sm text-ink-soft">{t('people.lists_for_none', { name: person.name })}</p>
                )}
            </Section>

            {together !== null && (
                <Section title={t('people.together_title', { name: person.name })} tip={t('people.together_tip', { name: person.name })}>
                    <ul className="divide-y divide-line rounded-card border border-line bg-card">
                        {together.groups.map((group) => (
                            <li key={`g:${group.id}`}>
                                <a href={group.url} className="flex flex-wrap items-center justify-between gap-2 px-4 py-3 text-sm hover:bg-line/20">
                                    <span className="min-w-0">
                                        <ListName name={group.title} kind={group.kind} />
                                        {group.forName && <span className="text-ink-soft"> · {t('people.for_whom', { name: group.forName })}</span>}
                                    </span>
                                    <span className="text-xs text-ink-soft">
                                        {t(group.role === 'organiser' ? 'people.role_organiser' : 'people.role_contributes', { name: person.name })}
                                        {group.eventDate && ` · ${formatDay(group.eventDate, market, { year: true })}`}
                                    </span>
                                </a>
                            </li>
                        ))}
                        {together.lists.map((list) => (
                            <li key={`l:${list.id}`}>
                                <a href={list.url} className="flex flex-wrap items-center justify-between gap-2 px-4 py-3 text-sm hover:bg-line/20">
                                    <span className="min-w-0">
                                        <ListName name={list.title} kind={list.kind} />
                                        {list.forName && <span className="text-ink-soft"> · {t('people.for_whom', { name: list.forName })}</span>}
                                    </span>
                                    <span className="text-xs text-ink-soft">
                                        {t('people.made_by', { name: person.name })}
                                        {list.eventDate && ` · ${formatDay(list.eventDate, market, { year: true })}`}
                                    </span>
                                </a>
                            </li>
                        ))}
                        {together.santa.map((group) => (
                            <li key={`s:${group.id}`}>
                                <Link href={group.url} className="flex flex-wrap items-center justify-between gap-2 px-4 py-3 text-sm hover:bg-line/20">
                                    <span className="inline-flex min-w-0 items-center gap-1.5 font-medium">
                                        <ToolIcon name="santa" className="h-4 w-4 shrink-0 text-accent" />
                                        {group.title}
                                    </span>
                                    <span className="text-xs text-ink-soft">
                                        {t('people.santa_both')}
                                        {group.date && ` · ${formatDay(group.date, market, { year: true })}`}
                                    </span>
                                </Link>
                            </li>
                        ))}
                    </ul>
                </Section>
            )}
        </>
    )
}

function Section({ title, tip, aside, children }: { title: string; tip?: string; aside?: ReactNode; children: ReactNode }) {
    return (
        <section className="mt-10">
            {/* items-start: an opened explanation grows the heading, and the button stays on the title's line. */}
            <div className="flex flex-wrap items-start justify-between gap-x-4 gap-y-1">
                <h2 className="flex items-center gap-1.5 text-lg font-medium">
                    {title}
                    {tip && <InfoTip>{tip}</InfoTip>}
                </h2>
                {aside && <div className="flex flex-wrap items-center gap-3">{aside}</div>}
            </div>
            <div className="mt-3">{children}</div>
        </section>
    )
}

function Lists({
    lists,
    format,
    external = false,
    actions = false,
}: {
    lists: ProfileList[]
    format: (iso: string) => string
    external?: boolean
    /** Your own lists: a ⋯ menu per row with what the list page's tools do. */
    actions?: boolean
}) {
    const { t } = useTranslations()
    const { market } = usePage<SharedProps>().props
    const row = 'flex min-w-0 flex-1 flex-wrap items-center justify-between gap-2 px-4 py-3 text-sm hover:bg-line/20'

    return (
        <ul className="divide-y divide-line rounded-card border border-line bg-card">
            {lists.map((list) => {
                /*
                  The kind as a word, not only an icon: two lists called "Voor
                  David" and "David" read as the same thing until one says
                  "Cadeaulijst" and the other "Samen geven" (owner's review).
                */
                // The kind right after the name (owner, 2026-09-27): it says
                // what the name is, so it is read with it, not at the far end.
                const body = (
                    <>
                        <span className="flex min-w-0 flex-wrap items-center gap-2">
                            <ListName name={list.title} kind={list.kind} />
                            <ListKindBadge kind={list.kind} />
                        </span>
                        {list.eventDate && <span className="text-xs text-ink-soft">{format(list.eventDate)}</span>}
                    </>
                )

                return (
                    <li key={list.id} className="flex items-center">
                        {/* Their lists open by share link, a page people often keep in a tab: a real anchor. */}
                        {external ? (
                            <a href={list.url} className={row}>
                                {body}
                            </a>
                        ) : (
                            <Link href={list.url} className={row}>
                                {body}
                            </Link>
                        )}
                        {/*
                          The list page's tools, one tap from here: each item
                          opens the list on that tool (`?panel=`).
                        */}
                        {actions && (
                            <span className="flex items-center pr-2">
                                {/* Share is the action people come for: its own icon; the rest under ⋯. */}
                                <Link
                                    href={`${list.url}?panel=share`}
                                    aria-label={`${t('lists.share')}: ${list.title}`}
                                    title={t('lists.share')}
                                    className="inline-flex h-11 w-11 items-center justify-center rounded-lg text-ink-soft hover:bg-line/40 hover:text-ink sm:h-8 sm:w-8"
                                >
                                    <ToolIcon name="shared" className="h-4 w-4" />
                                </Link>
                                <Menu
                                    label={t('people.list_actions', { name: list.title })}
                                    button={<ToolIcon name="more" className="h-4 w-4" />}
                                    buttonClassName="inline-flex h-11 w-11 items-center justify-center rounded-lg hover:bg-line/40 sm:h-8 sm:w-8"
                                >
                                    {() => (
                                        <>
                                            <MenuItem
                                                href={`/${market.key}/ask?list=${list.id}`}
                                                icon={<ToolIcon name="board" className="h-4 w-4" />}
                                            >
                                                {t('lists.ask_others')}
                                            </MenuItem>
                                            <MenuItem href={`${list.url}?panel=settings`} icon={<ToolIcon name="settings" className="h-4 w-4" />}>
                                                {t('lists.settings')}
                                            </MenuItem>
                                        </>
                                    )}
                                </Menu>
                            </span>
                        )}
                    </li>
                )
            })}
        </ul>
    )
}

/**
 * Name, relationship and birthday. The same PATCH /recipients/{id} as every
 * other place a saved person is edited, and the same rules.
 */
function DetailsForm({ person, options, url, onDone }: { person: ProfilePerson; options: ProfileOptions; url: string; onDone: () => void }) {
    const { t } = useTranslations()
    const [name, setName] = useState(person.name)
    const [relationship, setRelationship] = useState(person.relationship ?? '')
    const [day, setDay] = useState(person.birthday ? String(person.birthday.day).padStart(2, '0') : '')
    const [month, setMonth] = useState(person.birthday ? String(person.birthday.month).padStart(2, '0') : '')
    const [busy, setBusy] = useState(false)
    const errors = usePage<SharedProps>().props.errors as Record<string, string> | undefined

    // A relationship typed elsewhere as free text stays selectable as typed.
    const known = options.relationships.some((r) => r.value === relationship)
    const field = fieldClasses()

    return (
        <form
            onSubmit={(e) => {
                e.preventDefault()
                const md = monthDay(month, day)
                setBusy(true)
                router.patch(
                    url,
                    {
                        name: name.trim(),
                        relationship: relationship === '' ? null : relationship,
                        // Stored under the placeholder year 2000 (Recipient::BIRTHDAY_YEAR).
                        birthday: md === null ? null : `2000-${md}`,
                    },
                    { preserveScroll: true, onSuccess: onDone, onFinish: () => setBusy(false) },
                )
            }}
            className="mt-4 grid gap-4 rounded-card border border-line bg-card p-4 sm:grid-cols-3"
        >
            <label className="block text-xs font-medium">
                {t('people.name')}
                <input required maxLength={80} value={name} onChange={(e) => setName(e.target.value)} className={field} />
            </label>
            <label className="block text-xs font-medium">
                {t('people.relationship')}
                <select value={relationship} onChange={(e) => setRelationship(e.target.value)} className={field}>
                    <option value="">{t('people.relationship_none')}</option>
                    {!known && relationship !== '' && <option value={relationship}>{relationship}</option>}
                    {options.relationships.map((r) => (
                        <option key={r.value} value={r.value}>
                            {r.label}
                        </option>
                    ))}
                </select>
            </label>
            <label className="block text-xs font-medium">
                {t('people.birthday_optional')}
                <DayMonth day={day} month={month} onDay={setDay} onMonth={setMonth} />
            </label>
            <div className="flex flex-wrap gap-2 sm:col-span-3">
                <Button type="submit" busy={busy} disabled={name.trim() === ''}>
                    {t('people.save')}
                </Button>
                <Button type="button" variant="secondary" onClick={onDone}>
                    {t('people.cancel')}
                </Button>
                {errors?.name && (
                    <p className="w-full text-sm text-danger" role="alert">
                        {errors.name}
                    </p>
                )}
            </div>
        </form>
    )
}

/**
 * What you know, edited with Find a gift's own vocabularies, so a value
 * chosen here is the one the wizard and the engine read.
 *
 * Their taste (interests, style, what matters to them, what to avoid) is
 * theirs once they have said it themselves: the server then keeps their
 * answer and ignores a guess (Recipient::describeTaste). The form says so and
 * does not offer those fields, rather than accepting an edit that would
 * silently not be stored. Age and budget stay yours.
 */
function AboutForm({
    person,
    profile,
    options,
    url,
    onDone,
}: {
    person: ProfilePerson
    profile: Profile
    options: ProfileOptions
    url: string
    onDone: () => void
}) {
    const { t } = useTranslations()
    const about = profile.about
    const theirs = about.tasteSource === 'self'
    const [interests, setInterests] = useState<string[]>(about.interests.map((i) => i.value))
    const [vibe, setVibe] = useState<string | null>(about.vibe)
    const [values, setValues] = useState<string[]>(about.values)
    const [ageBand, setAgeBand] = useState<string | null>(about.ageBand)
    const [avoid, setAvoid] = useState<string[]>(about.avoid)
    const [min, setMin] = useState(about.budgetMin === null ? '' : String(about.budgetMin / 100))
    const [max, setMax] = useState(about.budgetMax === null ? '' : String(about.budgetMax / 100))
    const [ownWord, setOwnWord] = useState('')
    const [avoidWord, setAvoidWord] = useState('')
    const [busy, setBusy] = useState(false)

    const vocabulary = new Set(options.interests.map((o) => o.value))
    const ownWords = interests.filter((i) => !vocabulary.has(i))
    const full = interests.length >= MAX_INTERESTS
    const toggleIn = (list: string[], set: (next: string[]) => void, value: string) =>
        set(list.includes(value) ? list.filter((v) => v !== value) : [...list, value])
    const interestLabel = (value: string) => options.interests.find((o) => o.value === value)?.label ?? value

    const addOwn = () => {
        const word = ownWord.trim()

        if (word !== '' && !interests.includes(word) && !full) setInterests([...interests, word])
        setOwnWord('')
    }

    const addAvoid = () => {
        const word = avoidWord.trim()

        if (word !== '' && !avoid.includes(word)) setAvoid([...avoid, word])
        setAvoidWord('')
    }

    const euros = (value: string) => (value.trim() === '' ? null : Number(value))
    const field = fieldClasses()

    return (
        <form
            onSubmit={(e) => {
                e.preventDefault()
                setBusy(true)
                router.patch(
                    url,
                    {
                        age_band: ageBand,
                        // Euros here, cents in the column: RecipientTasteRequest converts.
                        budget_min: euros(min),
                        budget_max: euros(max),
                        ...(theirs ? {} : { interests, vibe, values, avoid }),
                    },
                    { preserveScroll: true, onSuccess: onDone, onFinish: () => setBusy(false) },
                )
            }}
            className="space-y-5 rounded-card border border-line bg-card p-4"
        >
            {theirs && (
                <p className="flex flex-wrap items-center text-sm text-ink-soft">
                    {t('people.source_self', { name: person.name })}
                    <InfoTip>{t('people.taste_is_theirs', { name: person.name })}</InfoTip>
                </p>
            )}

            {!theirs && (
                <fieldset>
                    <legend className="text-xs font-medium">{t('people.field_interests')}</legend>
                    <div className="mt-2 flex flex-wrap gap-1.5">
                        {options.interests.map((option) => {
                            const on = interests.includes(option.value)

                            return (
                                <button
                                    key={option.value}
                                    type="button"
                                    aria-pressed={on}
                                    disabled={!on && full}
                                    onClick={() => toggleIn(interests, setInterests, option.value)}
                                    className={`${chip(on)} disabled:opacity-40`}
                                >
                                    {option.label}
                                </button>
                            )
                        })}
                        {ownWords.map((word) => (
                            <button
                                key={word}
                                type="button"
                                aria-pressed
                                onClick={() => setInterests(interests.filter((i) => i !== word))}
                                className={chip(true)}
                                aria-label={t('people.remove_word', { word })}
                            >
                                {word}
                                <ToolIcon name="close" className="ml-1 h-3.5 w-3.5" />
                            </button>
                        ))}
                    </div>
                    <div className="mt-2 flex max-w-sm gap-2">
                        <input
                            value={ownWord}
                            maxLength={40}
                            disabled={full}
                            onChange={(e) => setOwnWord(e.target.value)}
                            onKeyDown={(e) => {
                                if (e.key === 'Enter') {
                                    e.preventDefault()
                                    addOwn()
                                }
                            }}
                            placeholder={t('gift.interests_other_placeholder')}
                            aria-label={t('gift.interests_other')}
                            className="min-w-0 flex-1 rounded-lg border border-line bg-cream px-3 py-2 text-sm"
                        />
                        <Button type="button" variant="secondary" size="sm" onClick={addOwn} disabled={full}>
                            {t('people.add_word')}
                        </Button>
                    </div>
                </fieldset>
            )}

            {!theirs && (
                <fieldset>
                    <legend className="text-xs font-medium">{t('people.field_vibe')}</legend>
                    <div className="mt-2 flex flex-wrap gap-1.5">
                        {options.vibes.map((option) => (
                            <button
                                key={option.value}
                                type="button"
                                aria-pressed={vibe === option.value}
                                onClick={() => setVibe(vibe === option.value ? null : option.value)}
                                className={chip(vibe === option.value)}
                            >
                                {option.label}
                            </button>
                        ))}
                    </div>
                </fieldset>
            )}

            {!theirs && (
                <fieldset>
                    <legend className="text-xs font-medium">{t('people.field_values')}</legend>
                    <div className="mt-2 flex flex-wrap gap-1.5">
                        {options.values.map((value) => (
                            <button
                                key={value}
                                type="button"
                                aria-pressed={values.includes(value)}
                                onClick={() => toggleIn(values, setValues, value)}
                                className={chip(values.includes(value))}
                            >
                                {t(`gift.values.${value}`)}
                            </button>
                        ))}
                    </div>
                </fieldset>
            )}

            <fieldset>
                <legend className="text-xs font-medium">{t('people.field_age')}</legend>
                <div className="mt-2 flex flex-wrap gap-1.5">
                    {options.ages.map((option) => (
                        <button
                            key={option.value}
                            type="button"
                            aria-pressed={ageBand === option.value}
                            onClick={() => setAgeBand(ageBand === option.value ? null : option.value)}
                            className={chip(ageBand === option.value)}
                        >
                            {option.label}
                        </button>
                    ))}
                </div>
            </fieldset>

            <fieldset className="grid max-w-sm grid-cols-2 gap-3">
                <legend className="col-span-2 text-xs font-medium">{t('people.field_budget')}</legend>
                <label className="block text-xs text-ink-soft">
                    {t('people.budget_min')}
                    <input type="number" min={0} max={100000} step={1} inputMode="numeric" value={min} onChange={(e) => setMin(e.target.value)} className={field} />
                </label>
                <label className="block text-xs text-ink-soft">
                    {t('people.budget_max')}
                    <input type="number" min={0} max={100000} step={1} inputMode="numeric" value={max} onChange={(e) => setMax(e.target.value)} className={field} />
                </label>
            </fieldset>

            {!theirs && (
                <fieldset>
                    <legend className="text-xs font-medium">{t('people.field_avoid')}</legend>
                    {avoid.length > 0 && (
                        <div className="mt-2 flex flex-wrap gap-1.5">
                            {avoid.map((word) => (
                                <button
                                    key={word}
                                    type="button"
                                    onClick={() => setAvoid(avoid.filter((w) => w !== word))}
                                    className={chip(true)}
                                    aria-label={t('people.remove_word', { word: word.startsWith(LEARNED) ? interestLabel(word.slice(LEARNED.length)) : word })}
                                >
                                    {word.startsWith(LEARNED) ? interestLabel(word.slice(LEARNED.length)) : word}
                                    <ToolIcon name="close" className="ml-1 h-3.5 w-3.5" />
                                </button>
                            ))}
                        </div>
                    )}
                    <div className="mt-2 flex max-w-sm gap-2">
                        <input
                            value={avoidWord}
                            maxLength={40}
                            onChange={(e) => setAvoidWord(e.target.value)}
                            onKeyDown={(e) => {
                                if (e.key === 'Enter') {
                                    e.preventDefault()
                                    addAvoid()
                                }
                            }}
                            placeholder={t('people.avoid_placeholder')}
                            aria-label={t('people.field_avoid')}
                            className="min-w-0 flex-1 rounded-lg border border-line bg-cream px-3 py-2 text-sm"
                        />
                        <Button type="button" variant="secondary" size="sm" onClick={addAvoid}>
                            {t('people.add_word')}
                        </Button>
                    </div>
                </fieldset>
            )}

            <div className="flex flex-wrap gap-2">
                <Button type="submit" busy={busy}>
                    {t('people.save')}
                </Button>
                <Button type="button" variant="secondary" onClick={onDone}>
                    {t('people.cancel')}
                </Button>
            </div>
        </form>
    )
}
