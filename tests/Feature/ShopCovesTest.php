<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\Market;
use App\Enums\ProductStatus;
use App\Enums\Source;
use App\Models\Merchant;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * `/shops` — the shops this market's prices are compared across.
 *
 * **Membership is the thing to test.** It was written against `feeds` first and
 * the page listed bol and nothing else, because `feeds.merchant_id` is null on
 * every row in the database — nothing in ingestion sets it. Nothing threw and
 * the page rendered; it was simply almost empty. So these pin the question the
 * page actually asks: does this shop have active offers in this market.
 */
class ShopCovesTest extends TestCase
{
    use RefreshDatabase;

    private function shop(
        string $name,
        ?Market $market,
        ProductStatus $status = ProductStatus::Active,
        bool $enabled = true,
    ): Merchant {
        $merchant = Merchant::create([
            'source' => Source::Awin->value,
            'external_id' => 'aw-'.str($name)->slug(),
            'name' => $name,
            'domain' => str($name)->slug().'.be',
            'enabled' => $enabled,
        ]);

        if ($market !== null) {
            Product::create([
                'source' => Source::Awin->value,
                'external_id' => 'p-'.str($name)->slug(),
                'market' => $market->value,
                'merchant_id' => $merchant->id,
                'title' => $name.' product',
                'price' => 1999,
                'affiliate_url' => 'https://example.test/p',
                'status' => $status->value,
            ]);
        }

        return $merchant;
    }

    #[Test]
    public function it_lists_the_shops_wired_up_for_this_market(): void
    {
        $this->shop('Coolblue', Market::BeNl);
        $this->shop('Krefel', Market::BeNl);

        $this->get('/be-nl/shops')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Shops/Index')
                ->has('shops', 2)
                // Alphabetical: the directory is scrolled and scanned for a
                // name, not read top to bottom.
                ->where('shops.0.name', 'Coolblue')
                ->where('shops.1.name', 'Krefel')
            );
    }

    #[Test]
    public function another_markets_shop_is_not_listed(): void
    {
        $this->shop('Fnac', Market::BeFr);

        $this->get('/be-nl/shops')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->has('shops', 0));
    }

    #[Test]
    public function a_shop_whose_offers_have_all_gone_stale_is_not_listed(): void
    {
        /*
         * A stale row is kept because a wishlist item or a published Cove
         * still points at it — it is deliberately not deleted. That is not the
         * same as still selling here, and a directory of shops we compare must
         * not be padded with shops we no longer carry.
         */
        $this->shop('Vertrokken', Market::BeNl, status: ProductStatus::Stale);

        $this->get('/be-nl/shops')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->has('shops', 0));
    }

    #[Test]
    public function a_disabled_merchant_is_not_listed(): void
    {
        $this->shop('Geblokkeerd', Market::BeNl, enabled: false);

        $this->get('/be-nl/shops')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->has('shops', 0));
    }

    #[Test]
    public function a_recent_arrival_is_featured_and_still_appears_in_the_directory(): void
    {
        $old = $this->shop('Oud', Market::BeNl);
        $old->forceFill(['created_at' => now()->subMonths(6)])->save();

        $this->shop('Nieuw', Market::BeNl);

        $this->get('/be-nl/shops')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('newShops', 1)
                ->where('newShops.0.name', 'Nieuw')
                // Repeated rather than moved. A spotlight that removes the shop
                // from the alphabet is a shop somebody scrolling cannot find.
                ->has('shops', 2)
                ->where('shops.0.isNew', true)
                ->where('shops.1.isNew', false)
            );
    }

    #[Test]
    public function nothing_is_featured_when_every_shop_is_new(): void
    {
        /*
         * Not hypothetical: a market onboarded in one sitting has its whole
         * directory inside the window, and the spotlight then reprints the page
         * below it under a heading promising something has changed. Measured on
         * the development database, where all six shops were inside 30 days.
         */
        $this->shop('Coolblue', Market::BeNl);
        $this->shop('Krefel', Market::BeNl);

        $this->get('/be-nl/shops')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('shops', 2)
                ->has('newShops', 0)
                // The badge is still true of each of them; only the band is
                // suppressed, because it is the band that implies a contrast.
                ->where('shops.0.isNew', true)
            );
    }

    #[Test]
    public function a_shop_links_into_search_filtered_to_itself(): void
    {
        $shop = $this->shop('Coolblue', Market::BeNl);

        $this->get('/be-nl/shops')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('shops.0.url', "/be-nl/search?merchant%5B%5D={$shop->id}")
            );
    }
}
