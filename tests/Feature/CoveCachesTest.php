<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\CoveKind;
use App\Enums\Market;
use App\Enums\PlanWriter;
use App\Enums\PublishStatus;
use App\Jobs\BuildCove;
use App\Models\CovePlan;
use App\Models\DailyPickSet;
use App\Services\Cove\CoveCaches;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The lists of Coves are cached for minutes, and a published Cove still shows
 * at once: publishing forgets them, and no cache outlives the Daily's release.
 */
class CoveCachesTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function a_published_cove_shows_on_the_lists_at_once(): void
    {
        $this->advice('eerste', 'De eerste gids');

        // Warm every list with the first Cove only.
        $this->get('/be-nl/coves')->assertOk();
        $this->get('/be-nl/discover-cove')->assertOk();
        $this->assertTrue(Cache::has(CoveCaches::covesKey(Market::BeNl)));
        $this->assertTrue(Cache::has(CoveCaches::discoverKey(Market::BeNl)));

        // A second one, published the way PublishDueCoves does it.
        $plan = CovePlan::create([
            'market' => Market::BeNl->value,
            'kind' => CoveKind::Advice->value,
            'slug' => 'tweede',
            'title' => 'De tweede gids',
            'status' => 'approved',
            'writer' => PlanWriter::Authored->value,
            'blurb' => 'Waar het over gaat.',
            'body' => "Eerste alinea.\n\nTweede alinea.",
        ]);
        BuildCove::dispatchSync($plan->id);

        $this->assertFalse(Cache::has(CoveCaches::covesKey(Market::BeNl)));
        $this->assertFalse(Cache::has(CoveCaches::discoverKey(Market::BeNl)));

        $this->get('/be-nl/coves')->assertOk()->assertSee('De tweede gids');
        $this->get('/be-nl/discover-cove')->assertOk()->assertSee('De tweede gids');
    }

    #[Test]
    public function another_markets_lists_are_left_alone(): void
    {
        Cache::put(CoveCaches::covesKey(Market::NlNl), ['kept'], 600);

        CoveCaches::forgetMarket(Market::BeNl);

        $this->assertSame(['kept'], Cache::get(CoveCaches::covesKey(Market::NlNl)));
    }

    #[Test]
    public function no_list_is_cached_past_the_dailys_release(): void
    {
        config(['giftcoves.picks.drop_time' => '09:00']);

        // Five minutes before the drop: half an hour would carry yesterday's
        // edition until 09:25.
        $this->travelTo(now()->setTime(8, 55));
        $this->assertSame(300, CoveCaches::ttl(1800));

        // Well away from it, the asked lifetime stands.
        $this->travelTo(now()->setTime(12, 0));
        $this->assertSame(1800, CoveCaches::ttl(1800));

        // Just after, the next release is tomorrow.
        $this->travelTo(now()->setTime(9, 0, 30));
        $this->assertSame(600, CoveCaches::ttl(600));
    }

    private function advice(string $slug, string $title): DailyPickSet
    {
        return DailyPickSet::create([
            'market' => Market::BeNl->value,
            'kind' => CoveKind::Advice->value,
            'slug' => $slug,
            'theme_title' => $title,
            'theme_slug' => $slug,
            'theme_blurb' => 'Waar het over gaat.',
            'body' => 'Een alinea.',
            'status' => PublishStatus::Published->value,
            'published_at' => now()->subDay(),
        ]);
    }
}
