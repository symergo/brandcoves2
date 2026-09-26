<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\Market;
use App\Models\ProductGroup;
use App\Services\Editorial\ProseCards;
use App\Services\Guides\CoveMarkup;
use App\Services\Identity\GroupMerger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Links to a product that was merged keep working.
 *
 * The merged product is kept rather than deleted for exactly this: its URL
 * has been shared and indexed, and published Coves name it by id.
 */
class MergedProductLinksTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0: ProductGroup, 1: ProductGroup} loser, winner */
    private function merged(): array
    {
        $winner = ProductGroup::factory()->create(['identity_key' => 'lego|winner', 'identity_kind' => 'title', 'slug' => 'lego-ferrari']);
        $loser = ProductGroup::factory()->create(['identity_key' => 'lego|loser', 'identity_kind' => 'title', 'slug' => 'lego-42125']);

        app(GroupMerger::class)->merge($loser, $winner);

        return [$loser->fresh(), $winner->fresh()];
    }

    #[Test]
    public function the_old_product_page_answers_301_to_the_one_it_became(): void
    {
        [$loser, $winner] = $this->merged();

        $this->get("/be-nl/p/{$loser->id}/{$loser->slug}")
            ->assertStatus(301)
            ->assertRedirect("/be-nl/p/{$winner->id}/{$winner->slug}");

        // A stale slug on the old id goes straight to the winner too.
        $this->get("/be-nl/p/{$loser->id}")->assertStatus(301)->assertRedirect("/be-nl/p/{$winner->id}/{$winner->slug}");
    }

    #[Test]
    public function a_cove_naming_the_old_id_still_links_to_the_product(): void
    {
        [$loser, $winner] = $this->merged();

        // The Cove's item list moved to the winner with the merge; the prose
        // still says the loser's id.
        $allowed = ['products' => [$winner->id => ['slug' => $winner->slug, 'title' => $winner->title]]];

        $result = app(CoveMarkup::class)->render("Neem [[product:{$loser->id}|de Ferrari]] mee.", Market::BeNl, $allowed);

        $this->assertStringContainsString("<a href=\"/be-nl/p/{$winner->id}/{$winner->slug}\">de Ferrari</a>", $result['html']);
        $this->assertSame([], $result['rejected']);

        // A token without a label takes the product's title, not a number.
        $this->assertSame("Neem {$winner->title} mee.", app(CoveMarkup::class)->plain("Neem [[product:{$loser->id}]] mee.", $allowed));

        // And the card still pairs with the paragraph that names it.
        $blocks = (new ProseCards(app(CoveMarkup::class), Market::BeNl, $allowed))->blocks("Over [[product:{$loser->id}|de Ferrari]].");
        $this->assertSame([$winner->id], $blocks[0]['groupIds']);
    }

    #[Test]
    public function an_unknown_id_is_still_plain_text(): void
    {
        [, $winner] = $this->merged();
        $allowed = ['products' => [$winner->id => ['slug' => $winner->slug, 'title' => $winner->title]]];

        $result = app(CoveMarkup::class)->render('Neem [[product:999999|iets]] mee.', Market::BeNl, $allowed);

        $this->assertSame('Neem iets mee.', $result['html']);
    }
}
