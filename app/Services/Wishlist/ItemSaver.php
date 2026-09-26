<?php

declare(strict_types=1);

namespace App\Services\Wishlist;

use App\Enums\Source;
use App\Jobs\ReadItemLink;
use App\Models\ProductGroup;
use App\Models\Wishlist;
use App\Models\WishlistItem;
use App\Services\Alerts\ListPriceWatch;
use App\Services\Identity\Gtin;
use App\Support\CurrentMarket;
use InvalidArgumentException;

/**
 * The one place a product becomes a list entry.
 *
 * Both ways of filling a list end here — a typed search and a suggestion from
 * the engine — and that is deliberate. Two save paths means the Amazon rule
 * gets applied in one of them and forgotten in the other, and the forgotten one
 * is a compliance breach that looks exactly like a working feature.
 */
class ItemSaver
{
    /**
     * Save a product we hold a group for.
     *
     * Snapshot, not just a reference: a feed can drop or rename this product
     * tomorrow, and the list must still show what the person actually chose, at
     * the price they saw. That is the record of their decision and it should not
     * silently rewrite itself.
     *
     * ## Why the owner may retitle it
     *
     * `$title` overrides the catalogue's wording. Feed titles are written for a
     * search engine — "Merk XY-3000 draadloze koptelefoon met ANC, zwart, 2024"
     * — and a list is read by a person, sometimes a person choosing a present
     * under time pressure. "De koptelefoon die ik wil" is more use to them than
     * the SKU.
     *
     * This does not contradict the snapshot rule above. That rule is about the
     * entry not rewriting *itself*; a title the owner typed on purpose is the
     * same kind of fact as the note beside it. `group_id` still points at the
     * real product, so the price, the link and the offer comparison are
     * unaffected — only the words change.
     *
     * Null leaves the catalogue title alone, which matters because this is an
     * `updateOrCreate`: saving the same product again from a card must not
     * quietly undo a title somebody wrote here.
     */
    public function saveGroup(
        Wishlist $list,
        ProductGroup $group,
        CurrentMarket $current,
        ?string $note = null,
        ?string $title = null,
    ): WishlistItem {
        $existing = WishlistItem::query()
            ->where('wishlist_id', $list->id)
            ->where('group_id', $group->id)
            ->first();

        $item = WishlistItem::updateOrCreate(
            ['wishlist_id' => $list->id, 'group_id' => $group->id],
            [
                'snapshot_title' => match (true) {
                    filled($title) => trim((string) $title),
                    $existing !== null => $existing->snapshot_title,
                    default => $group->displayTitle(),
                },
                'snapshot_image_url' => $group->image_url,
                'snapshot_price' => $group->min_price,
                /*
                 * The product's own market, not the reader's.
                 *
                 * `$current` is whichever market the URL carried, and a list is
                 * not scoped to one: saving an `nl-nl` product while reading a
                 * shared list under `/be-nl/` stored `/be-nl/p/{an nl-nl id}/…`,
                 * which is not a page. `ProductGroup::path()` is the one place
                 * that answers this.
                 */
                'snapshot_url' => $group->path(),
                // Kept for the same reason as the title, and it was already
                // being lost: every save from a product card posts no note, so
                // re-saving something you had annotated erased the annotation.
                'note' => $note ?? $existing?->note,
                // Put there by somebody entitled to. A suggestion is written by
                // SuggestionController, which nulls this afterwards.
                'accepted_at' => now(),
            ],
        );

        /*
         * A list that watches its prices starts watching the newcomer now, at
         * today's price, rather than on the next morning's pass. Otherwise a
         * drop between this save and 07:40 tomorrow would be measured from
         * nothing and reported from nowhere.
         */
        if ($list->watchesPrices() && $item->watch_seeded_at === null) {
            app(ListPriceWatch::class)->seedItem($item);
        }

        $list->touch();

        return $item;
    }

    /**
     * Save something we do not sell, typed in by hand.
     *
     * A list has always been able to hold a wish that is not a catalogue product
     * — `wishlist_items_identifiable` was widened for exactly that — and this is
     * the path that finally writes one.
     *
     * ## The link is looked up, in a queued job
     *
     * Until 2026-09-26 nothing was ever fetched from the link, and the reasons
     * were right: a server that fetches what it is handed is an SSRF probe
     * anybody can point at anything, and a remote image on a shared list is a
     * tracking pixel reporting who opened it. Roadmap step 1
     * (docs/strategy.md) keeps both reasons and answers them instead of
     * avoiding the link:
     *
     * - {@see ReadItemLink} runs after the save, never in the request. It asks
     *   our catalogue and the connectors first (`LinkRouter`: an Amazon ASIN,
     *   a bol or eBay id, a feed merchant's deep link) and reads the shop's
     *   page only when nothing recognises it, through `SafeFetch`, which
     *   refuses private addresses on every hop.
     * - A picture from the page is copied to our own storage and re-encoded
     *   (`ImageStore`), so a shared list never loads one from a stranger's host.
     *
     * A title is optional when there is a link: the item shows the shop's
     * host until the page is read, and only that placeholder is ever replaced.
     * See docs/features/pasted-links.md.
     *
     * ## No `external_id`
     *
     * There is nothing to re-fetch and no upstream identity to collide with, so
     * this is a plain insert rather than an `updateOrCreate`. Two entries called
     * "a nice scarf, dark green" are two wishes, not a double-tap — and the
     * partial unique index only binds when `external_id` is present.
     */
    public function saveManual(
        Wishlist $list,
        ?string $title,
        ?string $url = null,
        ?int $price = null,
        ?string $note = null,
        ?string $gtin = null,
    ): WishlistItem {
        // Re-checked here rather than trusted from the request. The rule is
        // the model's, and this is the only place a manual URL is written.
        $url = WishlistItem::isSafeExternalUrl($url) ? trim((string) $url) : null;
        $title = trim((string) $title);

        if ($title === '') {
            $title = WishlistItem::placeholderTitle($url);
        }

        if ($title === '') {
            // Neither a title nor a link: nothing to call it. The controller
            // requires one of the two, so this is a caller's bug.
            throw new InvalidArgumentException('A hand-written item needs a title or a link.');
        }

        $item = $list->allItems()->create([
            'source' => Source::Manual->value,
            'external_id' => null,
            'snapshot_title' => $title,
            'snapshot_image_url' => null,
            'snapshot_price' => $price,
            'snapshot_url' => $url,
            'note' => $note,
            // Kept for the nightly pass that joins it to its product once a
            // shop carries it. See LinkBarcodeItems.
            'gtin' => Gtin::normalise($gtin),
            'link_status' => $url === null ? null : 'pending',
            // Suggestions null this immediately afterwards, exactly as they do
            // for a catalogue save.
            'accepted_at' => now(),
        ]);

        $this->readLinkOf($item);

        $list->touch();

        return $item;
    }

    /**
     * Look the item's link up, after the save has committed.
     *
     * Also called when somebody edits the link on an item they typed: the new
     * link deserves the same lookup as the first one.
     */
    public function readLinkOf(WishlistItem $item): void
    {
        if ($item->link_status === 'pending') {
            ReadItemLink::dispatch($item->id)->afterCommit();
        }
    }

    /**
     * Save a product from a source we do not mirror.
     *
     * Live bol results and Amazon products are both reachable from search and
     * were both unsaveable until now, for the same reason: no stored group. They
     * are stored differently from each other, though, and the difference is not
     * cosmetic.
     *
     * `Source::allowsCatalogueStorage()` is the gate. For bol we may keep a
     * snapshot, so the entry behaves like every other one. For Amazon we may
     * store **the decision and nothing else** — the ASIN — and re-fetch title,
     * image, price and availability at render (invariant #6). Writing a snapshot
     * "just in case" would be a mirror of the catalogue, which is precisely what
     * the rule forbids.
     *
     * @param  array{title?: string|null, image_url?: string|null, price?: int|null, url?: string|null}  $snapshot
     */
    public function saveExternal(
        Wishlist $list,
        Source $source,
        string $externalId,
        array $snapshot = [],
        ?string $note = null,
    ): WishlistItem {
        $storable = $source->allowsCatalogueStorage();

        $item = WishlistItem::updateOrCreate(
            [
                'wishlist_id' => $list->id,
                'group_id' => null,
                'source' => $source->value,
                'external_id' => $externalId,
            ],
            [
                // A title is NOT NULL, and a live-rendered row genuinely has
                // none to store. The placeholder is never displayed — see
                // WishlistItem::rendersLive() — but the column has to hold
                // something, and an empty string would read as "we lost it".
                'snapshot_title' => $storable
                    ? (string) ($snapshot['title'] ?? '')
                    : $source->label(),
                'snapshot_image_url' => $storable ? ($snapshot['image_url'] ?? null) : null,
                'snapshot_price' => $storable ? ($snapshot['price'] ?? null) : null,
                'snapshot_url' => $storable ? ($snapshot['url'] ?? null) : null,
                'note' => $note,
                'accepted_at' => now(),
            ],
        );

        $list->touch();

        return $item;
    }
}
