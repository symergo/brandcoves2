<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\ListKind;
use App\Enums\Market;
use App\Http\Middleware\TrackAnonymousIdentity;
use App\Models\AnonymousIdentity;
use App\Models\Wishlist;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Anonymous identities stop piling up (2026-09-26): production held 2.56
 * million, 99% seen once, mostly crawlers. Crawlers get none, and a one-visit
 * identity that owns nothing is deleted after 30 days.
 */
class AnonymousIdentityGrowthTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function a_crawler_gets_no_identity_and_a_browser_does(): void
    {
        $this->withHeader('User-Agent', 'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)')
            ->get('/be-nl')
            ->assertOk()
            ->assertCookieMissing(TrackAnonymousIdentity::COOKIE);

        $this->assertSame(0, AnonymousIdentity::query()->count());

        $this->withHeader('User-Agent', 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 Version/17.0 Mobile/15E148 Safari/604.1')
            ->get('/be-nl')
            ->assertOk();

        $this->assertSame(1, AnonymousIdentity::query()->count());
    }

    #[Test]
    public function the_agent_rule_tells_machines_from_browsers(): void
    {
        foreach (['', 'curl/8.4.0', 'AhrefsBot/7.0', 'Mozilla/5.0 (compatible; bingbot/2.0)', 'WhatsApp/2.23.20.0', 'facebookexternalhit/1.1'] as $agent) {
            $this->assertTrue(TrackAnonymousIdentity::isMachine($agent), "'{$agent}' should count as a machine");
        }

        foreach ([
            'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/128.0 Safari/537.36',
            'Mozilla/5.0 (Linux; Android 13; CUBOT KingKong 9) AppleWebKit/537.36 Chrome/120.0 Mobile Safari/537.36',
        ] as $agent) {
            $this->assertFalse(TrackAnonymousIdentity::isMachine($agent), "'{$agent}' is a person's browser");
        }
    }

    #[Test]
    public function a_one_visit_identity_that_owns_nothing_goes_after_thirty_days(): void
    {
        $oneVisitOld = $this->identity(createdDaysAgo: 40, seenDaysAgo: 40);
        $oneVisitRecent = $this->identity(createdDaysAgo: 10, seenDaysAgo: 10);
        $cameBack = $this->identity(createdDaysAgo: 40, seenDaysAgo: 5);
        $ownsAList = $this->identity(createdDaysAgo: 40, seenDaysAgo: 40);

        Wishlist::create([
            'owner_anon_id' => $ownsAList,
            'title' => 'Mine',
            'market' => Market::BeNl,
            'kind' => ListKind::Mine,
            'visibility' => 'private',
        ]);

        $this->artisan('bc:prune-personal-data')->assertSuccessful();

        $left = AnonymousIdentity::query()->pluck('id')->all();

        $this->assertNotContains($oneVisitOld, $left);
        $this->assertContains($oneVisitRecent, $left, 'not a month old yet');
        $this->assertContains($cameBack, $left, 'came back on another day');
        $this->assertContains($ownsAList, $left, 'owns a list');
    }

    private function identity(int $createdDaysAgo, int $seenDaysAgo): string
    {
        $identity = AnonymousIdentity::create(['last_seen_at' => now()->subDays($seenDaysAgo)]);

        DB::table('anonymous_identities')->where('id', $identity->id)->update(['created_at' => now()->subDays($createdDaysAgo)]);

        return (string) $identity->id;
    }
}
