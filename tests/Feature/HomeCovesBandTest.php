<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\CoveKind;
use App\Enums\Market;
use App\Models\DailyPickSet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The front page's "Coves" band shows every shape a Cove takes.
 *
 * It listed the six newest articles, which was the whole archive when it was
 * written. A day that published fourteen advice pieces then turned it into an
 * advice column, and the owner looked for the personas under "Coves" and found
 * none (2026-09-08).
 */
class HomeCovesBandTest extends TestCase
{
    use RefreshDatabase;

    private function cove(CoveKind $kind, string $slug, int $minutesAgo = 0): DailyPickSet
    {
        return DailyPickSet::create([
            'market' => Market::BeNl,
            'kind' => $kind,
            'slug' => $slug,
            'theme_title' => ucfirst(str_replace('-', ' ', $slug)),
            'theme_slug' => $slug,
            'status' => 'published',
            'published_at' => now()->subMinutes($minutesAgo),
        ]);
    }

    private function band(): array
    {
        return $this->get('/be-nl')->assertOk()->viewData('page')['props']['coves'];
    }

    #[Test]
    public function the_band_mixes_the_kinds_rather_than_listing_the_newest_articles(): void
    {
        foreach (range(1, 8) as $i) {
            $this->cove(CoveKind::Advice, "advies-{$i}", $i);
        }
        // Four personas; the newest is the one the round-robin picks first.
        foreach (range(1, 4) as $i) {
            $this->cove(CoveKind::Persona, "de-persona-{$i}", 100 + $i);
        }
        $this->cove(CoveKind::Brand, 'sony', 200);
        $this->cove(CoveKind::Shop, 'bol-com', 300);

        $band = $this->band();

        // Six cards, four lanes: persona, advice, brand, shop, then the
        // persona and advice lanes come round again.
        $this->assertCount(6, $band);
        $kinds = array_count_values(array_column($band, 'kind'));
        $this->assertSame(2, $kinds['persona'] ?? 0);
        $this->assertSame(1, $kinds['brand'] ?? 0);
        $this->assertSame(1, $kinds['shop'] ?? 0);
        $this->assertSame(2, $kinds['advice'] ?? 0);

        // Each kind links to its own address, not to /guides for all of them.
        $urls = array_column($band, 'url');
        $this->assertContains('/be-nl/gift-ideas/de-persona-1', $urls);
        $this->assertContains('/be-nl/brand/sony', $urls);
        $this->assertContains('/be-nl/shops/bol-com', $urls);
    }

    #[Test]
    public function a_persona_is_in_this_band_now_that_it_has_no_band_of_its_own(): void
    {
        // The persona band left the front page on 2026-09-08; this band is
        // where a persona meets a first-time visitor, so none is skipped.
        foreach (range(1, 3) as $i) {
            $this->cove(CoveKind::Persona, "de-persona-{$i}", $i);
        }
        $this->cove(CoveKind::Advice, 'advies', 10);

        $props = $this->get('/be-nl')->assertOk()->viewData('page')['props'];

        $this->assertCount(3, $props['personas']);
        $this->assertSame(['persona', 'advice', 'persona', 'persona'], array_column($props['coves'], 'kind'));
    }

    #[Test]
    public function a_market_with_only_articles_still_gets_its_articles(): void
    {
        foreach (range(1, 7) as $i) {
            $this->cove(CoveKind::Advice, "advies-{$i}", $i);
        }

        $band = $this->band();

        $this->assertCount(6, $band);
        $this->assertSame('Advies 1', $band[0]['title']);
    }
}
