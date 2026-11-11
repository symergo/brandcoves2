<?php

declare(strict_types=1);

namespace Tests\Feature\Concerns;

use App\Enums\Market;
use App\Enums\Source;
use App\Models\PopularRank;
use App\Models\ProductGroup;
use App\Models\Wishlist;
use App\Models\WishlistItem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The two demand signals a ranking reads, written directly for be-nl: a product
 * on visitors' wish lists, and a product on a shop's bestseller chart.
 *
 * Shared by EntityRailsTest and PersonaTopTenTest.
 */
trait MakesDemandSignals
{
    /** An anonymous visitor, the owner a wish list needs when nobody signed in. */
    private function anon(): string
    {
        $id = (string) Str::uuid();

        DB::table('anonymous_identities')->insert([
            'id' => $id,
            'last_seen_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }

    /**
     * The product on `$lists` wish lists, each of a different visitor.
     *
     * @return list<Wishlist>
     */
    private function wish(ProductGroup $group, int $lists): array
    {
        $made = [];

        for ($i = 0; $i < $lists; $i++) {
            $list = Wishlist::create([
                'owner_anon_id' => $this->anon(),
                'title' => "Lijst {$i}",
                'market' => Market::BeNl->value,
            ]);
            WishlistItem::create([
                'wishlist_id' => $list->id,
                'group_id' => $group->id,
                // Snapshotted on every item, so a list survives its product
                // disappearing from the catalogue.
                'snapshot_title' => $group->title,
            ]);
            $made[] = $list;
        }

        return $made;
    }

    /** The product at `$rank` on today's bol chart. */
    private function chart(ProductGroup $group, int $rank): void
    {
        PopularRank::create([
            'source' => Source::Bol->value,
            'market' => Market::BeNl->value,
            'external_id' => 'x'.bin2hex(random_bytes(4)),
            'group_id' => $group->id,
            'rank' => $rank,
            'captured_on' => now()->toDateString(),
            'captured_at' => now(),
        ]);
    }
}
