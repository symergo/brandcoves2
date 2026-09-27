<?php

declare(strict_types=1);

namespace App\Services\Cove;

use App\Enums\EventType;
use App\Enums\Interest;
use App\Enums\ListKind;
use App\Enums\Market;
use App\Enums\RecipientType;
use App\Enums\Source;
use App\Models\User;
use App\Models\Wishlist;
use App\Models\WishlistItem;
use App\Services\Community\PostScreen;
use App\Services\Gift\GiftTags;
use App\Services\Gift\TasteBrief;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Community Coves: lists their owners chose to publish, for other people to
 * find, save and copy (docs/features/community-coves.md).
 *
 * This class is the one place that decides what a stranger may see of a list.
 * Everything that renders a Community Cove (its page, the listing, the band on
 * /coves, Find a gift's suggestions, the saved view) reads it from here, so
 * there is one answer to "does this leak?" rather than five.
 *
 * What a stranger sees: the title the owner wrote for the public, the products,
 * the hand-written items' titles and prices, who the list is for **as a kind of
 * person** ("for a dad") and the occasion ("birthday"), and the owner's first
 * name only if they ticked it. Never: the recipient's name, the list's own title
 * or description, notes, claims, pledges, votes, messages, the share token, a
 * hand-written item's link or photo.
 */
class CommunityCoves
{
    /**
     * Fewer than three things is a thought, not a Cove, and a page with one
     * product on it is the thinnest page this site could publish.
     */
    public const MIN_ITEMS = 3;

    /** The column is varchar(80); a public title is a heading, not a paragraph. */
    public const MAX_TITLE = 80;

    /*
     * When a Community Cove may be indexed by search engines. All three must
     * hold; see "Indexing" in docs/features/community-coves.md.
     *
     * - A week on the site first, so a report and an admin's hide can land
     *   before a crawler does.
     * - Eight things on it, the house minimum for an editorial Cove: below that
     *   the page is a thin list with a title.
     * - Saved by three people other than its owner: the only sign available
     *   that somebody found it worth keeping, standing in for an editor.
     */
    public const INDEX_AFTER_DAYS = 7;

    public const INDEX_MIN_ITEMS = 8;

    public const INDEX_MIN_SAVES = 3;

    public function __construct(private readonly PostScreen $screen) {}

    /**
     * Why this list cannot be published under this title, as a translation key
     * under `site.community`, or null when it can.
     */
    public function problemWith(Wishlist $list, string $title): ?string
    {
        $title = trim($title);

        if ($list->public_hidden_at !== null) {
            return 'problem_hidden';
        }

        if ($title === '' || mb_strlen($title) > self::MAX_TITLE) {
            return 'problem_title';
        }

        if ($this->screen->hold($title) !== null) {
            return 'problem_screen';
        }

        if ($this->namesRecipient($list, $title)) {
            return 'problem_name';
        }

        if ($this->publicItems($list)->count() < self::MIN_ITEMS) {
            return 'problem_items';
        }

        return null;
    }

    /**
     * Put the list on the site, or change its public title while it is there.
     *
     * The slug is made once and kept: a title edited later does not move the
     * address somebody saved or shared. It carries a random suffix so two lists
     * called "Birthday dad" never collide, and so the address says nothing about
     * how many Community Coves exist.
     */
    public function publish(Wishlist $list, string $title, bool $showOwner): void
    {
        $title = trim($title);

        $list->forceFill([
            'public_title' => $title,
            'public_shows_owner' => $showOwner,
            'public_slug' => $list->public_slug ?? $this->slugFor($title),
            // Republishing a Cove that is already up keeps its date: editing
            // the title is not news, and "newest first" should not reward it.
            'published_at' => $list->published_at ?? now(),
        ])->save();
    }

    /**
     * Off the site at once. The slug stays, so the old address can answer 410
     * Gone rather than 404, and republishing brings the same address back.
     */
    public function unpublish(Wishlist $list): void
    {
        $list->forceFill(['published_at' => null])->save();
    }

    /**
     * A published list by its address, in its own market.
     *
     * A Community Cove lives in the market it was made in: the same slug under
     * another market is not a page (invariant 2's spirit: a Cove's prices and
     * products are that market's).
     */
    public function find(Market $market, string $slug): ?Wishlist
    {
        return Wishlist::query()
            ->communityCoves()
            ->where('market', $market->value)
            ->where('public_slug', $slug)
            ->with(['recipient', 'owner', 'items.group'])
            ->withCount('saves')
            ->first();
    }

    /** Was there ever a Community Cove at this address? For the 410. */
    public function existed(Market $market, string $slug): bool
    {
        return Wishlist::query()->where('market', $market->value)->where('public_slug', $slug)->exists();
    }

    /**
     * The items a stranger may see, in the list's own order (newest first).
     *
     * Catalogue products, and hand-written items by title. Left out:
     *
     * - An item whose product has gone and was not hand-written: all that is
     *   left is a snapshot of what a feed once said.
     * - Anything that must be fetched live to be shown (Amazon, invariant 6).
     * - A hand-written item whose title the flat screen holds (a link, an email
     *   address, a phone number): free text on a public page is the one thing
     *   here that could carry spam, and it is dropped rather than shown.
     *
     * @return Collection<int, WishlistItem>
     */
    public function publicItems(Wishlist $list): Collection
    {
        $items = $list->relationLoaded('items')
            ? $list->items
            : $list->items()->with('group')->get();

        return $items
            ->filter(function (WishlistItem $item): bool {
                if ($item->rendersLive()) {
                    return false;
                }

                if ($item->group_id !== null && $item->group !== null) {
                    return true;
                }

                return $item->source === Source::Manual
                    && trim((string) $item->snapshot_title) !== ''
                    && $this->screen->hold((string) $item->snapshot_title) === null;
            })
            ->sortByDesc(fn (WishlistItem $item) => [$item->created_at?->getTimestamp() ?? 0, $item->id])
            ->values();
    }

    /**
     * The items as the public page draws them.
     *
     * @return list<array<string, mixed>>
     */
    public function presentItems(Wishlist $list): array
    {
        return $this->publicItems($list)
            ->map(fn (WishlistItem $item, int $position): array => $item->group !== null
                ? [
                    'key' => 'g'.$item->group->id,
                    'title' => $item->group->displayTitle(),
                    'image' => $item->group->image_url,
                    'price' => $item->group->min_price,
                    'url' => $item->group->path(),
                    'groupId' => $item->group->id,
                    'inStock' => (bool) $item->group->in_stock,
                ]
                : [
                    /*
                     * A hand-written item: its title and the price its owner
                     * typed. Not its link (typed by a person, and a public page
                     * is where a link becomes an advert) and not its photo
                     * (which may be of somebody's home). Keyed by position,
                     * not by row id: the id says nothing a stranger needs.
                     */
                    'key' => 'm'.$position,
                    'title' => (string) $item->snapshot_title,
                    'image' => null,
                    'price' => $item->snapshot_price,
                    'url' => null,
                    'groupId' => null,
                    'inStock' => null,
                ])
            ->values()
            ->all();
    }

    /**
     * Who the list is for, as a kind of person, or null.
     *
     * Only on a list about somebody else (on a wish list of one's own the
     * "recipient" is the owner), and only when the relationship the owner gave
     * is one of the closed values. `recipients.relationship` is free text, and
     * free text there is often a name: "Emma" must never reach a public page.
     */
    public function relationship(Wishlist $list): ?RecipientType
    {
        if (! $list->kind->isForSomeoneElse() || $list->recipient === null) {
            return null;
        }

        return RecipientType::tryFrom(mb_strtolower(trim((string) $list->recipient->relationship)));
    }

    /** The occasion, unless it is the catch-all "something else". */
    public function occasion(Wishlist $list): ?EventType
    {
        return $list->event_type === EventType::Other ? null : $list->event_type;
    }

    /**
     * "For a dad · Birthday", in the reader's language. Empty when the list
     * says neither.
     *
     * @return list<string>
     */
    public function describe(Wishlist $list): array
    {
        $words = [];

        if (($relationship = $this->relationship($list)) !== null) {
            $words[] = Str::ucfirst(__('site.community.for.'.$relationship->value));
        } elseif ($list->kind === ListKind::Mine) {
            $words[] = __('site.community.for_self');
        }

        if (($occasion = $this->occasion($list)) !== null) {
            $words[] = $occasion->label();
        }

        return $words;
    }

    /**
     * The owner's first name, when they asked for it to be shown, else null.
     *
     * The first word of the name on their account and nothing else. Never the
     * part of the email before the @, which `User::displayName()` falls back
     * to and which is fine among friends and a leak on a public page.
     */
    public function ownerName(Wishlist $list): ?string
    {
        if ($list->public_shows_owner !== true || $list->owner === null) {
            return null;
        }

        return self::firstName($list->owner);
    }

    public static function firstName(User $user): ?string
    {
        $name = trim((string) $user->name);

        if ($name === '') {
            return null;
        }

        return mb_substr(Str::before($name, ' '), 0, 30);
    }

    public function url(Wishlist $list): string
    {
        return '/'.$list->market->value.'/coves/community/'.$list->public_slug;
    }

    /**
     * A card for a listing: the band on /coves, the Community Coves index, the
     * Find a gift and the saved view all draw this.
     *
     * @return array<string, mixed>
     */
    public function card(Wishlist $list): array
    {
        $items = $this->publicItems($list);
        $count = $items->count();

        return [
            'title' => (string) $list->public_title,
            'intro' => implode(' · ', [
                ...$this->describe($list),
                trans_choice('site.community.ideas', $count, ['count' => $count]),
            ]),
            'url' => $this->url($list),
            'image' => $items->first(fn (WishlistItem $item) => $item->group?->image_url !== null)?->group?->image_url,
            'saves' => $saves = (int) ($list->saves_count ?? 0),
            'savesLabel' => $saves === 0 ? null : trans_choice('site.community.saved_by', $saves, ['count' => $saves]),
            'date' => null,
        ];
    }

    /**
     * A market's Community Coves, a page at a time.
     *
     * `saved` orders by how many people keep it, newest first among equals;
     * anything else is newest first.
     *
     * @return LengthAwarePaginator<int, Wishlist>
     */
    public function listing(Market $market, string $sort = 'new', int $perPage = 24): LengthAwarePaginator
    {
        return $this->query($market)
            ->when($sort === 'saved', fn (Builder $q) => $q->orderByDesc('saves_count'))
            ->orderByDesc('published_at')
            ->orderByDesc('id')
            ->paginate($perPage)
            ->withQueryString();
    }

    /**
     * The newest few, for the band on /coves.
     *
     * @return list<array<string, mixed>>
     */
    public function newest(Market $market, int $limit): array
    {
        return $this->query($market)
            ->orderByDesc('published_at')
            ->limit($limit)
            ->get()
            ->map(fn (Wishlist $list) => $this->card($list))
            ->values()
            ->all();
    }

    /**
     * The Community Coves whose public title matches a search term, most saved
     * first, as cards.
     *
     * The public title only: the list's own title and description are never
     * public (see the table in docs/features/community-coves.md), so they must
     * not be searchable either, or a search would confirm what a private
     * title says. Full text in the market's language, like the product
     * search. A Cove with fewer than three visible things is skipped, as in
     * the Gift Finder.
     *
     * @return list<array<string, mixed>>
     */
    public function matching(Market $market, string $term, int $limit): array
    {
        return $this->query($market)
            ->whereRaw(
                "to_tsvector(bc_text_config(?), coalesce(wishlists.public_title, '')) @@ websearch_to_tsquery(bc_text_config(?), ?)",
                [$market->value, $market->value, $term],
            )
            ->orderByDesc('saves_count')
            ->orderByDesc('published_at')
            ->limit($limit * 2)
            ->get()
            ->filter(fn (Wishlist $list) => $this->publicItems($list)->count() >= self::MIN_ITEMS)
            ->take($limit)
            ->map(fn (Wishlist $list) => $this->card($list))
            ->values()
            ->all();
    }

    /**
     * "Coves others made for someone like this": the Community Coves nearest a
     * Find a gift brief.
     *
     * A Cove qualifies when it is for the same kind of person or holds products
     * tagged with one of the brief's interests (by editors or by people's
     * lists). The occasion only breaks ties: "a birthday" alone says too
     * little about a person to suggest a stranger's list. The viewer's own
     * Coves are left out; they already know what is on them.
     *
     * @return list<array<string, mixed>>
     */
    public function forBrief(TasteBrief $brief, ?User $viewer = null, int $limit = 3): array
    {
        $relationship = $brief->relationship === null
            ? null
            : RecipientType::tryFrom(mb_strtolower(trim($brief->relationship)));

        $interests = array_values(array_filter(
            $brief->interests,
            fn (string $interest) => Interest::tryFrom($interest) !== null,
        ));

        if ($relationship === null && $interests === []) {
            return [];
        }

        $tags = '{'.implode(',', array_map(fn (string $i) => GiftTags::INTEREST.':'.$i, $interests)).'}';

        $forThem = "(wishlists.kind <> 'mine' AND EXISTS (SELECT 1 FROM recipients r WHERE r.id = wishlists.recipient_id AND lower(trim(r.relationship)) = ?))";
        $likesIt = 'EXISTS (SELECT 1 FROM wishlist_items wi JOIN product_groups g ON g.id = wi.group_id'
            .' WHERE wi.wishlist_id = wishlists.id AND wi.accepted_at IS NOT NULL'
            .' AND (jsonb_exists_any(g.gift_tags, ?::text[]) OR jsonb_exists_any(g.crowd_tags, ?::text[])))';
        $occasion = EventType::tryFrom((string) $brief->occasion);

        $fit = [];
        $bindings = [];

        if ($relationship !== null) {
            $fit[] = "(CASE WHEN {$forThem} THEN 2 ELSE 0 END)";
            $bindings[] = $relationship->value;
        }

        if ($interests !== []) {
            $fit[] = "(CASE WHEN {$likesIt} THEN 2 ELSE 0 END)";
            array_push($bindings, $tags, $tags);
        }

        if ($occasion !== null && $occasion !== EventType::Other) {
            $fit[] = '(CASE WHEN wishlists.event_type = ? THEN 1 ELSE 0 END)';
            $bindings[] = $occasion->value;
        }

        $expression = implode(' + ', $fit);

        return $this->query($brief->market)
            ->when($viewer !== null, fn (Builder $q) => $q->where(fn (Builder $w) => $w
                ->whereNull('wishlists.owner_user_id')
                ->orWhere('wishlists.owner_user_id', '<>', $viewer->id)))
            ->where(function (Builder $q) use ($relationship, $interests, $forThem, $likesIt, $tags): void {
                if ($relationship !== null) {
                    $q->orWhereRaw($forThem, [$relationship->value]);
                }

                if ($interests !== []) {
                    $q->orWhereRaw($likesIt, [$tags, $tags]);
                }
            })
            ->orderByRaw("({$expression}) DESC", $bindings)
            ->orderByDesc('saves_count')
            ->orderByDesc('published_at')
            ->limit($limit)
            ->get()
            ->filter(fn (Wishlist $list) => $this->publicItems($list)->count() >= self::MIN_ITEMS)
            ->map(fn (Wishlist $list) => $this->card($list))
            ->values()
            ->all();
    }

    /**
     * May search engines index this Community Cove? See the constants above.
     */
    public function isIndexable(Wishlist $list): bool
    {
        return $list->isCommunityCove()
            && $list->published_at !== null
            && $list->published_at->lte(now()->subDays(self::INDEX_AFTER_DAYS))
            && $this->publicItems($list)->count() >= self::INDEX_MIN_ITEMS
            && (int) ($list->saves_count ?? $list->saves()->count()) >= self::INDEX_MIN_SAVES;
    }

    /**
     * What to prefill the public title with.
     *
     * The list's own title when it would pass (it is usually the best name:
     * "Board games", "Camping"), otherwise words we write from what the list
     * says about itself. Never a title that names the recipient.
     */
    public function suggestedTitle(Wishlist $list): string
    {
        $own = trim($list->displayTitle());

        if ($own !== ''
            && mb_strlen($own) <= self::MAX_TITLE
            && $this->screen->hold($own) === null
            && ! $this->namesRecipient($list, $own)
            && ! $list->is_default) {
            return $own;
        }

        $relationship = $this->relationship($list);

        return $relationship === null
            ? __('site.community.default_title')
            : __('site.community.default_title_for', ['for' => __('site.community.for.'.$relationship->value)]);
    }

    /**
     * Does this title carry the name of the person the list is about?
     *
     * The recipient's name, word by word, matched as a whole word. A name
     * shorter than three letters is skipped ("Jo" would refuse "Joy"): a
     * check that refuses ordinary titles teaches people to work around it.
     */
    public function namesRecipient(Wishlist $list, string $title): bool
    {
        $name = trim((string) $list->recipient?->name);

        if ($name === '') {
            return false;
        }

        foreach (preg_split('/[\s,]+/u', $name) ?: [] as $part) {
            if (mb_strlen($part) < 3) {
                continue;
            }

            if (preg_match('/(?<![\p{L}\p{N}])'.preg_quote($part, '/').'(?![\p{L}\p{N}])/iu', $title) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * The market's Community Coves with what every card needs loaded, and the
     * save count for ordering and for the card.
     *
     * @return Builder<Wishlist>
     */
    private function query(Market $market): Builder
    {
        return Wishlist::query()
            ->communityCoves()
            ->where('wishlists.market', $market->value)
            /*
             * Only the columns a card reads.
             *
             * A card needs the count of what a stranger may see and one
             * picture, and publicItems() decides the first in PHP (the flat
             * screen that drops a hand-written title with a link in it has no
             * SQL twin), so the items still load. But not whole: every column
             * of every item and every column of its product (descriptions,
             * tags, search fields) was loaded for a listing of twenty-four
             * cards. These are the columns publicItems() and card() touch.
             */
            ->with([
                'recipient',
                'items' => fn ($items) => $items->select([
                    'id', 'wishlist_id', 'group_id', 'source', 'snapshot_title', 'created_at',
                ]),
                'items.group' => fn ($group) => $group->select(['id', 'image_url']),
            ])
            ->withCount('saves');
    }

    private function slugFor(string $title): string
    {
        $base = Str::limit(Str::slug($title), 60, '');
        $base = trim($base, '-');

        return ($base === '' ? 'cove' : $base).'-'.Str::lower(Str::random(6));
    }
}
