<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Enums\Market;
use App\Services\Search\AmazonSearchLink;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class AmazonSearchLinkTest extends TestCase
{
    #[Test]
    public function it_sends_the_netherlands_to_amazon_nl(): void
    {
        $link = AmazonSearchLink::for(Market::NlNl, 'koptelefoon');

        $this->assertNotNull($link);
        $this->assertSame('www.amazon.nl', $link->host);
        $this->assertSame('https://www.amazon.nl/s?k=koptelefoon&tag=giftcoves-21', $link->url);
        $this->assertTrue($link->hasTerm);
    }

    /**
     * Belgium goes to `.com.be` under the other tag, in both languages.
     *
     * This is the pair the whole config exists to keep straight: the tags are
     * issued per marketplace, and swapping them produces a working page that
     * earns nothing.
     */
    #[Test]
    public function it_sends_both_belgian_markets_to_amazon_com_be(): void
    {
        foreach ([Market::BeNl, Market::BeFr] as $market) {
            $link = AmazonSearchLink::for($market, 'casque');

            $this->assertNotNull($link, $market->value);
            $this->assertSame('www.amazon.com.be', $link->host);
            $this->assertStringContainsString('tag=giftcoves05-21', $link->url);
        }
    }

    /**
     * A market with no storefront gets no link. en has one since 2026-11-25: amazon.de in
     * English, untagged until the owner adds a tag ("Use without tag. I'll add them later").
     */
    #[Test]
    public function it_offers_nothing_where_there_is_no_storefront(): void
    {
        $this->assertNull(AmazonSearchLink::for(Market::Es, 'auriculares'));

        $en = AmazonSearchLink::for(Market::En, 'headphones');
        $this->assertSame('www.amazon.de', $en?->host);
        $this->assertSame('https://www.amazon.de/s?k=headphones&language=en_GB', $en->url);
    }

    /** The mark beside the label is the storefront's own favicon, never ours. */
    #[Test]
    public function it_points_at_the_storefronts_own_favicon(): void
    {
        $link = AmazonSearchLink::for(Market::BeNl, 'koptelefoon');

        $this->assertNotNull($link);
        $this->assertSame('https://www.amazon.com.be/favicon.ico', $link->iconUrl());
        $this->assertSame($link->iconUrl(), $link->toArray()['icon']);
    }

    /**
     * No term is not the same as no link. The search page before anything is
     * typed, and a page that found nothing, are both moments where the shopper
     * wanted the *link* — so they get the storefront itself, still tagged.
     */
    #[Test]
    public function an_empty_term_lands_on_the_tagged_storefront(): void
    {
        $link = AmazonSearchLink::for(Market::NlNl, '   ');

        $this->assertNotNull($link);
        $this->assertFalse($link->hasTerm);

        // The front page, not `/s` with no `k` — that is a results page for
        // nothing, which Amazon answers with an empty grid.
        $this->assertSame('https://www.amazon.nl/?tag=giftcoves-21', $link->url);
    }

    /**
     * A term with an ampersand in it must not truncate the query string — that
     * would drop the tag, which is the one part of this URL nobody would
     * notice was missing.
     */
    #[Test]
    public function it_encodes_a_term_that_would_otherwise_break_the_url(): void
    {
        $link = AmazonSearchLink::for(Market::NlNl, 'Procter & Gamble héél');

        $this->assertNotNull($link);

        // The literal `&` is gone from the URL — left raw it would end `k` and
        // start a parameter of its own, and `tag` after it would be a value on
        // a key Amazon ignores.
        $this->assertStringNotContainsString('& Gamble', $link->url);
        $this->assertStringEndsWith('&tag=giftcoves-21', $link->url);

        parse_str((string) parse_url($link->url, PHP_URL_QUERY), $parts);
        $this->assertSame('Procter & Gamble héél', $parts['k']);
        $this->assertSame('giftcoves-21', $parts['tag']);
    }

    /** An empty tag in config is treated as no tag: the link goes without one (2026-11-25). */
    #[Test]
    public function a_blank_tag_gives_an_untagged_link(): void
    {
        config()->set('giftcoves.amazon_search.markets.nl-nl.tag', '');

        $this->assertSame('https://www.amazon.nl/s?k=koptelefoon', AmazonSearchLink::for(Market::NlNl, 'koptelefoon')?->url);
    }
}
