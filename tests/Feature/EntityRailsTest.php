<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\Availability;
use App\Enums\CoveKind;
use App\Enums\Market;
use App\Enums\ProductStatus;
use App\Enums\PublishStatus;
use App\Enums\Source;
use App\Models\BrandStat;
use App\Models\DailyPickSet;
use App\Models\Merchant;
use App\Models\PopularRank;
use App\Models\Product;
use App\Models\ProductGroup;
use App\Models\Wishlist;
use App\Models\WishlistItem;
use App\Services\Cove\EntityRails;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The product rails under an entity Cove.
 *
 * The wishlist floor is the test that matters. Getting it wrong is a privacy
 * bug rather than a layout one: below three distinct lists the rail stops being
 * an aggregate and becomes a way of asking whether one particular person wants
 * one particular thing.
 */
class EntityRailsTest extends TestCase
{
    use RefreshDatabase;

    private Merchant $merchant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2027-04-15')->setTime(12, 0));
        Cache::flush();

        $this->merchant = Merchant::create([
            'source' => Source::Awin->value,
            'external_id' => 'shop',
            'name' => 'Shop',
            'domain' => 'shop.be',
        ]);
    }

    #[Test]
    public function a_product_on_two_lists_does_not_appear_and_one_on_three_does(): void
    {
        /*
         * The threshold *is* the anonymity. With one list, a shared list and a
         * brand page together identify an individual's list; the floor is what
         * makes the count an aggregate rather than a lookup with extra steps.
         */
        $shy = $this->product('Stille koptelefoon', 9900, 'Sony');
        $wanted = $this->product('Gewilde koptelefoon', 12900, 'Sony');

        $this->wish($shy, 2);
        $this->wish($wanted, EntityRails::WISHLIST_FLOOR);

        $rails = app(EntityRails::class)->forBrand($this->brand('Sony'), Market::BeNl);

        $ids = array_column($rails['wishlisted'], 'id');

        $this->assertContains($wanted->id, $ids);
        $this->assertNotContains($shy->id, $ids);
    }

    #[Test]
    public function deleting_a_list_takes_the_product_back_below_the_floor(): void
    {
        /*
         * Computed live and cached, never stored in a snapshot table — so a list
         * its owner deletes, or one reaped by `bc:prune-personal-data`, actually
         * leaves the rail rather than persisting in an aggregate nobody thinks
         * to prune.
         */
        $group = $this->product('Gewilde koptelefoon', 12900, 'Sony');
        $lists = $this->wish($group, 3);

        $rails = app(EntityRails::class)->forBrand($this->brand('Sony'), Market::BeNl);
        $this->assertContains($group->id, array_column($rails['wishlisted'], 'id'));

        $lists[0]->delete();
        Cache::flush();

        $rails = app(EntityRails::class)->forBrand($this->brand('Sony'), Market::BeNl);
        $this->assertNotContains($group->id, array_column($rails['wishlisted'], 'id'));
    }

    #[Test]
    public function the_rail_counts_lists_rather_than_rows(): void
    {
        // One person with four lists is not four people wanting a thing, and
        // counting rows would let a single enthusiastic list clear the floor.
        $group = $this->product('Gewilde koptelefoon', 12900, 'Sony');

        $list = Wishlist::create([
            'owner_anon_id' => $this->anon(),
            'title' => 'Eén lijst',
            'market' => Market::BeNl->value,
        ]);

        // The unique index stops a genuine duplicate, so the point is asserted
        // on the count itself: one list is one, whatever else it holds.
        WishlistItem::create([
            'wishlist_id' => $list->id,
            'group_id' => $group->id,
            // Snapshotted on every item, so a list survives its product
            // disappearing from the catalogue.
            'snapshot_title' => $group->title,
        ]);
        $other = $this->product('Iets anders', 4900, 'Sony');

        WishlistItem::create([
            'wishlist_id' => $list->id,
            'group_id' => $other->id,
            'snapshot_title' => $other->title,
        ]);

        $rails = app(EntityRails::class)->forBrand($this->brand('Sony'), Market::BeNl);

        $this->assertSame([], $rails['wishlisted']);
    }

    #[Test]
    public function nothing_in_the_rail_touches_claim_state(): void
    {
        /*
         * Invariant 4. The rail reports **membership** — this product is on some
         * lists — and says nothing about whether anybody has bought it. The two
         * are different questions and only one of them is safe to aggregate.
         */
        $group = $this->product('Gewilde koptelefoon', 12900, 'Sony');
        $this->wish($group, 3);

        $rails = app(EntityRails::class)->forBrand($this->brand('Sony'), Market::BeNl);

        $row = collect($rails['wishlisted'])->firstWhere('id', $group->id);

        $this->assertNotNull($row);

        foreach (['claimed', 'claimed_by_hash', 'claimedBy', 'listCount', 'lists'] as $leak) {
            $this->assertArrayNotHasKey($leak, $row, "the rail exposed {$leak}");
        }
    }

    #[Test]
    public function the_discount_rail_is_ordered_by_the_drop(): void
    {
        $small = $this->product('Kleine korting', 9000, 'Sony', discount: 10);
        $big = $this->product('Grote korting', 9000, 'Sony', discount: 40);
        $this->product('Geen korting', 9000, 'Sony');

        $rails = app(EntityRails::class)->forBrand($this->brand('Sony'), Market::BeNl);

        $this->assertSame([$big->id, $small->id], array_column($rails['discounts'], 'id'));
    }

    #[Test]
    public function the_popular_rail_reads_the_chart(): void
    {
        $second = $this->product('Tweede', 9900, 'Sony');
        $first = $this->product('Eerste', 9900, 'Sony');

        $this->chart($second, 12);
        $this->chart($first, 3);

        $rails = app(EntityRails::class)->forBrand($this->brand('Sony'), Market::BeNl);

        $this->assertSame([$first->id, $second->id], array_column($rails['popular'], 'id'));
    }

    #[Test]
    public function a_brand_rail_follows_every_spelling_of_the_brand(): void
    {
        /*
         * Feeds disagree about punctuation: "Audio-Technica" and "Audio
         * Technica" are one brand, which is why `brand_stats` carries the
         * spellings in `aliases` and the folding is done in PHP.
         */
        $hyphen = $this->product('Met koppelteken', 9900, 'Audio-Technica', discount: 30);
        $space = $this->product('Met spatie', 9900, 'Audio Technica', discount: 20);

        $brand = BrandStat::create([
            'market' => Market::BeNl->value,
            'brand' => 'Audio-Technica',
            'slug' => 'audio-technica',
            'aliases' => ['Audio Technica'],
            'product_count' => 2,
        ]);

        $rails = app(EntityRails::class)->forBrand($brand, Market::BeNl);

        $this->assertSame([$hyphen->id, $space->id], array_column($rails['discounts'], 'id'));
    }

    #[Test]
    public function a_shop_rail_is_scoped_to_what_that_shop_sells(): void
    {
        $mine = $this->product('Van deze winkel', 9900, 'Sony', discount: 30);

        $other = Merchant::create([
            'source' => Source::Awin->value,
            'external_id' => 'other',
            'name' => 'Andere',
            'domain' => 'andere.be',
        ]);

        $theirs = $this->product('Van een andere', 9900, 'Sony', discount: 50, merchant: $other);

        $rails = app(EntityRails::class)->forShop($this->merchant, Market::BeNl);

        $ids = array_column($rails['discounts'], 'id');

        $this->assertContains($mine->id, $ids);
        $this->assertNotContains($theirs->id, $ids);
    }

    #[Test]
    public function a_brand_cove_and_its_rails_render_on_the_brand_page(): void
    {
        /*
         * Above the grid, on the page that already exists. Not at a second
         * address: `/brand/{slug}` is the one canonical indexable URL per brand
         * per market and every brand mention on the site points at it.
         */
        $group = $this->product('Gewilde koptelefoon', 12900, 'Sony', discount: 30);
        $this->wish($group, EntityRails::WISHLIST_FLOOR);

        $this->brand('Sony');

        DailyPickSet::create([
            'market' => Market::BeNl->value,
            'kind' => CoveKind::Brand->value,
            'slug' => 'sony',
            'theme_title' => 'Wat Sony maakt',
            'theme_slug' => 'sony',
            'theme_blurb' => 'Waar het over gaat.',
            /*
             * `audio` is the category these products are actually in, and the
             * allowlist is built from exactly that. A token naming anything else
             * renders as plain words — which is the safety property, and the
             * reason the vocabulary has to come from the entity's own shelf
             * rather than from the writer's imagination.
             */
            'body' => 'Kijk vooral naar [[search:audio]].',
            'status' => PublishStatus::Published->value,
            'published_at' => now(),
        ]);

        $this->get('/be-nl/brand/sony')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('cove.title', 'Wat Sony maakt')
                // The prose is rendered, so a search token became a real,
                // crawlable link into this market — which is the point of an
                // entity Cove rather than a decoration on it.
                ->where('cove.body', fn (string $body) => str_contains($body, '<a')
                    && str_contains($body, '/be-nl/'))
                ->has('rails.discounts', 1)
                ->has('rails.wishlisted', 1)
            );
    }

    #[Test]
    public function a_brand_with_no_cove_renders_the_page_unchanged(): void
    {
        // Null for the great majority of brands, which is why the templated
        // copy below the grid stays. The two are not duplicates.
        $this->product('Gewone koptelefoon', 9900, 'Sony');
        $this->brand('Sony');

        $this->get('/be-nl/brand/sony')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('cove', null));
    }

    // ── Helpers ───────────────────────────────────────────────────────────

    /**
     * One anonymous owner per list.
     *
     * `wishlists_one_owner` requires exactly one of a user or an anonymous
     * identity, and these lists belong to nobody in particular — which is the
     * realistic case for the rail, since most lists on this site are made
     * without an account.
     */
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

    private function brand(string $name): BrandStat
    {
        // firstOrCreate: `(market, slug)` is unique, and a test that asks for
        // the same brand twice is asking about the same brand.
        return BrandStat::firstOrCreate(
            ['market' => Market::BeNl->value, 'slug' => strtolower($name)],
            ['brand' => $name, 'product_count' => 3],
        );
    }

    /** @return list<Wishlist> */
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

    private function product(
        string $title,
        int $price,
        string $brand,
        ?int $discount = null,
        ?Merchant $merchant = null,
    ): ProductGroup {
        $group = ProductGroup::create([
            'market' => Market::BeNl,
            'identity_key' => 'k'.bin2hex(random_bytes(5)),
            'identity_kind' => 'ean',
            'title' => $title,
            'slug' => 'p-'.bin2hex(random_bytes(3)),
            'brand' => $brand,
            'category' => 'audio',
            'image_url' => 'https://img.test/x.jpg',
            'min_price' => $price,
            /*
             * A discount is measured against the 30-day median rather than a
             * merchant's "was" price, so that is what a fixture sets. A null
             * median is a product with no price history and therefore no
             * discount to announce.
             */
            'median_price' => $discount === null ? null : (int) round($price / (1 - $discount / 100)),
            'merchant_count' => 1,
            'in_stock' => true,
            'giftable' => true,
            'surprise_score' => 60,
        ]);

        Product::create([
            'source' => Source::Awin,
            'market' => Market::BeNl,
            'merchant_id' => ($merchant ?? $this->merchant)->id,
            'group_id' => $group->id,
            'external_id' => 'e'.bin2hex(random_bytes(5)),
            'title' => $title,
            'brand' => $brand,
            'price' => $price,
            'currency' => 'EUR',
            'affiliate_url' => 'https://example.test/buy',
            'availability' => Availability::InStock,
            'status' => ProductStatus::Active,
            'identity_key' => $group->identity_key,
        ]);

        return $group;
    }
}
