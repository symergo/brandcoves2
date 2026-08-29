<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\CoveKind;
use App\Enums\Market;
use App\Enums\PublishStatus;
use App\Models\DailyPickSet;
use App\Services\Seo\Alternates;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * A Shop Cove: an article about a shop, read at `/shops/{slug}`.
 *
 * The sixth Cove kind, and the first prose kind that does **not** live in the
 * `/guides` URL space. That split is the whole risk here: `CoveKind::isArticle()`
 * answers a question about URLs and `expectsShortlist()` answers one about page
 * shape, they used to be the same question, and getting them confused shows up
 * as a Shop Cove quietly appearing in the guides index, the guides sitemap or
 * the guides hreflang set — none of which errors.
 */
class ShopCoveArticleTest extends TestCase
{
    use RefreshDatabase;

    private function cove(
        string $slug,
        string $title,
        Market $market = Market::BeNl,
        CoveKind $kind = CoveKind::Shop,
        PublishStatus $status = PublishStatus::Published,
    ): DailyPickSet {
        return DailyPickSet::create([
            'market' => $market->value,
            'kind' => $kind->value,
            'slug' => $slug,
            'theme_title' => $title,
            'theme_slug' => $slug,
            'theme_blurb' => 'Waar het over gaat.',
            'body' => "Eerste alinea.\n\nTweede alinea.",
            'status' => $status->value,
            'published_at' => '2026-08-20',
        ]);
    }

    #[Test]
    public function it_renders_at_its_own_path(): void
    {
        $this->cove('bol-com', 'Kopen bij bol');

        $this->get('/be-nl/shops/bol-com')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Guides/Show')
                ->where('guide.title', 'Kopen bij bol')
                // Prose, so the page renders no shortlist. An empty <ol> reads
                // as a broken buying guide rather than as a finished piece.
                ->where('guide.kind', 'advice')
                ->has('items', 0)
            );
    }

    #[Test]
    public function it_publishes_with_no_products(): void
    {
        // Its substance is the writing. `minimumItems()` is zero for exactly
        // this reason, as it is for an advice article.
        $this->assertSame(0, CoveKind::Shop->minimumItems());
        $this->assertFalse(CoveKind::Shop->expectsShortlist());

        $this->cove('krefel-be', 'Kopen bij Krëfel');

        $this->get('/be-nl/shops/krefel-be')->assertOk();
    }

    #[Test]
    public function it_does_not_leak_into_the_guides_space(): void
    {
        /*
         * The failure this whole test class exists for. `/guides` filters on
         * `articles()`, which asks `isArticle()` — a question about URL space,
         * not about page shape — so a Shop Cove answering true there would show
         * up in the archive of buying guides and be reachable at two addresses.
         */
        $this->cove('bol-com', 'Kopen bij bol');

        $this->assertFalse(CoveKind::Shop->isArticle());

        $this->get('/be-nl/guides/bol-com')->assertNotFound();

        $this->get('/be-nl/guides')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->has('guides', 0));
    }

    #[Test]
    public function a_guide_does_not_appear_under_shops_either(): void
    {
        // The other direction. The two spaces are separate in both.
        $this->cove('koptelefoons', 'De beste koptelefoons', kind: CoveKind::Guide);

        $this->get('/be-nl/shops/koptelefoons')->assertNotFound();
    }

    #[Test]
    public function a_draft_is_not_public(): void
    {
        $this->cove('bol-com', 'Nog niet klaar', status: PublishStatus::Draft);

        $this->get('/be-nl/shops/bol-com')->assertNotFound();
    }

    #[Test]
    public function it_leads_the_shops_page_in_its_own_market_only(): void
    {
        $this->cove('bol-com', 'Kopen bij bol', market: Market::BeNl);
        $this->cove('bol-com', 'Acheter chez bol', market: Market::BeFr);

        $this->get('/be-fr/shops')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('coves', 1)
                ->where('coves.0.title', 'Acheter chez bol')
                ->where('coves.0.url', '/be-fr/shops/bol-com')
            );
    }

    #[Test]
    public function the_overview_prefers_the_writing_over_the_directory(): void
    {
        /*
         * `/coves` shows a band of shops when nothing has been written and the
         * Coves themselves once something has. An overview of *all Coves* that
         * lists company names while real articles exist is showing the weaker
         * of the two things it has.
         */
        $this->cove('bol-com', 'Kopen bij bol');

        $this->get('/be-nl/coves')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('sections.0.key', 'shop')
                ->where('sections.0.url', '/be-nl/shops')
                ->where('sections.0.coves.0.title', 'Kopen bij bol')
                ->where('sections.0.coves.0.url', '/be-nl/shops/bol-com')
            );
    }

    #[Test]
    public function the_same_shop_in_two_markets_is_paired_for_hreflang(): void
    {
        /*
         * Slugs come from the shop's domain, so the same shop keeps the same
         * slug everywhere it trades — which is what makes these pairable at
         * all. Paired by a method of its own rather than by widening the guide
         * one: a `/guides/{slug}` sharing the slug is a different page.
         */
        $this->cove('bol-com', 'Kopen bij bol', market: Market::BeNl);
        $this->cove('bol-com', 'Acheter chez bol', market: Market::BeFr);

        $alternates = app(Alternates::class)
            ->for('/be-nl/shops/bol-com', Market::BeNl);

        $this->assertSame([
            'nl-BE' => url('/be-nl/shops/bol-com'),
            'fr-BE' => url('/be-fr/shops/bol-com'),
        ], $alternates);
    }
}
