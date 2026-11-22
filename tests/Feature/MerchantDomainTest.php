<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\Market;
use App\Enums\Source;
use App\Models\Merchant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * eBay is one merchant row for every market; its address follows the market's marketplace
 * (2026-11-22: Spain's shop page said ebay.nl). Other shops keep their stored domain.
 */
class MerchantDomainTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function ebay_shows_the_marketplace_of_the_market_and_other_shops_their_own_domain(): void
    {
        config(['giftcoves.connectors.ebay.marketplace' => ['es' => 'EBAY_ES', 'be-fr' => 'EBAY_FR', 'en' => 'EBAY_NL', 'be-nl' => '']]);

        $ebay = Merchant::query()->create(['source' => Source::Ebay->value, 'external_id' => 'ebay', 'name' => 'eBay', 'domain' => 'ebay.nl']);
        $shop = Merchant::query()->create(['source' => Source::Awin->value, 'external_id' => '96637', 'name' => 'Coolblue BE', 'domain' => 'coolblue.be']);

        $this->assertSame('ebay.es', $ebay->domainFor(Market::Es));
        $this->assertSame('ebay.fr', $ebay->domainFor(Market::BeFr));
        $this->assertSame('ebay.nl', $ebay->domainFor(Market::En));
        // No marketplace for the market: the stored domain
        $this->assertSame('ebay.nl', $ebay->domainFor(Market::BeNl));
        $this->assertSame('coolblue.be', $shop->domainFor(Market::Es));
    }
}
