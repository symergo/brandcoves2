<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\Market;
use App\Models\Feed;
use App\Models\Merchant;
use App\Models\WishlistItem;
use App\Services\Affiliate\MerchantLinks;
use App\Services\Guides\CoveMarkup;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * A link typed into an article, to a shop we are registered with, leaves the
 * site as an affiliate link (owner, 2026-11-07).
 */
class MerchantLinksTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'giftcoves.connectors.bol.partner_site_id' => ['BE' => '111', 'NL' => '222'],
            'giftcoves.connectors.awin.accounts' => ['default' => ['label' => 'GiftCoves', 'api_token' => 'x', 'publisher_id' => '999']],
            'giftcoves.connectors.awin.publisher_id' => '999',
        ]);
        Cache::flush();
    }

    #[Test]
    public function a_bol_link_goes_through_bols_redirector_for_the_country_in_its_path(): void
    {
        $be = MerchantLinks::wrap('https://www.bol.com/be/fr/p/orbito/9300000154303794/', Market::BeFr);
        parse_str((string) parse_url($be, PHP_URL_QUERY), $query);

        $this->assertStringStartsWith('https://partner.bol.com/click/click?', $be);
        $this->assertSame('111', $query['s']);
        $this->assertSame('https://www.bol.com/be/fr/p/orbito/9300000154303794/', $query['url']);

        // A Dutch bol page from a Belgian article is credited to the NL site.
        parse_str((string) parse_url(MerchantLinks::wrap('https://www.bol.com/nl/nl/p/x/1/', Market::BeNl), PHP_URL_QUERY), $nl);
        $this->assertSame('222', $nl['s']);
    }

    #[Test]
    public function an_awin_shop_gets_the_networks_deep_link_with_its_own_accounts_publisher(): void
    {
        $coolblue = Merchant::query()->create(['source' => 'awin', 'external_id' => '85165', 'name' => 'Coolblue BE', 'domain' => 'coolblue.be']);
        $vdb = Merchant::query()->create(['source' => 'awin', 'external_id' => '12677', 'name' => 'Vanden Borre BE', 'domain' => 'vandenborre.be']);
        Merchant::query()->create(['source' => 'awin', 'external_id' => '12648', 'name' => 'FNAC BE', 'domain' => 'nl.fnac.be']);
        Feed::query()->create(['source' => 'awin', 'external_feed_id' => '1', 'market' => 'be-nl', 'label' => 'Coolblue BE', 'account' => 'default', 'merchant_id' => $coolblue->id]);
        Feed::query()->create(['source' => 'awin', 'external_feed_id' => '2', 'market' => 'be-nl', 'label' => 'Vanden Borre BE', 'account' => 'vandenborre', 'merchant_id' => $vdb->id]);

        $link = MerchantLinks::wrap('https://www.coolblue.be/nl/cadeaubonnen', Market::BeNl);
        parse_str((string) parse_url($link, PHP_URL_QUERY), $query);

        $this->assertStringStartsWith('https://www.awin1.com/cread.php?', $link);
        $this->assertSame(['awinmid' => '85165', 'awinaffid' => '999', 'ued' => 'https://www.coolblue.be/nl/cadeaubonnen'], $query);

        // fr.fnac.be is the same shop as the registered nl.fnac.be.
        $this->assertStringContainsString('awinmid=12648', MerchantLinks::wrap('https://fr.fnac.be/a1', Market::BeFr));

        // Vanden Borre's account has no publisher id here: never borrow the default one.
        $this->assertSame('https://www.vandenborre.be/info/x', MerchantLinks::wrap('https://www.vandenborre.be/info/x', Market::BeNl));
    }

    #[Test]
    public function a_saved_shop_item_links_out_tracked_but_keeps_the_shops_address_for_editing(): void
    {
        $item = new WishlistItem(['source' => 'manual', 'snapshot_url' => 'https://www.bol.com/be/nl/p/x/1/']);

        $this->assertSame('https://www.bol.com/be/nl/p/x/1/', $item->externalUrl());
        $this->assertStringStartsWith('https://partner.bol.com/click/click?', (string) $item->trackedExternalUrl());
    }

    #[Test]
    public function an_unknown_shop_and_an_already_tracked_link_are_left_alone(): void
    {
        $this->assertSame('https://www.bongo.be/nl/bon', MerchantLinks::wrap('https://www.bongo.be/nl/bon', Market::BeNl));

        $tracked = 'https://partner.bol.com/click/click?p=2&s=1&url=x';
        $this->assertSame($tracked, MerchantLinks::wrap($tracked, Market::BeNl));
    }

    #[Test]
    public function an_article_shop_link_is_tracked_and_marked_sponsored_while_the_saved_address_stays_the_shops(): void
    {
        $markup = app(CoveMarkup::class);
        $allowed = ['brands' => [], 'searches' => [], 'products' => [], 'guides' => []];

        $html = $markup->render('Te koop bij [[shop:https://www.bol.com/be/nl/p/x/1/|bol]].', Market::BeNl, $allowed)['html'];

        $this->assertStringContainsString('href="https://partner.bol.com/click/click?', $html);
        $this->assertStringContainsString('rel="sponsored nofollow noopener"', $html);

        // A picture's buy address is what a list saves: the shop's own.
        $image = $markup->image('[[image:https://media.s-bol.com/a.jpg|Orbito|https://www.bol.com/be/nl/p/x/1/]]', Market::BeNl);
        $this->assertSame('https://www.bol.com/be/nl/p/x/1/', $image['buy']);
    }
}
