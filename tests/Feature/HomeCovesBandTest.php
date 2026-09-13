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
 * The recent Coves list on the front page.
 *
 * Every kind, newest first, ten rows, today's edition left out because it
 * has the band above. It replaced a round-robin of six cards on 2026-09-13.
 */
class HomeCovesBandTest extends TestCase
{
    use RefreshDatabase;

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
    public function the_list_is_newest_first_across_every_kind(): void
    {
        $this->cove(CoveKind::Advice, 'advies', 1);
        $this->cove(CoveKind::Persona, 'de-persona', 2);
        $this->cove(CoveKind::Brand, 'sony', 3);
        $this->cove(CoveKind::Shop, 'bol-com', 4);
        $this->cove(CoveKind::Guide, 'gids', 5);
        // Two dailies: today's has the band above and stays out of the list,
        // yesterday's is a Cove like any other.
        $today = now()->toDateString();
        $yesterday = now()->subDay()->toDateString();
        $this->cove(CoveKind::Daily, $today, 0, $today);
        $this->cove(CoveKind::Daily, $yesterday, 6, $yesterday);

        $band = $this->band();

        $this->assertSame(['advice', 'persona', 'brand', 'shop', 'guide', 'daily'], array_column($band, 'kind'));

        // Each kind links to its own address, not to /guides for all of them.
        $urls = array_column($band, 'url');
        $this->assertContains('/be-nl/gift-ideas/de-persona', $urls);
        $this->assertContains('/be-nl/brand/sony', $urls);
        $this->assertContains('/be-nl/shops/bol-com', $urls);
        $this->assertContains('/be-nl/guides/gids', $urls);
        $this->assertContains("/be-nl/tips/{$yesterday}", $urls);

        // The daily carries its edition day; the rest their publication day.
        $this->assertSame($yesterday, $band[5]['date']);
        $this->assertSame(now()->toDateString(), $band[0]['date']);
    }

    #[Test]
    public function todays_edition_is_not_repeated_below_its_own_band(): void
    {
        $today = now()->toDateString();
        $yesterday = now()->subDay()->toDateString();
        $this->cove(CoveKind::Daily, $today, 1, $today);
        $this->cove(CoveKind::Daily, $yesterday, 2, $yesterday);

        $props = $this->get('/be-nl')->assertOk()->viewData('page')['props'];

        $this->assertNotNull($props['today']);
        $this->assertSame(["/be-nl/tips/{$yesterday}"], array_column($props['coves'], 'url'));
    }

    #[Test]
    public function ten_rows_and_no_more(): void
    {
        foreach (range(1, 12) as $i) {
            $this->cove(CoveKind::Advice, "advies-{$i}", $i);
        }

        $band = $this->band();

        $this->assertCount(10, $band);
        $this->assertSame('Advies 1', $band[0]['title']);
    }

    #[Test]
    public function a_market_with_nothing_published_has_no_list(): void
    {
        $props = $this->get('/be-nl')->assertOk()->viewData('page')['props'];

        $this->assertSame([], $props['coves']);
        $this->assertArrayNotHasKey('personas', $props);
    }
}
