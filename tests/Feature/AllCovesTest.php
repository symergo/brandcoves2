<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\CoveKind;
use App\Enums\Market;
use App\Enums\PublishStatus;
use App\Models\DailyPickSet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * `/coves` — every Cove this market has published, by shape.
 *
 * The page exists because the three kinds each had an index of their own and
 * nothing held all of them: the header said "Cove" and pointed at four
 * different rooms. What is worth testing is the grouping, because the two ways
 * it can be wrong — a persona landing in the daily band, or a draft landing in
 * any of them — both leave a page that looks entirely correct.
 */
class AllCovesTest extends TestCase
{
    use RefreshDatabase;

    private function cove(
        CoveKind $kind,
        string $slug,
        string $title,
        Market $market = Market::BeNl,
        PublishStatus $status = PublishStatus::Published,
        ?string $dropDate = null,
        string $publishedAt = '2026-08-01',
    ): DailyPickSet {
        return DailyPickSet::create([
            'market' => $market->value,
            'kind' => $kind->value,
            'slug' => $slug,
            'theme_title' => $title,
            'theme_slug' => $slug,
            'theme_blurb' => 'Waar het over gaat.',
            'status' => $status->value,
            'drop_date' => $dropDate,
            'published_at' => $publishedAt,
        ]);
    }

    #[Test]
    public function it_groups_the_three_kinds_into_their_own_bands(): void
    {
        $this->cove(CoveKind::Daily, 'vondsten', 'Vondsten van vandaag', dropDate: '2026-08-20');
        $this->cove(CoveKind::Persona, 'de-vader', 'De vader die alles heeft');
        $this->cove(CoveKind::Guide, 'koptelefoons', 'De beste koptelefoons');

        $this->get('/be-nl/coves')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Coves/Index')
                ->has('sections', 3)
                // In the order the Discover menu lists them.
                ->where('sections.0.key', 'daily')
                ->where('sections.1.key', 'gift')
                ->where('sections.2.key', 'smart')
                ->where('sections.0.coves.0.title', 'Vondsten van vandaag')
                ->where('sections.1.coves.0.title', 'De vader die alles heeft')
                ->where('sections.2.coves.0.title', 'De beste koptelefoons')
            );
    }

    #[Test]
    public function a_seasonal_cove_belongs_to_the_theme_band(): void
    {
        /*
         * Seasonal is the kind most likely to be filed wrong: it is a separate
         * `CoveKind` but shares the `/guides` URL space, and the band is defined
         * by `scopeArticles()` rather than by a list of kinds precisely so that
         * adding a kind does not silently drop it off this page.
         */
        $this->cove(CoveKind::Seasonal, 'halloween', 'Halloween-cadeaus');
        $this->cove(CoveKind::Advice, 'echte-reviews', 'Een echte review herkennen');

        $this->get('/be-nl/coves')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('sections', 1)
                ->where('sections.0.key', 'smart')
                ->has('sections.0.coves', 2)
            );
    }

    #[Test]
    public function an_edition_is_linked_by_its_slug_and_not_its_date(): void
    {
        /*
         * `/daily/{date}` exists and 301s onto `/daily/{slug}`. Linking by date
         * would send every click on this page through a redirect — the archive
         * strip on `/daily` already links by slug for the same reason.
         */
        $this->cove(CoveKind::Daily, 'vondsten', 'Vondsten', dropDate: '2026-08-20');

        $this->get('/be-nl/coves')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('sections.0.coves.0.url', '/be-nl/cadeautips/vondsten')
                ->where('sections.0.coves.0.date', '20 Aug 2026')
            );
    }

    #[Test]
    public function a_persona_carries_no_date(): void
    {
        // On purpose: a persona never stops being current, so a publication
        // date on the card would invite the reader to treat an old one as
        // stale.
        $this->cove(CoveKind::Persona, 'de-lezer', 'De vriend die leest');

        $this->get('/be-nl/coves')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('sections.0.coves.0.date', null));
    }

    #[Test]
    public function an_empty_kind_drops_its_band_rather_than_heading_nothing(): void
    {
        $this->cove(CoveKind::Guide, 'koptelefoons', 'De beste koptelefoons');

        $this->get('/be-nl/coves')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('sections', 1)
                ->where('sections.0.key', 'smart')
            );
    }

    #[Test]
    public function a_draft_is_not_listed(): void
    {
        // A public page. A Cove still being written is published nowhere else
        // either, and this must not be the leak.
        $this->cove(CoveKind::Guide, 'concept', 'Nog niet klaar', status: PublishStatus::Draft);

        $this->get('/be-nl/coves')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->has('sections', 0));
    }

    #[Test]
    public function another_markets_coves_are_not_listed(): void
    {
        // Coves are written per market, in that market's language. A Dutch one
        // on the French page is the market boundary leaking.
        $this->cove(CoveKind::Guide, 'koptelefoons', 'De beste koptelefoons', market: Market::BeNl);

        $this->get('/be-fr/coves')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->has('sections', 0));
    }
}
