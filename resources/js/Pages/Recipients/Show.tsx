import { Head, Link, usePage } from '@inertiajs/react'
import NextSteps, { type NextStepCard } from '../../Components/NextSteps'
import PersonProfile, { type Profile, type ProfileOptions, type ProfilePerson, type ProfileUrls } from '../../Components/PersonProfile'
import SaveToList from '../../Components/SaveToList'
import type { Cents, SavingTo, SharedProps } from '../../types'
import { formatPrice } from '../../types'
import { useTranslations } from '../../useTranslations'

interface Highlight {
    id: number
    title: string
    brand: string | null
    image: string | null
    price: Cents | null
    url: string
}

interface Props {
    person: ProfilePerson
    profile: Profile
    options: ProfileOptions
    groupLists: number
    nextSteps: NextStepCard[]
    highlight: Highlight | null
    recipientList: SavingTo | null
    urls: ProfileUrls
}

/**
 * A saved person's page: who they are, their lists, and what could come next.
 *
 * Only the owner reaches it. The profile at the top is PersonProfile
 * (docs/features/my-people.md). "De volgende stap" follows on from the owner's
 * own claims on lists for this person, never anybody else's. See
 * docs/features/gift-history.md.
 */
export default function RecipientShow({
    person,
    profile,
    options,
    groupLists,
    nextSteps,
    highlight,
    recipientList,
    urls,
}: Props) {
    const { market } = usePage<SharedProps>().props
    const { t } = useTranslations()
    return (
        <>
            <Head title={person.name} />

            <PersonProfile person={person} profile={profile} options={options} urls={urls} groupLists={groupLists} />

            {/*
              The idea a reminder email linked to. The email only opens this
              page; the save is the press here, so a mail scanner opening every
              link cannot add anything to the list.
            */}
            {highlight && (
                <section className="mt-8 rounded-card border border-accent/40 bg-accent/5 p-4">
                    <h2 className="text-sm font-medium text-ink-soft">{t('gift_history.from_email')}</h2>
                    <div className="mt-3 flex items-center gap-4">
                        {highlight.image && (
                            <img src={highlight.image} alt="" className="h-20 w-20 shrink-0 object-contain" loading="lazy" />
                        )}
                        <div className="min-w-0 flex-1">
                            <Link href={highlight.url} className="line-clamp-2 font-medium">
                                {highlight.title}
                            </Link>
                            {highlight.price !== null && (
                                <p className="mt-1 text-sm font-semibold">{formatPrice(highlight.price, market)}</p>
                            )}
                        </div>
                        <SaveToList groupId={highlight.id} into={recipientList ?? undefined} />
                    </div>
                </section>
            )}

            {/*
              "Wat je gaf" and "Op je lijsten voor ..." were here until the owner
              removed them (2026-09-29). What you marked "Ik koop dit" on a list
              for them still keeps it out of new ideas (GiftHistory).
            */}
            <NextSteps steps={nextSteps} name={person.name} into={recipientList} />
        </>
    )
}
