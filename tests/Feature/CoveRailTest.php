<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\Availability;
use App\Enums\CoveKind;
use App\Enums\Market;
use App\Enums\ProductStatus;
use App\Enums\PublishStatus;
use App\Enums\Source;
use App\Models\DailyPick;
use App\Models\DailyPickSet;
use App\Models\Merchant;
use App\Models\Product;
use App\Models\ProductGroup;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Where a Cove sends you next.
 *
 * Two surfaces, filled by one service and asserted here together because they
 * are one decision: the cards under the article offer more Coves of the same
 * kind, and the rail beside it offers more products from the categories the
 * Cove's own picks are in.
 *
 * The assertions are on the Inertia props rather than on the rendered HTML, and
 * deliberately so. A whole-page string search cannot tell a title in the cards
 * from the same title in the article above them — the mistake `GiftPersonaTest`
 * documents having already made once with `assertDontSee`.
 */
class CoveRailTest extends TestCase
{
    use RefreshDatabase;

    private Merchant $merchant;

    protected function setUp(): void
    {
        parent::setUp();

        // An edition is built at 06:00 to publish at 09:00, and `published()`
        // hides it until then. Without a fixed clock this file passes in the
        // afternoon and 404s in the morning.
        $this->travelTo(CarbonImmutable::today()->setTime(12, 0));

        $this->merchant = Merchant::create([
            'source' => Source::Awin->value,
            'external_id' => 'shop',
            'name' => 'Shop',
        ]);
    }

    // ── The cards under the article ───────────────────────────────────────

    #[Test]
    public function an_edition_offers_the_other_editions_and_not_the_personas(): void
    {
        $today = $this->cove(CoveKind::Daily, 'Vandaag', drop: CarbonImmutable::today());
        $this->cove(CoveKind::Daily, 'Gisteren', drop: CarbonImmutable::yesterday());
        $this->cove(CoveKind::Persona, 'De kruidenliefhebber');
        $this->cove(CoveKind::Guide, 'Beste koptelefoons');

        $this->get('/be-nl/tips/'.$today->slug)
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('rail.coves.key', 'daily')
                ->where('rail.coves.coves.0.title', 'Gisteren')
                // One kind only. A reader who has just finished an edition has
                // said what shape of page they want, and /coves is where the
                // whole shelf is on show.
                ->where('rail.coves.coves', fn ($coves) => count($coves) === 1));
    }

    #[Test]
    public function a_cove_is_never_offered_as_the_next_thing_to_read(): void
    {
        // The page you are on is not somewhere to go next, and a card for it
        // under its own article is the kind of bug a reader can see.
        $persona = $this->cove(CoveKind::Persona, 'De kruidenliefhebber');
        $this->cove(CoveKind::Persona, 'De thuiswerker');

        $this->get('/be-nl/gift-ideas/'.$persona->slug)
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('rail.coves.coves.0.title', 'De thuiswerker')
                ->where('rail.coves.coves', fn ($coves) => count($coves) === 1));
    }

    #[Test]
    public function a_draft_sibling_is_never_offered(): void
    {
        $guide = $this->cove(CoveKind::Guide, 'Beste koptelefoons');
        $this->cove(CoveKind::Guide, 'Nog niet af', status: PublishStatus::Draft);

        // Not even to an admin previewing a draft: the rail is the published
        // site's furniture, and a preview that leaks a sibling draft leaks it
        // to whoever the preview link is forwarded to.
        $this->get('/be-nl/guides/'.$guide->slug)
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('rail.coves', null));
    }

    #[Test]
    public function the_three_article_kinds_are_one_shelf(): void
    {
        /*
         * A buying guide, a seasonal guide and an advice article share a URL
         * space, an index and a name in the header, so they are one section to
         * a reader. Splitting them would leave an advice article offering only
         * the other advice articles — on a market with one of those, nothing.
         */
        $advice = $this->cove(CoveKind::Advice, 'Hoe herken je een betaalde review');
        $this->cove(CoveKind::Guide, 'Beste koptelefoons');
        $this->cove(CoveKind::Seasonal, 'Cadeaus voor Sinterklaas');

        $this->get('/be-nl/guides/'.$advice->slug)
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('rail.coves.key', 'smart')
                ->where('rail.coves.coves', fn ($coves) => count($coves) === 2));
    }

    // ── The products in the rail ──────────────────────────────────────────

    #[Test]
    public function the_rail_offers_more_products_from_the_coves_own_categories(): void
    {
        $picked = $this->product('Koptelefoon Sony', 'Koptelefoons');
        $neighbour = $this->product('Koptelefoon Sennheiser', 'Koptelefoons');
        $this->product('Broodrooster', 'Keuken');

        $persona = $this->cove(CoveKind::Persona, 'De muziekliefhebber', picks: [$picked]);

        $this->get('/be-nl/gift-ideas/'.$persona->slug)
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('rail.products.0.category', 'Koptelefoons')
                // The category the Cove is not about stays out of it: this is
                // "more like these", not "more of anything".
                ->where('rail.products', fn ($bands) => count($bands) === 1)
                ->where('rail.products.0.products.0.id', $neighbour->id));
    }

    #[Test]
    public function a_product_already_on_the_page_is_not_offered_again(): void
    {
        $picked = $this->product('Koptelefoon Sony', 'Koptelefoons');
        $persona = $this->cove(CoveKind::Persona, 'De muziekliefhebber', picks: [$picked]);

        // The only product in its category is the one the reader just read
        // about, so the block has nothing to say and does not appear. "More
        // like this" that opens with what you just read is worse than silence.
        $this->get('/be-nl/gift-ideas/'.$persona->slug)
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('rail.products', []));
    }

    #[Test]
    public function an_article_with_no_products_gets_no_product_block(): void
    {
        // The normal state of an advice article, which is the one kind that may
        // publish with no shortlist at all. There is no category to read off
        // it, and an empty "more like this" reads as a failed load.
        $advice = $this->cove(CoveKind::Advice, 'Hoe herken je een betaalde review');

        $this->get('/be-nl/guides/'.$advice->slug)
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('rail.products', []));
    }

    #[Test]
    public function an_out_of_stock_neighbour_is_not_offered(): void
    {
        $picked = $this->product('Koptelefoon Sony', 'Koptelefoons');
        $gone = $this->product('Koptelefoon Sennheiser', 'Koptelefoons');
        $gone->update(['in_stock' => false]);

        $persona = $this->cove(CoveKind::Persona, 'De muziekliefhebber', picks: [$picked]);

        // `presentable()`: in stock, priced, and with an image. A rail that
        // sends a reader to something they cannot buy is worse than a short
        // rail.
        $this->get('/be-nl/gift-ideas/'.$persona->slug)
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('rail.products', []));
    }

    // ── Fixtures ──────────────────────────────────────────────────────────

    private function product(string $title, ?string $category): ProductGroup
    {
        $group = ProductGroup::create([
            'market' => Market::BeNl,
            'identity_key' => 'k'.bin2hex(random_bytes(5)),
            'identity_kind' => 'ean',
            'title' => $title,
            'slug' => 'p-'.bin2hex(random_bytes(3)),
            'category' => $category,
            'image_url' => 'https://img.test/x.jpg',
            'min_price' => 4900,
            'merchant_count' => 2,
            'in_stock' => true,
            'giftable' => true,
            'worth_showing' => true,
        ]);

        Product::create([
            'source' => Source::Awin,
            'market' => Market::BeNl,
            'merchant_id' => $this->merchant->id,
            'group_id' => $group->id,
            'external_id' => 'e'.bin2hex(random_bytes(5)),
            'title' => $title,
            'price' => 4900,
            'currency' => 'EUR',
            'affiliate_url' => 'https://example.test/buy',
            'availability' => Availability::InStock,
            'status' => ProductStatus::Active,
            'identity_key' => $group->identity_key,
        ]);

        return $group;
    }

    /**
     * A published Cove of any kind, written straight to the table.
     *
     * Not through `EditionBuilder`: what is under test is what a page offers
     * *once several Coves exist*, and the builder would decide for itself which
     * products go where and refuse a kind whose shortlist is too thin.
     *
     * @param  list<ProductGroup>  $picks
     */
    private function cove(
        CoveKind $kind,
        string $title,
        ?CarbonImmutable $drop = null,
        PublishStatus $status = PublishStatus::Published,
        array $picks = [],
    ): DailyPickSet {
        $set = DailyPickSet::create([
            'market' => Market::BeNl,
            'kind' => $kind->value,
            'drop_date' => $kind->isDated() ? ($drop ?? CarbonImmutable::today()) : null,
            'theme_title' => $title,
            'theme_blurb' => 'Waar dit over gaat.',
            'theme_slug' => 'theme-'.bin2hex(random_bytes(3)),
            'status' => $status->value,
            'published_at' => $status === PublishStatus::Published ? now()->subHour() : null,
        ]);

        foreach ($picks as $rank => $group) {
            DailyPick::create([
                'set_id' => $set->id,
                'group_id' => $group->id,
                'rank' => $rank + 1,
                'slug' => $group->slug,
            ]);
        }

        return $set;
    }
}
