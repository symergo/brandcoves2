<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\CoveKind;
use App\Enums\Market;
use App\Enums\PublishStatus;
use App\Models\DailyPickSet;
use App\Services\Cove\DiscoverMenu;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The Discover menu lists only entries with something behind them in the market (2026-11-22:
 * Spain opened with eBay alone and most entries led to an empty page).
 */
class DiscoverMenuTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    #[Test]
    public function a_market_with_nothing_published_offers_no_entry_and_no_all_coves_link(): void
    {
        $this->assertSame(
            ['daily' => false, 'surprise' => false, 'giftIdeas' => false, 'guides' => false, 'community' => false, 'brands' => false, 'coves' => false],
            app(DiscoverMenu::class)->for(Market::Es),
        );
    }

    #[Test]
    public function a_published_daily_edition_shows_its_entry_and_all_coves_in_that_market_only(): void
    {
        DailyPickSet::create([
            'market' => Market::Es->value,
            'kind' => CoveKind::Daily->value,
            'slug' => '2026-11-20',
            'drop_date' => '2026-11-20',
            'theme_title' => 'Té',
            'theme_slug' => '2026-11-20',
            'status' => PublishStatus::Published->value,
            'published_at' => '2026-11-20 06:00:00',
        ]);

        $menu = app(DiscoverMenu::class)->for(Market::Es);
        $this->assertTrue($menu['daily']);
        $this->assertTrue($menu['coves']);
        $this->assertFalse($menu['guides']);
        $this->assertFalse(app(DiscoverMenu::class)->for(Market::BeFr)['daily']);

        // Every page carries it, for the header's menu
        $this->get('/es')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->where('discoverMenu.daily', true)
            ->where('discoverMenu.guides', false));
    }
}
