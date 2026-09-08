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
        // Four personas: three go to the persona band, the fourth is the one this band may show.
        foreach (range(1, 4) as $i) {
            $this->cove(CoveKind::Persona, "de-persona-{$i}", 100 + $i);
        }
        $this->cove(CoveKind::Brand, 'sony', 200);
        $this->cove(CoveKind::Shop, 'bol-com', 300);

        $band = $this->band();

        $this->assertCount(6, $band);
        $kinds = array_count_values(array_column($band, 'kind'));
        $this->assertSame(1, $kinds['persona'] ?? 0);
        $this->assertSame(1, $kinds['brand'] ?? 0);
        $this->assertSame(1, $kinds['shop'] ?? 0);
        $this->assertSame(3, $kinds['advice'] ?? 0);

        // Each kind links to its own address, not to /guides for all of them.
        $urls = array_column($band, 'url');
        $this->assertContains('/be-nl/gift-ideas/de-persona-4', $urls);
        $this->assertContains('/be-nl/brand/sony', $urls);
        $this->assertContains('/be-nl/shops/bol-com', $urls);
    }

    #[Test]
    public function a_persona_already_in_the_persona_band_is_not_repeated_below_it(): void
    {
        foreach (range(1, 3) as $i) {
            $this->cove(CoveKind::Persona, "de-persona-{$i}", $i);
        }
        $this->cove(CoveKind::Advice, 'advies', 10);

        $props = $this->get('/be-nl')->assertOk()->viewData('page')['props'];

        $this->assertCount(3, $props['personas']);
        $this->assertSame(['advice'], array_column($props['coves'], 'kind'));
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
