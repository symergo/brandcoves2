<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\Market;
use App\Services\Search\AmazonEvent;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The Amazon sales event under the home page's hero (owner, 2026-10-06):
 * Prime Big Deal Days on the Dutch and Belgian markets, French included,
 * only while it runs, always with the market's own Associates tag.
 */
class AmazonEventTest extends TestCase
{
    use RefreshDatabase;

    private function at(string $brussels): CarbonImmutable
    {
        return CarbonImmutable::parse($brussels, 'Europe/Brussels');
    }

    #[Test]
    public function each_market_gets_its_own_store_page_and_tag(): void
    {
        $now = $this->at('2026-10-06 12:00');

        $nl = AmazonEvent::current(Market::NlNl, $now);
        $this->assertSame('https://www.amazon.nl/primebigdealdays?tag=giftcoves-21', $nl['url']);
        $this->assertStringEndsWith('/NL_Live.gif', $nl['image']);

        $beNl = AmazonEvent::current(Market::BeNl, $now);
        $this->assertSame('https://www.amazon.com.be/joursprime?language=nl_BE&tag=giftcoves05-21', $beNl['url']);
        $this->assertStringEndsWith('/NL_Live.gif', $beNl['image']);

        $beFr = AmazonEvent::current(Market::BeFr, $now);
        $this->assertSame('https://www.amazon.com.be/joursprime?language=fr_BE&tag=giftcoves05-21', $beFr['url']);
        $this->assertStringEndsWith('/FR_Live.gif', $beFr['image']);
    }

    #[Test]
    public function only_while_it_runs_and_only_where_there_is_a_tag(): void
    {
        $this->assertNull(AmazonEvent::current(Market::BeNl, $this->at('2026-10-05 23:59')));
        $this->assertNotNull(AmazonEvent::current(Market::BeNl, $this->at('2026-10-06 00:00')));
        $this->assertNotNull(AmazonEvent::current(Market::BeNl, $this->at('2026-10-07 23:59')));
        $this->assertNull(AmazonEvent::current(Market::BeNl, $this->at('2026-10-08 00:00')));

        // No Associates tag for these markets: nothing, not an untracked link.
        $this->assertNull(AmazonEvent::current(Market::En, $this->at('2026-10-06 12:00')));
    }

    #[Test]
    public function the_home_page_carries_it_in_the_markets_language(): void
    {
        Cache::flush();
        $this->travelTo($this->at('2026-10-06 12:00'));

        $this->get('/be-fr')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('amazonEvent.title', 'Les Jours Flash Prime sur Amazon')
                ->where('amazonEvent.url', 'https://www.amazon.com.be/joursprime?language=fr_BE&tag=giftcoves05-21'));

        $this->travelTo($this->at('2026-10-08 12:00'));
        Cache::flush();

        $this->get('/nl-nl')->assertOk()->assertInertia(fn ($page) => $page->where('amazonEvent', null));
    }
}
