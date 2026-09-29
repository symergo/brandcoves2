import { Head, Link, router, usePage } from '@inertiajs/react'
import { Fragment, type ReactNode, useState } from 'react'
import type { Cents, SavingTo, SharedProps } from '../../types'
import { formatBudget, formatCountdown, formatDay, formatPrice } from '../../types'
import { useTranslations } from '../../useTranslations'
import { stashAskBrief } from '../../askBrief'
import CoveIcon from '../../Components/CoveIcon'
import ChipInput from '../../Components/ChipInput'
import GiftResults, { type GiftPick, type GiftResultsExtras } from '../../Components/GiftResults'
import InfoTip from '../../Components/InfoTip'
import type { SceneKey } from '../../Components/SceneIllustration'
import ShareRow from '../../Components/ShareRow'
import SaveToList from '../../Components/SaveToList'
import AddProduct from '../../Components/AddProduct'
import {
    HitList,
    HitRow,
    OwnItemFooter,
    SearchField,
    searchPanel,
    useGroupBadge,
    useListSearch,
} from '../../Components/ProductSearch'
import Badge from '../../Components/Badge'
import Button from '../../Components/Button'
import { send } from '../../http'
import ToolIcon from '../../Components/ToolIcon'
import GiftProfileCardBanner, { type GiftProfileCardProps } from '../../Components/GiftProfileCardBanner'
import PersonPicker, { type PickablePerson } from '../../Components/PersonPicker'

interface Option {
    value: string
    label: string
}

interface Recipient {
    id: string
    name: string
    relationship: string | null
    /** The relationship read as the closed vocabulary ("mama" as `mother`), or null. */
    relationshipType?: string | null
    interests: string[]
    vibe: string | null
    preferences: string[]
    budgetMin: Cents | null
    budgetMax: Cents | null
    avoid: string[]
    values: string[]
    ageBand: string | null
    /** Their own link (`/for/{token}`); null once an account is behind them. */
    selfUrl?: string | null
    personUrl?: string
}

interface Brief {
    interests?: string[]
    vibe?: string | null
    preferences?: string[]
    budget_min?: number | null
    budget_max?: number | null
    avoid?: string[]
    values?: string[]
    relationship?: string | null
    occasion?: string | null
    age_band?: string | null
    recipient_id?: string | null
    /** "Voor mezelf": the answers describe the visitor, not somebody else. */
    for_me?: boolean
}

/** A persona Cove: the third way in, "Start from a type". */
interface PersonaCard {
    title: string
    intro: string | null
    url: string
    scene: SceneKey | null
    /** Who its plan was written for, when it was written for somebody in particular. */
    relationship?: string | null
}

interface Props extends GiftResultsExtras {
    /** The persona Coves for "Start from a type"; empty means that way is not offered. */
    personas?: PersonaCard[]
    options: {
        interests: Option[]
        vibes: Option[]
        /** Taste as axes: each one drawn as its two ends, so picking a side is one click. */
        preferences: { axis: string; poles: [Option, Option] }[]
        ages: Option[]
        /** "Who is it for?" without a saved person: the closed vocabulary. */
        relationships?: Option[]
    }
    recipients: Recipient[]
    picks: GiftPick[] | null
    brief: Brief | null
    /** The chosen person's list, where a save lands. Null without a person. */
    recipientList: SavingTo | null
    /** Opened from somebody's gift profile card: the answers below come from it. */
    card?: GiftProfileCardProps | null
    /** This or that, the second way in. */
    tasteUrl?: string
    /** Your own "Mijn smaak", for "Voor mezelf"; null when you keep none or are not signed in. */
    myTaste?: {
        interests: string[]
        vibe: string | null
        preferences: string[]
        values: string[]
        avoid: string[]
        ageBand: string | null
    } | null
    /**
     * Everybody you buy for, as My people lists them: saved people and
     * friends, one row each, drawn by `PersonPicker`. Signed-in only.
     */
    people?: PickablePerson[]
}

/*
  The questions, after "Who is it for?" and the choice of way.

  No values step (owner's call, 2026-09-14): a saved person still carries
  values from their own page and the brief picks them up server-side. No
  "who" step either since "Find a gift" became one flow (2026-09-26): who it
  is for is the flow's first question, asked once for all three ways.
*/
const STEPS = ['interests', 'age', 'vibe', 'budget', 'avoid'] as const

/** The server caps a brief at eight interests; refusing the ninth here is the only visible place. */
const MAX_INTERESTS = 8

/**
 * How many persona Coves the "Start from a type" list offers before "All".
 * Four while they were cards in the card; a dropdown holds more without
 * growing the page (owner's review, 2026-09-27).
 */
const TYPES_SHOWN = 30

/** One way to an idea: every card in the two rows is drawn the same. */
const wayCard = 'group flex flex-col rounded-card border border-line bg-card p-5 text-left transition hover:border-ink'

/** The icon beside the title rather than above it: a shorter card says the same. */
function WayHead({ icon, title }: { icon: ReactNode; title: string }) {
    return (
        <span className="flex items-center gap-3">
            <span className="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-accent/10 text-accent">{icon}</span>
            <span className="font-medium text-ink">{title}</span>
        </span>
    )
}

/**
 * "Weet je al wat je zoekt?" (owner, 2026-09-27): a search that puts things
 * straight on the list for this person, above the ways, full width.
 *
 * The list for a saved person is fetched (and made, the first time) when the
 * button is pressed, not when the page opens: choosing a person must not make
 * a list by itself. Then it is the list page's own add panel (AddProduct), so
 * catalogue results, shops we do not mirror and something typed by hand all
 * work here as they do there; the server's toast names the list. A
 * relationship ("Collega") gets the same, through a person saved under that
 * name on the first press. Only when nobody is chosen, or the visitor is not
 * signed in, does the card search in place with the save picker per result.
 */
function SearchToListCard({
    recipient: person,
    kind,
    kindLabel,
    market,
}: {
    recipient: Recipient | null
    /** A relationship picked instead of a saved person ("Collega"). */
    kind: string | null
    kindLabel: string | null
    market: SharedProps['market']
}) {
    const { t } = useTranslations()
    const { auth } = usePage<SharedProps>().props
    const base = `/${market.key}`
    /*
     * Who the list is for. A saved person, or (owner, 2026-09-27: "there is a
     * person picked, either a friend or a relationship") a relationship, which
     * the server turns into a saved person on the first press. Only "skip" and
     * a visitor who is not signed in (who cannot have saved people) search
     * without a list, with the save picker on each result.
     */
    const recipient: { name: string } | null =
        person ?? (kind !== null && kindLabel !== null && auth.user !== null ? { name: kindLabel } : null)
    const listUrl = person ? `${base}/people/${person.id}/list` : `${base}/people/for-relationship/list`
    const [listId, setListId] = useState<string | null>(null)
    // What the list's own panel starts with once it has the list: the first
    // search, or "something typed by hand".
    const [handOver, setHandOver] = useState<{ term: string; manual: boolean } | null>(null)
    const [term, setTerm] = useState('')
    const [busy, setBusy] = useState(false)
    const [failed, setFailed] = useState(false)

    /*
     * The card looks like a list's search box from the start (owner,
     * 2026-09-27: "a search card like the ones used on lists"). The first
     * search, or the offline link, fetches the list for this person (made the
     * first time) and hands over to that list's own panel, which runs it.
     */
    const start = (next: { term: string; manual: boolean }) => {
        if (recipient === null) {
            return
        }
        if (listId !== null) {
            setHandOver(next)
            return
        }
        setBusy(true)
        setFailed(false)
        send<{ id: string }>(listUrl, 'POST', person ? undefined : { relationship: kind })
            .then((list) => {
                setListId(list.id)
                setHandOver(next)
            })
            .catch(() => setFailed(true))
            .finally(() => setBusy(false))
    }

    return (
        <div className={`${wayCard} mt-4 hover:border-line`}>
            <WayHead icon={<ToolIcon name="search" className="h-5 w-5" />} title={t('gift.way_search')} />
            {recipient !== null ? (
                <>
                    <span className="mt-1 text-sm text-ink-soft">{t('gift.way_search_hint', { name: recipient.name })}</span>
                    {handOver !== null && listId !== null ? (
                        <div className="mt-4">
                            <AddProduct
                                // A new panel per hand-over, so its start state applies.
                                key={`${handOver.manual ? 'manual' : 'search'}:${handOver.term}`}
                                base={base}
                                listId={listId}
                                market={market}
                                defaultOpen
                                onListPage={false}
                                initialTerm={handOver.term}
                                startManual={handOver.manual}
                                onClose={() => setHandOver(null)}
                            />
                        </div>
                    ) : (
                        // AddProduct's own field and footer (ProductSearch), so nothing changes when it takes over.
                        <div className={`mt-4 ${searchPanel}`}>
                            <SearchField
                                value={term}
                                onChange={setTerm}
                                onSearch={(q) => {
                                    if (q.trim() !== '') start({ term: q, manual: false })
                                }}
                                busy={busy}
                            />
                            {busy && <p className="mt-3 text-sm text-ink-soft">{t('search.searching')}</p>}
                            {failed && (
                                <p className="mt-3 text-sm text-danger" role="alert">
                                    {t('gift.way_search_failed')}
                                </p>
                            )}
                            <OwnItemFooter disabled={busy} onClick={() => start({ term: '', manual: true })} />
                        </div>
                    )}
                </>
            ) : (
                <>
                    <span className="mt-1 text-sm text-ink-soft">{t('gift.way_search_hint_none')}</span>
                    <InlineSearch base={base} signedIn={auth.user !== null} />
                </>
            )}
        </div>
    )
}

/**
 * The search card without a list: nobody chosen ("skip"), or a visitor who is
 * not signed in.
 *
 * The same field and the same rows as a list's add panel (`ProductSearch`,
 * owner, 2026-09-27: "the inline searches should be the same as the one on
 * the list pages"): catalogue results, shops we do not mirror, and a pasted
 * link. What differs is only the press. There is no list to put anything on
 * yet, so each row carries the site's save button, which asks which list (or
 * starts one); the full search page is one link away for more. No "offline
 * article" footer: the typed-by-hand form belongs to a list, and here there
 * is none to put it on.
 *
 * Signed out, the search leads to /search instead of searching here. The
 * in-place search (`/list-search`) is behind sign-in, as a list's is (it
 * reads shops live, and AddProductTest keeps it so); until 2026-09-27 this
 * card called it anyway, got a refusal and said "nothing found".
 */
function InlineSearch({ base, signedIn }: { base: string; signedIn: boolean }) {
    const { t } = useTranslations()
    const found = useListSearch(base)
    const groupBadge = useGroupBadge()
    const { term, setTerm, groups, live, link, searching } = found

    return (
        <div className={`mt-4 ${searchPanel}`}>
            <SearchField
                value={term}
                onChange={setTerm}
                onSearch={(q) =>
                    signedIn
                        ? found.search(q)
                        : q.trim() !== '' && router.get(`${base}/search`, { q: q.trim() })
                }
                busy={searching}
            />

            {searching && <p className="mt-3 text-sm text-ink-soft">{t('search.searching')}</p>}

            {found.nothingFound && (
                <p className="mt-3 text-sm text-ink-soft">{t('lists.add_nothing_found', { term: term.trim() })}</p>
            )}
            {found.failed && <p className="mt-3 text-sm text-danger">{t('lists.search_failed')}</p>}
            {found.linkRefused && <p className="mt-3 text-sm text-danger">{t('lists.link_refused')}</p>}

            {!searching && (groups.length > 0 || live.length > 0 || link !== null) && (
                <>
                    <HitList>
                        {groups.map((hit) => (
                            <HitRow
                                key={`g${hit.id}`}
                                image={hit.image}
                                title={hit.title}
                                price={hit.price}
                                badge={groupBadge(hit)}
                                href={`${base}/p/${hit.id}`}
                                action={<SaveToList groupId={hit.id} title={hit.title} imageUrl={hit.image} price={hit.price} compact />}
                            />
                        ))}
                        {live.map((hit) => (
                            <HitRow
                                key={`l${hit.source}-${hit.externalId}`}
                                image={hit.image}
                                title={hit.title}
                                price={hit.price}
                                badge={hit.merchant}
                                action={
                                    <SaveToList
                                        source={hit.source}
                                        externalId={hit.externalId}
                                        title={hit.title}
                                        imageUrl={hit.image}
                                        price={hit.price}
                                        compact
                                    />
                                }
                            />
                        ))}
                        {/* A link nothing we hold matched: saved as it is, and
                            its page is read afterwards, as on a list. */}
                        {link !== null && (
                            <HitRow
                                key={`u${link.url}`}
                                image={null}
                                title={link.host}
                                price={null}
                                action={<SaveToList url={link.url} compact />}
                            />
                        )}
                    </HitList>
                    {groups.length > 0 && (
                        <Link
                            href={`${base}/search?q=${encodeURIComponent(term.trim())}`}
                            className="mt-3 inline-block text-sm font-medium text-accent-dark hover:text-ink"
                        >
                            {t('gift.way_search_all')} →
                        </Link>
                    )}
                </>
            )}
        </div>
    )
}

/**
 * "Vraag het {naam} zelf" (owner, 2026-09-27): let the person themselves say
 * it, on their own link, where they play This or that, suggest gifts and say
 * "this is me" (which connects them to you, so their own wish lists show).
 *
 * Three states: a saved person with no account (the link, to copy or share),
 * one with an account (their page, where their lists and answers already
 * are), and nobody saved yet (the way to save them, since the link belongs
 * to a saved person).
 */
function AskThemCard({ recipient, peopleUrl }: { recipient: Recipient | null; peopleUrl: string }) {
    const { t } = useTranslations()
    const icon = <ToolIcon name="link" className="h-5 w-5" />

    if (recipient !== null && recipient.selfUrl) {
        return (
            <div className={wayCard}>
                <WayHead icon={icon} title={t('gift.ask_them_title', { name: recipient.name })} />
                <span className="mt-1 text-sm text-ink-soft">{t('gift.ask_them_hint', { name: recipient.name })}</span>
                <div className="mt-auto pt-4">
                    <ShareRow url={recipient.selfUrl} text={t('recipients.ask_them')} />
                </div>
            </div>
        )
    }

    if (recipient !== null && recipient.personUrl) {
        return (
            <Link href={recipient.personUrl} className={wayCard}>
                <WayHead icon={icon} title={t('gift.ask_them_linked_title', { name: recipient.name })} />
                <span className="mt-1 text-sm text-ink-soft">{t('gift.ask_them_linked_hint', { name: recipient.name })}</span>
                <span className="mt-auto pt-4 text-sm font-medium text-accent-dark">
                    {t('gift.ask_them_linked_cta', { name: recipient.name })} →
                </span>
            </Link>
        )
    }

    return (
        <Link href={peopleUrl} className={wayCard}>
            <WayHead icon={icon} title={t('gift.ask_them_none_title')} />
            <span className="mt-1 text-sm text-ink-soft">{t('gift.ask_them_none_hint')}</span>
            <span className="mt-auto pt-4 text-sm font-medium text-accent-dark">{t('gift.ask_them_none_cta')} →</span>
        </Link>
    )
}

/**
 * "Find a gift": one flow, one results page.
 *
 * 1. **Who is it for?** One of your saved people, or a kind of person
 *    (partner, mum, a colleague), or skip it.
 * 2. **Three ways, side by side**: answer a few questions (below), choose
 *    between two things (This or that, carrying who it is for), or start
 *    from a type (the persona Coves, the ones for that kind of person first).
 * 3. **One results page** (GiftResults), which This or that ends on as well.
 *
 * A profile card opens straight on the questions, filled in; `?for=` opens
 * straight on the results. See docs/features/find-a-gift.md.
 */
export default function GiftWizard(props: Props) {
    const { options, recipients, picks, brief, recipientList, card = null, personas = [], tasteUrl, people = [], myTaste = null } = props
    const { market } = usePage<SharedProps>().props
    const { t } = useTranslations()

    /*
     * Answers live in component state, not in the URL.
     *
     * A brief describes a real person — their tastes, what to avoid, what you
     * are willing to spend on them. That does not belong in a URL that ends up
     * in a referrer header or in a browser history someone else can read.
     */
    const [stage, setStage] = useState<'who' | 'ways' | 'questions'>(card || brief ? 'questions' : 'who')
    const [step, setStep] = useState(0)
    const [interests, setInterests] = useState<string[]>(brief?.interests ?? [])
    const [vibe, setVibe] = useState<string | null>(brief?.vibe ?? null)
    const [preferences, setPreferences] = useState<string[]>(brief?.preferences ?? [])
    const [budgetMax, setBudgetMax] = useState<string>(
        brief?.budget_max != null ? String(brief.budget_max) : '',
    )
    // Not asked, but carried: This or that learns a band, and "Refine with
    // the questions" posts it here, so it has to travel back with each post.
    const [budgetMin, setBudgetMin] = useState<number | null>(brief?.budget_min ?? null)
    const [avoid, setAvoid] = useState<string[]>(brief?.avoid ?? [])
    /*
      No step asks for these any more, but a saved person carries them from
      their own page and the brief echoes them back, so they stay in the
      payload and on the summary row.
    */
    const [values, setValues] = useState<string[]>(brief?.values ?? [])
    const [relationship, setRelationship] = useState<string | null>(brief?.relationship ?? null)
    // One of the fixed groups the server offers, never typed: an editor tags
    // a product with the same strings, so the two meet as one value.
    const [ageBand, setAgeBand] = useState<string | null>(brief?.age_band ?? null)
    const [recipientId, setRecipientId] = useState<string | null>(brief?.recipient_id ?? null)
    /*
     * "Voor mezelf" (owner, 2026-09-28): the same questions about yourself.
     * No saved person and no relationship; the questions say "you", and the
     * server scores for the person using the list (SuggestionProfile::forMyself).
     */
    const [forMe, setForMe] = useState<boolean>(brief?.for_me ?? false)

    /*
     * "Adjust" shows the questions again with the answers kept, and no request:
     * the state above is already the truth. The results stay in props, so
     * "Back to the ideas" is the same flag the other way.
     */
    const [editing, setEditing] = useState(false)

    const recipient = recipients.find((r) => r.id === recipientId) ?? null
    const relationshipOptions = options.relationships ?? []

    /** Who it is for as the closed vocabulary: the saved person's, or the chip picked. */
    const kind: string | null =
        recipient?.relationshipType ??
        (relationship && relationshipOptions.some((o) => o.value === relationship) ? relationship : null)

    const whoLabel = forMe
        ? t('gift.who_me_label')
        : recipient
          ? recipient.name
          : kind
          ? (relationshipOptions.find((o) => o.value === kind)?.label ?? kind)
          : null

    /*
     * Which of the interests are chips and which are the visitor's own words.
     * One list on the wire, because the engine does not care; two on screen,
     * because a typed word has no chip to light up.
     */
    const enumValues = new Set(options.interests.map((o) => o.value))
    const chosenChips = interests.filter((i) => enumValues.has(i))
    const ownWords = interests.filter((i) => !enumValues.has(i))
    const interestsFull = interests.length >= MAX_INTERESTS

    // Every key is posted every time, including the empty ones. The server
    // fills an *absent* key from the saved person's profile, so a cleared
    // answer has to travel as "cleared", not as "not mentioned".
    const payload = () => ({
        interests,
        vibe,
        preferences,
        // Only when there is one: an absent floor is filled from the saved
        // person, and a posted null would wipe theirs.
        ...(budgetMin !== null && budgetMax !== '' ? { budget_min: budgetMin } : {}),
        budget_max: budgetMax === '' ? null : Number(budgetMax),
        avoid,
        values,
        relationship,
        age_band: ageBand,
        recipient_id: recipientId,
        for_me: forMe,
    })

    const submit = () => {
        setEditing(false)
        router.post(`/${market.key}/gift`, payload(), { preserveScroll: false })
    }

    /*
     * "Something else."
     *
     * The server remembers what was rejected (`RejectionMemory`), keyed by the
     * brief, so all this sends is which one. A list kept here was destroyed
     * by the swap's own response, which rebuilds the component.
     */
    const swap = (pickId: number) => {
        router.post(`/${market.key}/gift/swap`, { ...payload(), rejected: pickId }, { preserveScroll: true })
    }

    /*
     * "Eight more" — past this board to the next one. The server works out
     * what is on screen for itself; nothing here lists the ids.
     */
    const more = () => {
        router.post(`/${market.key}/gift/more`, payload(), { preserveScroll: true })
    }

    const toggle = (list: string[], setter: (v: string[]) => void, value: string) => {
        setter(list.includes(value) ? list.filter((v) => v !== value) : [...list, value])
    }

    const useRecipient = (chosen: Recipient) => {
        // The second time you buy for your mother you should not have to
        // describe her again.
        setForMe(false)
        setRecipientId(chosen.id)
        setInterests(chosen.interests)
        setVibe(chosen.vibe)
        setPreferences(chosen.preferences ?? [])
        setAvoid(chosen.avoid)
        setValues(chosen.values)
        setRelationship(chosen.relationship)
        setAgeBand(chosen.ageBand)
        setBudgetMax(chosen.budgetMax != null ? String(chosen.budgetMax / 100) : '')
        setBudgetMin(null)
        setStage('ways')
    }

    /*
     * A card chosen. A saved person is used at once. A friend nobody saved yet
     * is saved first (the same `POST /recipients` with `friend_id` as My
     * people's "Bewaar wat je over … weet"), then used: Find a gift works on a
     * saved person, whose taste it reads and whose list it fills.
     */
    const [savingFriend, setSavingFriend] = useState<string | null>(null)
    const choosePerson = (person: PickablePerson) => {
        if (person.personId !== null) {
            const found = recipients.find((r) => r.id === person.personId)
            if (found) useRecipient(found)
            return
        }
        if (person.friend === null) {
            return
        }
        setSavingFriend(person.key)
        router.post(
            `/${market.key}/recipients`,
            { name: person.name, friend_id: person.friend.id },
            {
                preserveScroll: true,
                preserveState: true,
                onSuccess: (page) => {
                    // The friend just saved as a person: pick the new row.
                    const fresh = (page.props as unknown as { recipients?: Recipient[] }).recipients ?? []
                    const found = [...fresh].reverse().find((r) => r.name === person.name)
                    if (found) useRecipient(found)
                },
                onFinish: () => setSavingFriend(null),
            },
        )
    }

    // Declared before `pickable`, which calls it while rendering: a `const` arrow
    // used above its line is a ReferenceError, and the page rendered blank for
    // anybody with saved people but no My people list (production, 2026-09-27).
    const interestLabel = (value: string) => options.interests.find((o) => o.value === value)?.label ?? value

    /*
     * Without My people (not signed in, so no friends), the saved people as the
     * same cards: what the page knows of them from their own record.
     */
    const pickable: PickablePerson[] =
        people.length > 0
            ? people
            : recipients.map((r) => ({
                  key: `p:${r.id}`,
                  name: r.name,
                  relationship: r.relationship,
                  personId: r.id,
                  friend: null,
                  known: { interests: r.interests.map(interestLabel) },
              }))

    /** A kind of person, or nobody in particular (null). Clears a saved person picked before. */
    const useKind = (value: string | null) => {
        if (recipientId !== null) {
            setRecipientId(null)
            setInterests([])
            setVibe(null)
            setPreferences([])
            setAvoid([])
            setValues([])
            setAgeBand(null)
            setBudgetMax('')
        }

        setForMe(false)
        setRelationship(value)
        setStage('ways')
    }

    /**
     * "Voor mezelf": nobody else, so a saved person and a relationship picked
     * before both go. Your own "Mijn smaak", when you keep one, fills the
     * answers, as choosing a saved person fills theirs; never a budget.
     */
    const useMe = () => {
        useKind(null)
        setForMe(true)

        if (myTaste) {
            setInterests(myTaste.interests)
            setVibe(myTaste.vibe)
            setPreferences(myTaste.preferences)
            setValues(myTaste.values)
            setAvoid(myTaste.avoid)
            setAgeBand(myTaste.ageBand)
        }
    }

    /** A question's key, in its "you" form when the gift is for yourself. */
    const own = (key: string) => (forMe ? `${key}_me` : key)

    /*
      An avoided *interest*, as This or that learns it ("not gaming"), is kept
      in the tag's spelling, `interest:gaming`, so the engine leaves out the
      tag and never a title word (TasteBrief::avoidedInterests). It reads as
      the interest's name, and it is removed with a tap rather than typed.
    */
    const LEARNED = 'interest:'
    const avoidLabel = (word: string) => (word.startsWith(LEARNED) ? interestLabel(word.slice(LEARNED.length)) : word)
    const learnedAvoid = avoid.filter((word) => word.startsWith(LEARNED))
    const typedAvoid = avoid.filter((word) => !word.startsWith(LEARNED))

    const chip = (selected: boolean, disabled = false) =>
        `rounded-full border px-3 py-1.5 text-sm ${
            selected ? 'border-accent bg-accent text-white' : 'border-line hover:bg-card'
        } ${disabled ? 'cursor-not-allowed opacity-50' : ''}`

    /*
      This or that, carrying who it is for: a saved person by id (the server
      checks it is yours, and leaves out what they were given), a kind of
      person by its value. Neither describes the person, so both may sit in a
      URL, where the answers may not.
    */
    const withWho = (base: string) => {
        if (forMe) {
            return `${base}?for=me`
        }

        if (recipient) {
            return `${base}?person=${encodeURIComponent(recipient.id)}`
        }

        return kind ? `${base}?relationship=${encodeURIComponent(kind)}` : base
    }
    const tasteHref = withWho(tasteUrl ?? `/${market.key}/gift/taste`)
    // Swipe gifts carries who it is for the same way (CarriedWho).
    const swipeHref = withWho(`/${market.key}/gift/swipe`)

    /*
      The persona Coves for this kind of person first, then the ones for
      anybody, then the rest; newest first within each, as the server sent
      them. Nothing is filtered out: a type is recognised on sight, and "the
      home cook" may be the right shelf for a colleague.
    */
    const types = [...personas]
        .map((persona, index) => ({ persona, index }))
        .sort((a, b) => {
            const rank = (p: PersonaCard) => (kind && p.relationship === kind ? 0 : p.relationship ? 2 : 1)
            return rank(a.persona) - rank(b.persona) || a.index - b.index
        })
        .slice(0, TYPES_SHOWN)
        .map(({ persona }) => persona)

    /*
      The fourth way, "Ask other people": the ask form, filled in from what is
      known. Who it is for travels in the link, as This or that's does (a
      saved person by id, checked on the server; a kind of person by value);
      what was said about them waits in this tab's sessionStorage (askBrief.ts),
      because answers never go in an address. Never the person's name: the
      question is public, and the asker types a name only if they choose to.
    */
    const askHref = (() => {
        const base = `/${market.key}/ask?from=gift`

        if (recipient) {
            return `${base}&person=${encodeURIComponent(recipient.id)}`
        }

        return kind ? `${base}&relationship=${encodeURIComponent(kind)}` : base
    })()

    const stashForAsk = () =>
        stashAskBrief({
            interests: chosenChips,
            vibe,
            values,
            budget_max: budgetMax,
            age_band: ageBand ? (options.ages.find((o) => o.value === ageBand)?.label ?? '') : '',
        })

    const showResults = picks !== null && !editing

    /** "For Mum · change", above the ways and the questions. */
    const forLine = !card && (
        <p className="flex flex-wrap items-center gap-2 text-sm">
            <span className="rounded-full bg-accent/10 px-3 py-1 font-medium text-accent-dark">
                {whoLabel ? t('gift.for_label', { who: whoLabel }) : t('gift.for_someone')}
            </span>
            <button
                type="button"
                className="text-accent underline"
                onClick={() => {
                    setEditing(false)
                    setStage('who')
                }}
            >
                {t('gift.change')}
            </button>
        </p>
    )

    return (
        <>
            <Head title={card?.title ?? t('gift.title')}>{card && <meta name="robots" content="noindex, nofollow" />}</Head>

            <header className="max-w-2xl">
                <h1 className="text-2xl font-semibold sm:text-3xl">{t('gift.title')}</h1>
                <p className="mt-2 text-ink-soft">{t('gift.subtitle')}</p>
            </header>

            {card && !showResults && <GiftProfileCardBanner card={card} onSeeIdeas={submit} />}

            {showResults ? (
                <GiftResults
                    picks={picks}
                    pageUrl={props.pageUrl}
                    offlineIdeas={props.offlineIdeas}
                    communityCoves={props.communityCoves}
                    nextSteps={props.nextSteps}
                    personUrl={props.personUrl}
                    askUrl={props.askUrl ? askHref : null}
                    onAsk={stashForAsk}
                    into={recipientList}
                    personName={recipient?.name ?? null}
                    onSwap={swap}
                    // Thumbs: about the saved person, or the kind of person picked.
                    thumbs={{ recipientId, relationship: forMe ? null : (kind ?? relationship) }}
                    forMe={forMe}
                    interestLabel={interestLabel}
                    top={
                        <>
                            {/*
                              What you told us, in one line of chips, with the
                              way back to change one of them.
                            */}
                            <div className="flex flex-wrap items-center gap-2">
                                {whoLabel && (
                                    <span className="text-sm font-medium">{t('gift.summary_for', { name: whoLabel })}</span>
                                )}
                                {interests.map((value) => (
                                    <span key={value} className="rounded-full border border-line px-3 py-1 text-sm">
                                        {interestLabel(value)}
                                    </span>
                                ))}
                                {ageBand && (
                                    <span className="rounded-full border border-line px-3 py-1 text-sm">
                                        {options.ages.find((o) => o.value === ageBand)?.label ?? ageBand}
                                    </span>
                                )}
                                {preferences.map((preference) => (
                                    <span key={preference} className="rounded-full border border-line px-3 py-1 text-sm">
                                        {t(`gift.preferences.${preference}`)}
                                    </span>
                                ))}
                                {vibe && (
                                    <span className="rounded-full border border-line px-3 py-1 text-sm">
                                        {options.vibes.find((o) => o.value === vibe)?.label ?? vibe}
                                    </span>
                                )}
                                <span className="rounded-full border border-line px-3 py-1 text-sm">
                                    {budgetMax === ''
                                        ? t('gift.budget_any')
                                        : t('gift.summary_budget', {
                                              amount: formatBudget(Math.round(Number(budgetMax) * 100), market),
                                          })}
                                </span>
                                {avoid.map((word) => (
                                    <span key={word} className="rounded-full border border-line px-3 py-1 text-sm text-ink-soft">
                                        {t('gift.summary_avoid', { word: avoidLabel(word) })}
                                    </span>
                                ))}
                                {values.map((value) => (
                                    <span key={value} className="rounded-full border border-line px-3 py-1 text-sm">
                                        {t(`gift.values.${value}`)}
                                    </span>
                                ))}
                                <button
                                    type="button"
                                    className="text-sm text-accent underline"
                                    onClick={() => {
                                        setEditing(true)
                                        setStage('questions')
                                        setStep(0)
                                    }}
                                >
                                    {t('gift.adjust')}
                                </button>
                            </div>


                            {/*
                              The same search card as on the ways step, on the
                              ideas too (owner, 2026-09-27: "also add an inline
                              search" here): somebody who sees the ideas and
                              thinks of something else can look it up and put
                              it on the same list without going back. A plain
                              search, no ranking by the person (owner: "forget
                              about the prioritisation").
                            */}
                            <SearchToListCard
                                key={recipient?.id ?? kind ?? 'nobody'}
                                recipient={recipient}
                                kind={kind}
                                kindLabel={whoLabel}
                                market={market}
                            />
                        </>
                    }
                    actions={
                        <>
                            <button
                                type="button"
                                className="rounded border border-line px-4 py-2 text-sm"
                                onClick={() => router.get(`/${market.key}/gift`)}
                            >
                                {t('gift.start_over')}
                            </button>
                            {/*
                              Nothing to move past when the board is empty; the
                              answers are what need changing, and Adjust is above.
                            */}
                            {picks.length > 0 && (
                                <button
                                    type="button"
                                    className="rounded bg-accent px-4 py-2 text-sm font-medium text-white"
                                    onClick={more}
                                >
                                    {t('gift.more')}
                                </button>
                            )}
                        </>
                    }
                />
            ) : stage === 'who' ? (
                <section className="mt-8" aria-labelledby="gift-who">
                    <h2 id="gift-who" className="text-lg font-medium">
                        {t('gift.who_title')}
                    </h2>

                    {/*
                      "Voor mezelf" first and larger than a chip (owner,
                      2026-09-28): the one answer that is about the visitor.
                    */}
                    <button
                        type="button"
                        aria-pressed={forMe}
                        onClick={useMe}
                        className={`mt-4 flex w-full items-center gap-3 rounded-card border px-5 py-4 text-left text-base font-medium transition sm:w-auto sm:min-w-72 ${
                            forMe ? 'border-accent bg-accent text-white' : 'border-line bg-card hover:border-ink'
                        }`}
                    >
                        <span
                            className={`flex h-9 w-9 shrink-0 items-center justify-center rounded-lg ${
                                forMe ? 'bg-white/20 text-white' : 'bg-accent/10 text-accent'
                            }`}
                        >
                            <ToolIcon name="wishlist" className="h-5 w-5" />
                        </span>
                        {t('gift.who_me')}
                    </button>

                    {pickable.length > 0 && (
                        <div className="mt-4">
                            <PersonPicker
                                people={pickable}
                                label={t('gift.who_people')}
                                isChosen={(person) => person.personId !== null && person.personId === recipientId}
                                onChoose={choosePerson}
                                busyKey={savingFriend}
                            />
                        </div>
                    )}

                    {relationshipOptions.length > 0 && (
                        <div className="mt-5">
                            {recipients.length > 0 && <p className="text-sm text-ink-soft">{t('gift.who_type')}</p>}
                            <div className="mt-2 flex flex-wrap gap-2">
                                {relationshipOptions.map((option) => (
                                    <button
                                        key={option.value}
                                        type="button"
                                        aria-pressed={recipient === null && relationship === option.value}
                                        className={chip(recipient === null && relationship === option.value)}
                                        onClick={() => useKind(option.value)}
                                    >
                                        {option.label}
                                    </button>
                                ))}
                            </div>
                        </div>
                    )}

                    <button type="button" className="mt-6 text-sm text-ink-soft underline hover:text-ink" onClick={() => useKind(null)}>
                        {t('gift.who_skip')}
                    </button>
                </section>
            ) : stage === 'ways' ? (
                <section className="mt-8" aria-labelledby="gift-ways">
                    {forLine}

                    <h2 id="gift-ways" className="mt-5 text-lg font-medium">
                        {t('gift.ways_title')}
                    </h2>

                    {/* Keyed by who it is for: another person means another list, never the last one's. */}
                    <SearchToListCard
                        key={recipient?.id ?? kind ?? 'nobody'}
                        recipient={recipient}
                        kind={kind}
                        kindLabel={whoLabel}
                        market={market}
                    />

                    {/*
                      Swipe gifts, full width and second, under the search
                      (owner, 2026-09-28): the other way that puts things
                      straight on the list.
                    */}
                    <Link href={swipeHref} className={`${wayCard} mt-4`}>
                        <WayHead icon={<ToolIcon name="swipe" className="h-5 w-5" />} title={t('gift.way_swipe')} />
                        <span className="mt-1 text-sm text-ink-soft">{t(own('gift.way_swipe_hint'))}</span>
                        <span className="mt-auto pt-4 text-sm font-medium text-accent-dark">{t('gift.way_swipe_cta')} →</span>
                    </Link>

                    {/*
                      Two rows since the owner's review of 2026-09-27: the ways
                      you search yourself, then the ways you ask somebody. The
                      type card was four cards in a card, the tallest thing on
                      the page, and every other card was stretched to its
                      height; it is now a card like the others with a list to
                      pick from. The rows have 3 and 2 cards (or 2 and 2 with
                      no persona Coves in this market), so no card is ever
                      alone on a row.
                    */}
                    <div className={`mt-4 grid gap-4 sm:grid-cols-2 ${types.length > 0 ? 'lg:grid-cols-3' : ''}`}>
                        <button
                            type="button"
                            onClick={() => {
                                setStep(0)
                                setStage('questions')
                            }}
                            className={wayCard}
                        >
                            <WayHead icon={<ToolIcon name="suggestions" className="h-5 w-5" />} title={t('gift.way_questions')} />
                            <span className="mt-1 text-sm text-ink-soft">{t(own('gift.way_questions_hint'))}</span>
                            <span className="mt-auto pt-4 text-sm font-medium text-accent-dark">{t('gift.way_questions_cta')} →</span>
                        </button>

                        <Link href={tasteHref} className={wayCard}>
                            <WayHead icon={<ToolIcon name="taste" className="h-5 w-5" />} title={t('gift.way_taste')} />
                            <span className="mt-1 text-sm text-ink-soft">{t(own('gift.way_taste_hint'))}</span>
                            <span className="mt-auto pt-4 text-sm font-medium text-accent-dark">{t('gift.way_taste_cta')} →</span>
                        </Link>

                        {types.length > 0 && (
                            <div className={`${wayCard} sm:col-span-2 lg:col-span-1`}>
                                <WayHead icon={<ToolIcon name="people" className="h-5 w-5" />} title={t('gift.way_types')} />
                                <span className="mt-1 text-sm text-ink-soft">{t('gift.personas_hint')}</span>
                                {/* A list to pick from: the persona Coves for this kind of person first. */}
                                <label className="mt-auto block pt-4">
                                    <span className="sr-only">{t('gift.way_types')}</span>
                                    <select
                                        defaultValue=""
                                        onChange={(e) => e.target.value !== '' && router.visit(e.target.value)}
                                        className="block w-full rounded-lg border border-line bg-cream px-3 py-2 text-sm"
                                    >
                                        <option value="" disabled>
                                            {t('gift.types_pick')}
                                        </option>
                                        {types.map((persona) => (
                                            <option key={persona.url} value={persona.url}>
                                                {persona.title}
                                            </option>
                                        ))}
                                    </select>
                                </label>
                                <Link href={`/${market.key}/gift-ideas`} className="mt-2 text-sm font-medium text-accent-dark hover:text-ink">
                                    {t('discover_cove.persona_all')} →
                                </Link>
                            </div>
                        )}
                    </div>

                    <h3 className="mt-8 text-base font-medium">{t('gift.ways_ask_title')}</h3>
                    <div className="mt-3 grid gap-4 sm:grid-cols-2">
                        {/*
                          Ask other people (owner, 2026-09-26): the ask form,
                          already filled in with who it is for and what is
                          known.
                        */}
                        {/*
                          Two ways to ask others (owner, 2026-09-27): a public
                          question on the board, or the list for this person
                          shared with friends and family, who suggest things
                          on it (SuggestionController). The second is a POST
                          for a saved person, because it may make that list.
                        */}
                        <div className={wayCard}>
                            <WayHead icon={<CoveIcon name="ask" className="h-5 w-5" />} title={t('gift.way_ask')} />
                            <span className="mt-1 text-sm text-ink-soft">{t('gift.way_ask_hint')}</span>
                            <span className="mt-auto flex flex-col items-start gap-2 pt-4 text-sm font-medium text-accent-dark">
                                <Link href={askHref} onClick={stashForAsk} className="hover:text-ink">
                                    {t('gift.way_ask_cta')} →
                                </Link>
                                {recipient !== null ? (
                                    <button
                                        type="button"
                                        className="text-left hover:text-ink"
                                        onClick={() => router.post(`/${market.key}/people/${recipient.id}/share-list`)}
                                    >
                                        {t('gift.way_ask_list', { name: recipient.name })} →
                                    </button>
                                ) : (
                                    <Link href={`/${market.key}/lists?new`} className="hover:text-ink">
                                        {t('gift.way_ask_list_none')} →
                                    </Link>
                                )}
                            </span>
                        </div>

                        <AskThemCard recipient={recipient} peopleUrl={`/${market.key}/people`} />
                    </div>
                </section>
            ) : (
                <section className="mt-8">
                    {forLine}

                    <div className={`${card ? '' : 'mt-5'} flex items-baseline justify-between gap-3`}>
                        <p className="text-xs text-ink-soft">{t('gift.step', { current: step + 1, total: STEPS.length })}</p>
                        {editing && (
                            <button type="button" className="text-xs text-ink-soft underline" onClick={() => setEditing(false)}>
                                {t('gift.back_to_ideas')}
                            </button>
                        )}
                    </div>

                    <h2 className="mt-1 text-lg font-medium">{t(STEPS[step] === 'interests' || STEPS[step] === 'age' ? own(`gift.step_${STEPS[step]}`) : `gift.step_${STEPS[step]}`)}</h2>

                    <div className="mt-4">
                        {STEPS[step] === 'interests' && (
                            <div>
                                <div className="flex flex-wrap gap-2">
                                    {options.interests.map((option) => {
                                        const selected = chosenChips.includes(option.value)
                                        const blocked = !selected && interestsFull

                                        return (
                                            <button
                                                key={option.value}
                                                type="button"
                                                aria-pressed={selected}
                                                disabled={blocked}
                                                className={chip(selected, blocked)}
                                                onClick={() => toggle(interests, setInterests, option.value)}
                                            >
                                                {option.label}
                                            </button>
                                        )
                                    })}
                                </div>

                                {/*
                                  Words of your own. The engine searches them
                                  as typed — "wielrennen" retrieves what a chip
                                  never could.
                                */}
                                <div className="mt-5">
                                    <div className="flex items-baseline justify-between gap-3">
                                        <label htmlFor="own-interest" className="text-sm font-medium">
                                            {t('gift.interests_other')}
                                        </label>
                                        <span className="text-xs text-ink-soft">{t('gift.interests_max')}</span>
                                    </div>
                                    <div className="mt-2">
                                        <ChipInput
                                            inputId="own-interest"
                                            value={ownWords}
                                            onChange={(words) => setInterests([...chosenChips, ...words])}
                                            placeholder={t('gift.interests_other_placeholder')}
                                            addLabel={t('gift.add')}
                                            max={MAX_INTERESTS - chosenChips.length}
                                        />
                                    </div>
                                </div>

                                {/*
                                  Stuck here? The other way to answer this
                                  step: choose between products and let the
                                  choices say it (taste-discovery.md).
                                */}
                                {!card && (
                                    <Link href={tasteHref} className="mt-5 inline-flex items-center gap-2 text-sm text-accent underline">
                                        <ToolIcon name="taste" className="h-4 w-4" />
                                        {t(own('gift.taste.from_finder'))}
                                    </Link>
                                )}
                            </div>
                        )}

                        {STEPS[step] === 'age' && (
                            <div className="flex flex-wrap gap-2">
                                {options.ages.map((option) => (
                                    <button
                                        key={option.value}
                                        type="button"
                                        aria-pressed={ageBand === option.value}
                                        className={chip(ageBand === option.value)}
                                        onClick={() => setAgeBand(ageBand === option.value ? null : option.value)}
                                    >
                                        {option.label}
                                    </button>
                                ))}
                            </div>
                        )}

                        {STEPS[step] === 'vibe' && (
                            /*
                              Two rows, one question: what a present is for and
                              what it looks like are independent, but nobody
                              experiences them as two questions about the same
                              person, and a step of its own is a step people skip.
                            */
                            <div className="space-y-5">
                                <div className="flex flex-wrap gap-2">
                                    {options.vibes.map((option) => (
                                        <button
                                            key={option.value}
                                            type="button"
                                            aria-pressed={vibe === option.value}
                                            className={chip(vibe === option.value)}
                                            onClick={() => setVibe(vibe === option.value ? null : option.value)}
                                        >
                                            {option.label}
                                        </button>
                                    ))}
                                </div>

                                {/*
                                  Taste as pairs of opposites, one row an axis.
                                  Shown both ends, a person recognises their own
                                  taste; shown a bag of words, they read all of
                                  them and pick none. Picking one end clears the
                                  other; the cap of three counts the whole answer.
                                */}
                                <div>
                                    <p className="mb-3 text-sm text-ink-soft">{t(own('gift.preference_label'))}</p>
                                    <div className="space-y-2">
                                        {options.preferences.map(({ axis, poles }) => (
                                            <div key={axis} className="flex flex-wrap items-center gap-2">
                                                {poles.map((pole, index) => (
                                                    <Fragment key={pole.value}>
                                                        {index === 1 && (
                                                            <span aria-hidden className="text-xs text-ink-soft">
                                                                {t('gift.preference_or')}
                                                            </span>
                                                        )}
                                                        <button
                                                            type="button"
                                                            aria-pressed={preferences.includes(pole.value)}
                                                            className={chip(preferences.includes(pole.value))}
                                                            onClick={() =>
                                                                setPreferences((current) => {
                                                                    const other = poles[index === 0 ? 1 : 0].value
                                                                    const without = current.filter(
                                                                        (value) => value !== pole.value && value !== other,
                                                                    )

                                                                    return current.includes(pole.value) || without.length >= 3
                                                                        ? without
                                                                        : [...without, pole.value]
                                                                })
                                                            }
                                                        >
                                                            {pole.label}
                                                        </button>
                                                    </Fragment>
                                                ))}
                                            </div>
                                        ))}
                                    </div>
                                </div>
                            </div>
                        )}

                        {STEPS[step] === 'budget' && (
                            <div className="flex items-center gap-2">
                                <label htmlFor="budget-max" className="text-sm">
                                    {t('gift.budget_up_to')}
                                </label>
                                <input
                                    id="budget-max"
                                    type="number"
                                    min="0"
                                    step="1"
                                    className="w-32 rounded border border-line px-3 py-2"
                                    placeholder={t('gift.budget_any')}
                                    value={budgetMax}
                                    onChange={(e) => {
                                        setBudgetMax(e.target.value)
                                        // A typed ceiling replaces a learned band.
                                        setBudgetMin(null)
                                    }}
                                />
                            </div>
                        )}

                        {STEPS[step] === 'avoid' && (
                            <div>
                                <ChipInput
                                    value={typedAvoid}
                                    onChange={(words) => setAvoid([...learnedAvoid, ...words])}
                                    placeholder={t('gift.avoid_placeholder')}
                                    addLabel={t('gift.add')}
                                    max={10 - learnedAvoid.length}
                                />
                                {learnedAvoid.length > 0 && (
                                    <div className="mt-2 flex flex-wrap gap-2">
                                        {learnedAvoid.map((word) => (
                                            <button
                                                key={word}
                                                type="button"
                                                className="inline-flex items-center gap-1.5 rounded-full border border-line px-3 py-1 text-sm text-ink-soft hover:border-ink"
                                                onClick={() => setAvoid(avoid.filter((w) => w !== word))}
                                            >
                                                {t('gift.summary_avoid', { word: avoidLabel(word) })}
                                                <ToolIcon name="close" className="h-3.5 w-3.5 shrink-0" />
                                            </button>
                                        ))}
                                    </div>
                                )}
                                <p className="mt-2 text-xs text-ink-soft">{t('gift.avoid_hint')}</p>
                            </div>
                        )}
                    </div>

                    <div className="mt-8 flex flex-wrap items-center gap-3">
                        {/* Back from the first question is back to the three ways, unless there were none. */}
                        {(step > 0 || (!card && !editing)) && (
                            <button
                                type="button"
                                className="rounded border border-line px-4 py-2 text-sm"
                                onClick={() => (step > 0 ? setStep(step - 1) : setStage('ways'))}
                            >
                                {t('gift.back')}
                            </button>
                        )}

                        {step < STEPS.length - 1 ? (
                            <>
                                <button
                                    type="button"
                                    className="rounded bg-accent px-5 py-2 font-medium text-white"
                                    onClick={() => setStep(step + 1)}
                                >
                                    {t('gift.next')}
                                </button>
                                {/*
                                  Every step is skippable. The engine treats an
                                  unanswered question as "does not apply" rather
                                  than as a zero, so a person who only knows one
                                  thing about the recipient still gets a real answer.
                                */}
                                <button type="button" className="text-sm text-ink-soft underline" onClick={submit}>
                                    {t('gift.find')}
                                </button>
                            </>
                        ) : (
                            <button type="button" className="rounded bg-accent px-5 py-2 font-medium text-white" onClick={submit}>
                                {t('gift.find')}
                            </button>
                        )}
                    </div>
                </section>
            )}
        </>
    )
}
