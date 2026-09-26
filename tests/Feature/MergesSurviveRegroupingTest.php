<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\Availability;
use App\Enums\Market;
use App\Enums\ProductStatus;
use App\Enums\Source;
use App\Models\MatchCandidate;
use App\Models\Merchant;
use App\Models\Product;
use App\Models\ProductGroup;
use App\Services\Connectors\Offer;
use App\Services\Identity\GroupMerger;
use App\Services\Identity\GroupSplitter;
use App\Services\Ingestion\IncomingGrouper;
use App\Services\Ingestion\ProductGrouper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * A merge or a split made by a person outlives the grouper.
 *
 * The grouper re-derives every offer's product from its identity key twice a
 * day, and live search does the same per request. Before aliases and
 * overrides, a hand-made merge that only moved `group_id` was undone within
 * twelve hours. These pin that it no longer is, from both directions.
 */
class MergesSurviveRegroupingTest extends TestCase
{
    use RefreshDatabase;

    private Merchant $merchant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->merchant = Merchant::create(['source' => Source::Awin->value, 'external_id' => 'shop', 'name' => 'Shop']);
    }

    /** An offer as the upserter leaves it: keyed, not yet grouped. */
    private function offer(string $externalId, string $key, int $price): Product
    {
        return Product::create([
            'source' => Source::Awin,
            'market' => Market::BeNl,
            'merchant_id' => $this->merchant->id,
            'external_id' => $externalId,
            'identity_kind' => 'title',
            'identity_key' => $key,
            'title' => "Offer {$externalId}",
            'brand' => 'LEGO',
            'price' => $price,
            'currency' => 'EUR',
            'affiliate_url' => 'https://example.test/buy',
            'availability' => Availability::InStock,
            'status' => ProductStatus::Active,
        ]);
    }

    private function regroup(): void
    {
        app(ProductGrouper::class)->run(Market::BeNl);
    }

    private function groupOf(Product $offer): ProductGroup
    {
        return ProductGroup::query()->findOrFail($offer->fresh()->group_id);
    }

    #[Test]
    public function a_merge_survives_the_next_grouping_run(): void
    {
        $a = $this->offer('a', 'lego|technic ferrari 488', 5000);
        $b = $this->offer('b', 'lego|ferrari 488 42125', 4500);
        $this->regroup();

        $winner = $this->groupOf($a);
        $loser = $this->groupOf($b);
        $this->assertNotSame($winner->id, $loser->id);

        app(GroupMerger::class)->merge($loser, $winner);

        $this->regroup();
        $this->regroup();

        $this->assertSame($winner->id, $b->fresh()->group_id);
        $this->assertSame(2, $winner->fresh()->offer_count);
        $this->assertSame(0, $loser->fresh()->offer_count);
        // No new product was made for the merged key either.
        $this->assertSame(2, ProductGroup::query()->count());
    }

    #[Test]
    public function a_merge_survives_an_offer_arriving_from_live_search(): void
    {
        $a = $this->offer('a', 'lego|technic ferrari 488', 5000);
        $b = $this->offer('b', 'lego|ferrari 488 42125', 4500);
        $this->regroup();
        $winner = $this->groupOf($a);

        app(GroupMerger::class)->merge($this->groupOf($b), $winner);

        // A new shop's offer with the merged key, attached the way a live
        // search or a page import attaches it.
        $c = $this->offer('c', 'lego|ferrari 488 42125', 4700);
        app(IncomingGrouper::class)->attach(Market::BeNl, [new Offer(
            source: Source::Awin, externalId: 'c', market: Market::BeNl, title: 'x', affiliateUrl: 'https://example.test/buy',
        )]);

        $this->assertSame($winner->id, $c->fresh()->group_id);
        $this->assertSame(3, $winner->fresh()->offer_count);
    }

    #[Test]
    public function a_split_survives_the_next_grouping_run(): void
    {
        $a = $this->offer('a', 'lego|ferrari 488', 5000);
        $b = $this->offer('b', 'lego|ferrari 488', 9000);
        $this->regroup();
        $original = $this->groupOf($a);

        $new = app(GroupSplitter::class)->split($original, [$b->id]);

        $this->regroup();

        $this->assertSame($new->id, $b->fresh()->group_id);
        $this->assertSame($original->id, $a->fresh()->group_id);
        $this->assertSame(1, $original->fresh()->offer_count);
        $this->assertSame(1, $new->fresh()->offer_count);
        // The split key is not a barcode and must never be printed as one.
        $this->assertSame('title', $new->identity_kind->value);

        // And the match rules will not propose putting them back together.
        $this->assertTrue(MatchCandidate::query()
            ->where('group_a', min($original->id, $new->id))
            ->where('group_b', max($original->id, $new->id))
            ->where('status', 'rejected')
            ->exists());
    }

    #[Test]
    public function a_split_survives_an_alias_on_its_old_key(): void
    {
        $a = $this->offer('a', 'lego|ferrari 488', 5000);
        $b = $this->offer('b', 'lego|ferrari 488', 9000);
        $c = $this->offer('c', 'lego|ferrari 488 gte', 5100);
        $this->regroup();
        $original = $this->groupOf($a);
        $elsewhere = $this->groupOf($c);

        $split = app(GroupSplitter::class)->split($original, [$b->id]);

        // Now the product the offer was split from is merged away. Its key
        // gets an alias, and the split offer must not follow it.
        app(GroupMerger::class)->merge($original->fresh(), $elsewhere);
        $this->regroup();

        $this->assertSame($elsewhere->id, $a->fresh()->group_id);
        $this->assertSame($split->id, $b->fresh()->group_id);
    }

    #[Test]
    public function a_product_made_by_a_split_can_itself_be_merged_later(): void
    {
        $a = $this->offer('a', 'lego|ferrari 488', 5000);
        $b = $this->offer('b', 'lego|ferrari 488', 9000);
        $c = $this->offer('c', 'lego|ferrari 488 gte', 5100);
        $this->regroup();

        $split = app(GroupSplitter::class)->split($this->groupOf($a), [$b->id]);
        $target = $this->groupOf($c);

        app(GroupMerger::class)->merge($split, $target);
        $this->regroup();

        // The override's key is aliased onward, and the offer follows.
        $this->assertSame($target->id, $b->fresh()->group_id);
    }
}
