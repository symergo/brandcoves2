<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ClaimVisibility;
use App\Enums\EventType;
use App\Enums\ListKind;
use App\Enums\ListVisibility;
use App\Enums\Market;
use App\Services\Wishlist\DefaultTitle;
use App\Support\ListAccess;
use App\Support\Owner;
use Database\Factories\WishlistFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * A list, either for yourself or for a specific recipient.
 *
 * @property ListVisibility $visibility
 * @property ListKind $kind
 * @property ClaimVisibility $claim_visibility
 * @property bool|null $owner_sees_claims
 */
class Wishlist extends Model
{
    /** @use HasFactory<WishlistFactory> */
    use HasFactory;

    use HasUuids;

    protected $guarded = [];

    protected static function booted(): void
    {
        // The column is NOT NULL and every list needs a share URL. Generating it
        // here rather than at the call site means no code path can create a list
        // that cannot be shared.
        static::creating(function (self $list): void {
            $list->share_token ??= (string) Str::uuid();
        });
    }

    protected function casts(): array
    {
        return [
            'market' => Market::class,
            'visibility' => ListVisibility::class,
            'kind' => ListKind::class,
            'claim_visibility' => ClaimVisibility::class,
            'owner_sees_claims' => 'boolean',
            'event_type' => EventType::class,
            'event_date' => 'date',
            'is_default' => 'boolean',
            'handed_over_at' => 'datetime',

            /*
             * A home address is the most sensitive thing this application
             * holds, and unlike an email it cannot be rotated. Encrypted at
             * rest so a database copy — a backup, a laptop, a support session —
             * is not a list of where people live.
             */
            'delivery_address' => 'encrypted',
        ];
    }

    /**
     * What to call this list on screen.
     *
     * `title` is what is stored; this is what is shown. They differ for exactly
     * one list — the default one, whose name we wrote rather than the owner —
     * and they differ because the stored name is frozen in the language of the
     * market the list was created on. A title a person typed is theirs and is
     * returned untouched. See {@see DefaultTitle}.
     */
    public function displayTitle(?string $language = null): string
    {
        return DefaultTitle::isOurs($this->title)
            ? DefaultTitle::current($language)
            : (string) $this->title;
    }

    /**
     * Does this list say what it is for?
     *
     * Any list of any kind may carry an occasion: a wedding of my own, a
     * birthday list about my father, a leaving do for a colleague. The kind
     * says who the list is about; this says why it exists.
     */
    public function hasOccasion(): bool
    {
        return $this->event_type !== null;
    }

    /**
     * A registry: an occasion, a date, and somewhere to send the parcel.
     *
     * Split from {@see hasOccasion()} when the occasion stopped being
     * registry-only, and the split is the whole point. One method answering
     * both questions is how the delivery-address gate silently widens: the
     * address is the owner's *home*, disclosed to anybody who has claimed
     * something, and only ever appropriate on a list belonging to the person
     * receiving the parcel. A gift list about somebody else has an occasion and
     * must never have an address, so the two questions have to be askable
     * apart.
     */
    public function isRegistry(): bool
    {
        return $this->hasOccasion() && $this->kind === ListKind::Mine;
    }

    /**
     * The list proper.
     *
     * Accepted items only. A pending suggestion is a message to the owner, not
     * something on their list, and every surface that renders "the list" —
     * shared views, the quiz, Secret Santa, claiming — must not see it.
     *
     * @return HasMany<WishlistItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(WishlistItem::class)->whereNotNull('accepted_at');
    }

    /** Everything, including suggestions awaiting a decision. */
    public function allItems(): HasMany
    {
        return $this->hasMany(WishlistItem::class);
    }

    /** @return HasMany<WishlistItem, $this> */
    public function suggestions(): HasMany
    {
        return $this->hasMany(WishlistItem::class)->whereNull('accepted_at');
    }

    /** @return BelongsTo<Recipient, $this> */
    public function recipient(): BelongsTo
    {
        return $this->belongsTo(Recipient::class);
    }

    /** @return BelongsTo<User, $this> */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }

    /** @return HasMany<WishlistCollaborator, $this> */
    public function collaborators(): HasMany
    {
        return $this->hasMany(WishlistCollaborator::class);
    }

    /**
     * Money pledged towards the present, on a group list.
     *
     * Every pledge belongs to the list now; there is no per-item kind to
     * exclude. This used to filter on `whereNull('item_id')`, back when a wish
     * list pooled per item as well.
     *
     * @return HasMany<GiftPledge, $this>
     */
    public function pledges(): HasMany
    {
        return $this->hasMany(GiftPledge::class);
    }

    /**
     * Is this list *about* somebody other than its owner?
     *
     * Delegates rather than re-testing the enum. It used to compare against
     * `ForSomeone` alone, which silently disagreed with
     * `ListKind::isForSomeoneElse()` the moment `Group` existed — and
     * `SharedListController` uses this to decide whose name a visitor is shown.
     * A group list would have answered with the *organiser's* name where the
     * recipient's belongs, telling the people buying the present that the list
     * belongs to the person it is a surprise for.
     */
    public function isForSomeoneElse(): bool
    {
        return $this->kind->isForSomeoneElse();
    }

    /**
     * Is anybody else involved in this list?
     *
     * Most lists are private, of every kind. A gift list about somebody is
     * usually solo research — one person, one present, nobody to coordinate
     * with — and the default wish list every account gets is simply where a
     * bookmark lands. Claiming exists to stop two people buying the same thing,
     * so it is noise until there is a second person, and a claim-privacy
     * setting about an audience of one is worse than noise: it describes
     * readers who do not exist.
     *
     * **Deriving this is safe where deriving the kind was not.**
     * `list-taxonomy.md` rejected a derived `ListKind` because "a list that
     * silently changes address is one people lose". Nothing moves here — same
     * kind, same section, same URL. Only controls appear, on the same list, at
     * the moment they start to mean something.
     *
     * Two ways somebody else gets on a list, and both count. A share link is
     * the one the endpoint cares about; a collaborator is the one the owner's
     * own page cares about, since a list can be worked on together while never
     * being link-shared at all.
     */
    public function hasCoGivers(): bool
    {
        return ($this->visibility?->isShareable() ?? false)
            || $this->collaborators()->exists();
    }

    /**
     * Can anyone holding the link say "I'll get this"?
     *
     * Two questions, and both have to be yes: what the list is *for*, and
     * whether there is anybody to coordinate with. The model answers it so no
     * surface has to re-decide — and every new lens on a list is a chance to
     * re-decide it wrongly.
     *
     * The `hasCoGivers()` half is free for the claim endpoint, which is only
     * reachable through a share token and therefore never sees a private list.
     * It is the *page* that needs it: `Lists/Show` decides from this value
     * whether to draw claim controls at all.
     */
    public function allowsClaiming(): bool
    {
        return $this->kind->allowsClaiming() && $this->hasCoGivers();
    }

    /**
     * May this person put money in?
     *
     * Two questions in one, deliberately, because they are one question — and
     * because the pledge endpoint used to ask them separately and got the second
     * one wrong for a group list.
     *
     * On a `mine` list the owner **is** the person being surprised, so
     * contributions are hidden from them absolutely (invariant #4) and they may
     * not pledge on their own list. On a `group` list the owner is the
     * organiser: the recipient is a third party who never sees the list at all,
     * so there is no surprise to protect from its owner, and refusing them
     * would lock the one person who fronts the money out of the pool.
     *
     * A `for_someone` list allows neither. It is one person's research, and
     * there is nothing to pool against.
     *
     * Somebody with no identity at all is refused too — a pledge is a row
     * belonging to a person, and there is nobody here to own it or to take it
     * back later. Asked here rather than only at the endpoint so the button and
     * the POST answer the same question: this is the value the page renders
     * from, and a control that 403s when pressed is worse than no control.
     */
    public function allowsContributionsFrom(Owner $viewer): bool
    {
        return $viewer->exists()
            && $this->kind->allowsContributions()
            && ($this->kind->ownerSeesContributions() || ! $this->shouldHideClaimsFrom($viewer));
    }

    /**
     * Claim state must never reach the list owner.
     *
     * The whole value of a gift list is that the owner does not know what has
     * been bought. This is the single rule the wishlist feature exists to
     * protect, so it lives on the model rather than in one controller.
     *
     * Takes an `Owner`, not a `User`, because lists work before signup and the
     * *typical* owner is therefore an anonymous identity. The earlier signature
     * accepted `?User` and answered `false` for null — so an anonymous owner
     * opening their own share link was shown exactly what had been claimed,
     * which is the invariant failing in the ordinary case rather than an
     * exotic one.
     */
    /**
     * Is this viewer the person who owns the list?
     *
     * Delegates rather than keeping a third copy of the two-column comparison.
     * This used to be inlined in `shouldHideClaimsFrom()` below, which was fine
     * while identity and claim-privacy were the same question — and they stopped
     * being the same question the moment a gift list's owner could see claims.
     */
    public function isOwnedBy(Owner $viewer): bool
    {
        return ListAccess::isOwner($this, $viewer);
    }

    /**
     * May this person vote on which present the group should buy?
     *
     * Shaped like {@see allowsContributionsFrom()}, and the `exists()` check is
     * there for the same reason found by a test rather than by reading: the
     * page renders its button from this value, and without it a visitor whose
     * cookie identity had not been minted yet is shown a control that 403s when
     * pressed. A vote is a row belonging to somebody, and there has to be a
     * somebody to own it and to take it back later.
     *
     * The owner votes too. On a group list they are the organiser — a member of
     * the group who is also fronting the money — not the person being
     * surprised, so there is nothing to keep from them.
     */
    public function allowsVotingFrom(Owner $viewer): bool
    {
        return $viewer->exists() && $this->kind->allowsVoting();
    }

    /**
     * Claim state must never reach the list owner — on a wish list.
     *
     * The whole value of a wish list is that the owner does not know what has
     * been bought. This is the single rule the feature exists to protect, so it
     * lives on the model rather than in one controller.
     *
     * **It inverts on a list about somebody else, and it is the owner's to
     * decide either way.** There the recipient is a third party who never opens
     * the page, so there is no surprise to protect *from the owner* — and the
     * owner is the person organising the buying. Since 2026-08-29 the owner of
     * a **wish** list may also ask to see claims, and one of a gift list may
     * ask not to; see {@see ownerSeesClaims()} for why that is a default rather
     * than a rule.
     *
     * Exactly the shape `allowsContributionsFrom()` already has for money on a
     * group list. Two mechanisms, one rule: the owner is hidden from precisely
     * when the owner is the person being surprised.
     *
     * Takes an `Owner`, not a `User`, because lists work before signup and the
     * *typical* owner is therefore an anonymous identity. The earlier signature
     * accepted `?User` and answered `false` for null — so an anonymous owner
     * opening their own share link was shown exactly what had been claimed,
     * which is the invariant failing in the ordinary case rather than an
     * exotic one.
     */
    public function shouldHideClaimsFrom(Owner $viewer): bool
    {
        if (! $this->isOwnedBy($viewer)) {
            return false;
        }

        return ! $this->ownerSeesClaims();
    }

    /**
     * Has this owner asked to see what has been claimed off their own list?
     *
     * **This is where invariant #4 now lives, and it is a default rather than
     * an absolute.** A wish list still hides by default — the surprise is the
     * point — and nothing infers otherwise: only an explicit choice by the
     * owner turns it on, stored as a boolean that starts null.
     *
     * Null means never asked, and the kind decides. Storing the default
     * instead would make every list assert a preference nobody expressed, and a
     * later change to what a kind implies would silently skip all of them.
     */
    public function ownerSeesClaims(): bool
    {
        return $this->owner_sees_claims ?? $this->kind->ownerSeesClaimsByDefault();
    }
}
