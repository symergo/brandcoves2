import { useState } from 'react'
import Button from './Button'
import { useConfirm } from './Modal'
import Option from './Option'
import ShareRow from './ShareRow'
import { useTranslations } from '../useTranslations'

/** What the sharing controls read of a list. Both callers' list shapes carry it. */
export interface ShareableList {
    title: string
    kind: string
    shareUrl: string | null
    /** "Visible to my people"; null where the question does not arise (see summarise()). */
    visibleToFriends: boolean | null
    linkCanAdd: boolean
    pledgersVisible: boolean
    votingEnabled: boolean
}

/**
 * Whether a list is shared, its link, and what the link lets people do: one
 * component for the share popup on Mijn Coves and a person's page
 * (`ShareListDialog`) and the share panel on the list page (`ListTools`).
 *
 * ## Why one (consistency review, round 2, 2026-09-27)
 *
 * The switches were drawn twice and had already drifted apart. The panel said
 * "Only you and your people see this list" when a private wish list was shown
 * to your people and the popup said "This list is private"; the popup said
 * what each switch did on screen, the panel behind an (i); the panel named
 * the friends who see the list and the popup did not; stopping sharing was a
 * bordered button beside the heading in one and grey text at the foot of the
 * other. A privacy control that reads differently depending on which door you
 * came in by is a privacy control somebody misreads. Now both doors open onto
 * this.
 *
 * Every switch is the list page's own PATCH (`onSetting`), under the
 * conditions the panel always had: adding needs a live link on a list that is
 * not about you (on your own wish list the link holders shop for you, and
 * "anyone can add" would invite them to write it); the two group switches
 * need a group. How a group collects (the pledge mode) is not here: it moved
 * to Settings (owner, 2026-09-27), as what the list is rather than who sees it.
 *
 * `readOnly` is for somebody who may edit the list without owning it (a
 * legacy editor collaborator, who can open the panel by `?panel=share`): the
 * state and the link, and none of the controls, as the panel always drew it.
 */
export default function ShareSettings({
    list,
    friends,
    onSetting,
    saved = false,
    readOnly = false,
}: {
    list: ShareableList
    /**
     * The owner's friends on GiftCoves, named under "Visible to my people" so
     * the audience of that switch is something you can read (friends.md). Left
     * out where the caller does not have them.
     */
    friends?: { name: string }[]
    onSetting: (data: Record<string, string | number | boolean | null>, done?: () => void) => void
    /** The last switch was stored: one line, announced, then gone (ListTools). */
    saved?: boolean
    readOnly?: boolean
}) {
    const { t } = useTranslations()
    const [busy, setBusy] = useState(false)
    const [confirm, confirmDialog] = useConfirm()

    const linkCanAdd = list.shareUrl !== null && list.kind !== 'mine'
    const group = list.kind === 'group'
    const hasSwitches = !readOnly && (list.visibleToFriends !== null || linkCanAdd || group)

    return (
        <div>
            {/*
              What is true right now, as the heading, before any control. On a
              page where the mistake is thinking something is private when it
              is not, the state is worth being the first line.
            */}
            <div className="flex flex-wrap items-start justify-between gap-3">
                <div className="min-w-0">
                    <h3 className="text-sm font-medium">
                        {list.shareUrl
                            ? t('lists.sharing_on')
                            : list.visibleToFriends
                              ? t('lists.sharing_off_people')
                              : t('lists.sharing_off')}
                    </h3>
                    {!list.shareUrl && !list.visibleToFriends && <p className="mt-1 text-xs text-ink-soft">{t('lists.share_hint')}</p>}
                </div>
                {/*
                  Stop sharing beside the sentence it ends, quiet and second,
                  and asking once: every link already sent stops working.
                */}
                {list.shareUrl && !readOnly && (
                    <Button
                        variant="secondary"
                        size="sm"
                        onClick={async () => {
                            if (
                                await confirm({
                                    message: t('lists.disable_sharing_confirm'),
                                    confirmLabel: t('lists.disable_sharing'),
                                    danger: true,
                                })
                            ) {
                                onSetting({ visibility: 'private' })
                            }
                        }}
                    >
                        {t('lists.disable_sharing')}
                    </Button>
                )}
            </div>

            {/*
              The press that publishes the list says so: "Turn sharing on" is
              the answer to the sentence above it, where "Share" would repeat
              the button that opened this.
            */}
            {!list.shareUrl && !readOnly && (
                <Button
                    className="mt-3"
                    busy={busy}
                    onClick={() => {
                        setBusy(true)
                        onSetting({ visibility: 'link' }, () => setBusy(false))
                    }}
                >
                    {t('lists.enable_sharing')}
                </Button>
            )}

            {list.shareUrl && (
                <div className="mt-3">
                    <ShareRow url={list.shareUrl} text={t('lists.share_text', { title: list.title })} />
                </div>
            )}

            {hasSwitches && (
                <div className="mt-5 space-y-2">
                    {/*
                      "Visible to my people" (2026-09-26): all of the owner's
                      friends on GiftCoves see this wish list and can pick from
                      it. Independent of the link: a private list with this on
                      is seen by those friends and nobody else. The names are
                      under the switch, because "my friends" is an audience that
                      grows by opening links (wish-list-for-my-people.md).
                    */}
                    {list.visibleToFriends !== null && (
                        <Option
                            type="checkbox"
                            checked={list.visibleToFriends}
                            onChange={() => onSetting({ visible_to_friends: !list.visibleToFriends })}
                            label={t('lists.visible_to_people')}
                            tip={t('lists.visible_to_people_tip')}
                            note={
                                friends === undefined
                                    ? undefined
                                    : friends.length > 0
                                      ? t('lists.visible_to_people_who', { names: friends.map((friend) => friend.name).join(', ') })
                                      : t('lists.visible_to_people_nobody')
                            }
                        />
                    )}
                    {linkCanAdd && (
                        <Option
                            type="checkbox"
                            checked={list.linkCanAdd}
                            onChange={() => onSetting({ link_can_add: !list.linkCanAdd })}
                            label={t('lists.anyone_can_add')}
                            tip={t('lists.anyone_can_add_hint')}
                        />
                    )}
                    {/*
                      Names only, never amounts: the ladder of who put in how
                      much stays the organiser's whatever this says. See
                      ContributionView.
                    */}
                    {group && (
                        <Option
                            type="checkbox"
                            checked={list.pledgersVisible}
                            onChange={() => onSetting({ pledgers_visible: !list.pledgersVisible })}
                            label={t('lists.pledgers_visible')}
                            tip={t('lists.pledgers_visible_hint')}
                        />
                    )}
                    {/*
                      Switching voting off deletes nothing: a vote is somebody's
                      opinion, and turning it back on shows the tally as it was.
                    */}
                    {group && (
                        <Option
                            type="checkbox"
                            checked={list.votingEnabled}
                            onChange={() => onSetting({ voting_enabled: !list.votingEnabled })}
                            label={t('lists.voting_enabled')}
                            tip={t('lists.voting_enabled_hint')}
                        />
                    )}
                    {/* Holding its height, so a save never nudges what is under it. */}
                    <p role="status" aria-live="polite" className="h-4 text-xs text-sage">
                        {saved && t('lists.saved')}
                    </p>
                </div>
            )}

            {confirmDialog}
        </div>
    )
}
