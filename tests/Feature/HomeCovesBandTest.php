<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\CoveKind;
use App\Enums\Market;
use App\Models\DailyPickSet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The Coves shelf on the front page: ten at random, any kind but the
 * dailies, held for an hour per market.
 *
 * It was a round-robin of six cards, then for an afternoon the ten newest;
 * both went on 2026-09-13 at the owner's request. The cache is flushed per
 * test because the array store outlives RefreshDatabase within a process,
 * and a shelf drawn from one test's rows would be served to the next.
 */
class HomeCovesBandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    private function cove(CoveKind $kind, string $slug, int $minutesAgo = 0, ?string $dropDate = null): DailyPickSet
    {
        return DailyPickSet::create([
            'market' => Market::BeNl,
            'kind' => $kind,
            'slug' => $slug,
            'theme_title' => ucfirst(str_replace('-', ' ', $slug)),
            'theme_slug' => $slug,
            'status' => 'published',
            'published_at' => now()->subMinutes($minutesAgo),
            'drop_date' => $dropDate,
        ]);
    }

    private function band(): array
    {
        return $this->get('/be-nl')->assertOk()->viewData('page')['props']['coves'];
    }

    #[Test]
    public function every_kind_but_the_dailies_can_be_on_the_shelf(): void
    {
        $this->cove(CoveKind::Advice, 'advies', 1);
        $this->cove(CoveKind::Persona, 'de-persona', 2);
        $this->cove(CoveKind::Brand, 'sony', 3);
        $this->cove(CoveKind::Shop, 'bol-com', 4);
        $this->cove(CoveKind::Guide, 'gids', 5);
        $today = now()->toDateString();
        $this->cove(CoveKind::Daily, $today, 0, $today);

        $band = $this->band();

        // Five rows, one per kind, whatever the order; the daily is not one
        // of them — one appears every day, and it has the band above.
        $this->assertCount(5, $band);
        $kinds = array_column($band, 'kind');
        sort($kinds);
        $this->assertSame(['advice', 'brand', 'guide', 'persona', 'shop'], $kinds);

        // Each kind links to its own address, not to /guides for all of them.
        $urls = array_column($band, 'url');
        $this->assertContains('/be-nl/gift-ideas/de-persona', $urls);
        $this->assertContains('/be-nl/brand/sony', $urls);
        $this->assertContains('/be-nl/shops/bol-com', $urls);
        $this->assertContains('/be-nl/guides/gids', $urls);
        $this->assertArrayNotHasKey('date', $band[0]);
    }

    #[Test]
    public function the_shelf_holds_still_for_an_hour(): void
    {
        // Drawn at random, but not per request: a visitor who reloads sees
        // the same shelf, and the page does not pay for ORDER BY random() on
        // every hit. Twelve rows and ten slots, so two draws would almost
        // certainly differ.
        foreach (range(1, 12) as $i) {
            $this->cove(CoveKind::Advice, "advies-{$i}", $i);
        }

        $first = $this->band();
        $this->cove(CoveKind::Guide, 'net-gepubliceerd', 0);

        $this->assertSame($first, $this->band());

        $this->travel(61)->minutes();

        $later = $this->band();
        $this->assertCount(10, $later);
        // The new Cove is now eligible; the shelf was redrawn from thirteen.
        $this->assertNotSame($first, $later);
    }

    #[Test]
    public function a_market_with_nothing_published_has_no_shelf(): void
    {
        $props = $this->get('/be-nl')->assertOk()->viewData('page')['props'];

        $this->assertSame([], $props['coves']);
        $this->assertArrayNotHasKey('personas', $props);
    }
}
