<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Source;
use Database\Factories\WishlistItemFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WishlistItem extends Model
{
    /** @use HasFactory<WishlistItemFactory> */
    use HasFactory;

    protected $guarded = [];

    /**
     * Never serialise either claim field.
     *
     * `claimed_by_hash` is one-way, but its mere presence tells the list owner
     * that an item has been claimed — and `marked_sent_at` leaks exactly the
     * same secret one step further along. Both belong here for the same reason,
     * and the second was the easy one to forget.
     */
    protected $hidden = ['claimed_by_hash', 'marked_sent_at'];

    protected function casts(): array
    {
        return [
            'claimed_at' => 'datetime',
            'marked_sent_at' => 'datetime',
            'source' => Source::class,
            'accepted_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Wishlist, $this> */
    public function wishlist(): BelongsTo
    {
        return $this->belongsTo(Wishlist::class);
    }

    /**
     * Votes for this candidate, on a group list.
     *
     * @return HasMany<ListItemVote, $this>
     */
    public function votes(): HasMany
    {
        return $this->hasMany(ListItemVote::class, 'item_id');
    }

    /** @return BelongsTo<ProductGroup, $this> */
    public function group(): BelongsTo
    {
        return $this->belongsTo(ProductGroup::class, 'group_id');
    }

    /**
     * Who proposed this, when it was somebody other than the owner.
     *
     * @return BelongsTo<User, $this>
     */
    public function suggestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'suggested_by_user_id');
    }

    /** @return HasMany<GiftPledge, $this> */
    public function pledges(): HasMany
    {
        return $this->hasMany(GiftPledge::class, 'item_id');
    }

    /**
     * Must this item's title, image and price be fetched at render time?
     *
     * Amazon may not be mirrored (invariant #6), so its rows deliberately carry
     * no snapshot at all — which puts them in direct tension with the rule that
     * every *other* item snapshots on purpose, so a dropped feed product still
     * shows what the person chose. Both rules are right; the source decides
     * which one applies.
     *
     * A failed fetch hides the item rather than showing stale data.
     */
    public function rendersLive(): bool
    {
        return $this->source !== null && ! $this->source->allowsCatalogueStorage();
    }

    /**
     * Is this a link we are willing to put in an `href`?
     *
     * `https:` and nothing else — the same test `Product::hasSafeAffiliateUrl()`
     * applies to feed URLs, applied here because a manual item's link is worse
     * input than a feed's: it is typed by a person, into a page other people
     * open from a link they were sent.
     *
     * HTML escaping does not help. `javascript:alert(1)` survives it intact and
     * runs on click, and `http:` is downgraded rather than dangerous but still
     * has no business being offered as "where to buy it".
     *
     * A static so the write path, the read path and the validation message all
     * ask the same question. Three copies of a scheme check is how two of them
     * end up disagreeing.
     */
    public static function isSafeExternalUrl(?string $url): bool
    {
        if (! is_string($url) || trim($url) === '') {
            return false;
        }

        $scheme = parse_url(trim($url), PHP_URL_SCHEME);

        return is_string($scheme) && strtolower($scheme) === 'https';
    }

    /**
     * Where a manually added item says you can buy it.
     *
     * Scoped to `manual` rows on purpose: every other item's `snapshot_url` is
     * *our* product page, stored as a root-relative path with no scheme at all,
     * so running those through the check above would reject every one of them
     * and quietly unlink the entire catalogue half of the list.
     *
     * Null when the stored link is unsafe, so a caller cannot render a bad link
     * even by accident — the same shape as `Product::outboundUrl()`.
     */
    public function externalUrl(): ?string
    {
        if ($this->source !== Source::Manual) {
            return null;
        }

        return self::isSafeExternalUrl($this->snapshot_url) ? trim((string) $this->snapshot_url) : null;
    }

    /**
     * Our own page for this item, in the market the product is in.
     *
     * **Not the market the reader is browsing.** A wish list is not scoped to a
     * market — you keep one list and shop from wherever you happen to be — so a
     * list routinely holds a `nl-nl` group and a `be-fr` one at the same time,
     * and every caller here used to prefix the path with `CurrentMarket`. Under
     * `/en/` that produced `/en/p/{a nl-nl group id}/…`, which is not a page:
     * `product_groups` is unique on `(market, identity_key)` per invariant #2,
     * and the id belongs to exactly one of them.
     *
     * So the market comes from the group, and the reader's market is not
     * consulted at all. Following the link switches market, which is correct
     * and is what the visitor asked for by opening a product they saved there —
     * `SetMarket` reads the prefix, and only the switcher writes the cookie, so
     * it does not repoint their home market.
     *
     * Null when the group has gone (a feed dropped it, `nullOnDelete`). The row
     * still renders from its snapshot; it just stops being a link.
     */
    public function productPath(): ?string
    {
        if ($this->group === null) {
            return null;
        }

        return '/'.$this->group->market->value."/p/{$this->group_id}/{$this->group->slug}";
    }

    public function isClaimed(): bool
    {
        return $this->claimed_by_hash !== null;
    }

    /**
     * Mark a claimed item as bought and on its way.
     *
     * Restricted to the person holding the claim: "someone bought it" is the
     * claimer's fact to report, and anyone else asserting it would strand a gift
     * that nobody has actually sent.
     */
    public function markSent(string $identityHash): bool
    {
        return static::query()
            ->whereKey($this->getKey())
            ->where('claimed_by_hash', $identityHash)
            ->update(['marked_sent_at' => now(), 'updated_at' => now()]) === 1;
    }

    /**
     * Claim this item, if nobody else already has.
     *
     * Two people opening a shared gift list and tapping "I'll get this" at the
     * same moment is the expected case, not an edge case. A read-then-write
     * would let both succeed and the recipient would get two of the same thing,
     * so the check and the write are one atomic statement and the affected-row
     * count is the answer.
     */
    public function claim(string $identityHash, ?string $name = null): bool
    {
        $claimed = static::query()
            ->whereKey($this->getKey())
            ->whereNull('claimed_by_hash')
            ->update([
                'claimed_by_hash' => $identityHash,
                /*
                 * Null unless the list asked for a name, which the *caller*
                 * decides — the list's setting is not this model's to read, and
                 * an item that went looking for it would be a second place to
                 * get the consent question wrong.
                 */
                'claimed_by_name' => $name,
                'claimed_at' => now(),
                'updated_at' => now(),
            ]);

        if ($claimed === 1) {
            $this->forceFill([
                'claimed_by_hash' => $identityHash,
                'claimed_by_name' => $name,
                'claimed_at' => now(),
            ]);

            return true;
        }

        return false;
    }

    /** Only the person who claimed it may release it, and only within the undo window. */
    public function release(string $identityHash): bool
    {
        $undoHours = (int) config('giftcoves.wishlist.claim_undo_hours');

        return static::query()
            ->whereKey($this->getKey())
            ->where('claimed_by_hash', $identityHash)
            ->where('claimed_at', '>=', now()->subHours($undoHours))
            ->update([
                'claimed_by_hash' => null,
                // The name belonged to that claim, not to the item. Leaving it
                // behind would name somebody as the buyer of something they
                // have just handed back.
                'claimed_by_name' => null,
                'claimed_at' => null,
                'updated_at' => now(),
            ]) === 1;
    }

    /**
     * One-way identity hash. Uses a dedicated app secret rather than APP_KEY so
     * that rotating APP_KEY does not orphan every existing claim.
     */
    public static function identityHash(string $identity): string
    {
        return hash_hmac('sha256', $identity, (string) config('giftcoves.wishlist.claim_hash_secret'));
    }
}
