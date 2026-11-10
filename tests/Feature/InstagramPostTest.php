<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\CoveKind;
use App\Enums\Market;
use App\Enums\PublishStatus;
use App\Models\DailyPickSet;
use App\Models\InstagramPost;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * GiftCoves' Instagram account: `bc:post-instagram` posts the day's Daily Cove
 * and each new guide once (owner, 2026-11-10). See docs/features/instagram.md.
 */
class InstagramPostTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Sleep::fake();
        config([
            'app.url' => 'https://giftcoves.test',
            'giftcoves.instagram.enabled' => true,
            'giftcoves.instagram.access_token' => 'secret-token',
            'giftcoves.instagram.user_id' => '17841400000000000',
            'giftcoves.instagram.market' => 'be-nl',
            'giftcoves.instagram.graph_host' => 'graph.facebook.com',
        ]);
    }

    #[Test]
    public function it_posts_todays_daily_cove_with_its_card_and_caption(): void
    {
        $this->fakeGraph();
        $daily = $this->daily(now()->toDateString());

        $this->artisan('bc:post-instagram --only=daily')->assertSuccessful();

        $row = InstagramPost::query()->sole();
        $this->assertSame($daily->id, $row->daily_pick_set_id);
        $this->assertSame(InstagramPost::POSTED, $row->status);
        $this->assertSame('media-1', $row->media_id);

        Http::assertSent(function (Request $request) {
            return str_ends_with($request->url(), '/17841400000000000/media')
                && $request['image_url'] === 'https://giftcoves.test/be-nl/og/daily/'.now()->toDateString().'.jpg'
                && str_contains($request['caption'], 'Alles voor de barbecue')
                && str_contains($request['caption'], 'giftcoves.test/be-nl/tips/'.now()->toDateString())
                && str_contains($request['caption'], '#giftcoves');
        });
        Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/media_publish') && $request['creation_id'] === 'container-1');
    }

    #[Test]
    public function the_token_is_a_header_and_never_part_of_a_url(): void
    {
        $this->fakeGraph();
        $this->daily(now()->toDateString());

        $this->artisan('bc:post-instagram --only=daily')->assertSuccessful();

        // A failed request's exception message carries its URL; a token in the
        // URL would be a secret in a log line.
        Http::assertSent(fn (Request $request) => $request->hasHeader('Authorization', 'Bearer secret-token'));
        Http::assertNotSent(fn (Request $request) => str_contains($request->url(), 'secret-token'));
    }

    #[Test]
    public function a_cove_is_posted_once(): void
    {
        $this->fakeGraph();
        $this->daily(now()->toDateString());

        $this->artisan('bc:post-instagram --only=daily')->assertSuccessful();
        $this->artisan('bc:post-instagram --only=daily')->assertSuccessful();

        $this->assertSame(1, InstagramPost::query()->count());
        Http::assertSentCount(3); // create, status, publish: once
    }

    #[Test]
    public function guides_are_posted_oldest_first_one_per_run_and_only_this_weeks(): void
    {
        $this->fakeGraph();
        $old = $this->guide('oude-gids', now()->subDays(20));
        $first = $this->guide('eerste-gids', now()->subDays(2));
        $second = $this->guide('tweede-gids', now()->subDay());

        $this->artisan('bc:post-instagram --only=guides')->assertSuccessful();
        $this->assertSame([$first->id], InstagramPost::query()->pluck('daily_pick_set_id')->all());

        $this->artisan('bc:post-instagram --only=guides')->assertSuccessful();
        $this->artisan('bc:post-instagram --only=guides')->assertSuccessful();

        // Switching the feature on must not empty the archive into the feed.
        $this->assertEqualsCanonicalizing([$first->id, $second->id], InstagramPost::query()->pluck('daily_pick_set_id')->all());
        $this->assertNotContains($old->id, InstagramPost::query()->pluck('daily_pick_set_id')->all());

        Http::assertSent(fn (Request $request) => ($request['image_url'] ?? null) === 'https://giftcoves.test/be-nl/og/guide/eerste-gids.jpg');
    }

    #[Test]
    public function a_refusal_is_recorded_and_retried_until_the_limit(): void
    {
        Http::fake(['*' => Http::response(['error' => ['message' => 'Invalid image']], 400)]);
        $this->daily(now()->toDateString());

        $this->artisan('bc:post-instagram --only=daily')->assertFailed();

        $row = InstagramPost::query()->sole();
        $this->assertSame(InstagramPost::FAILED, $row->status);
        $this->assertStringContainsString('Invalid image', (string) $row->error);

        $this->artisan('bc:post-instagram --only=daily')->assertFailed();
        $this->artisan('bc:post-instagram --only=daily')->assertFailed();
        // Three attempts, then left alone for a person to read.
        $this->artisan('bc:post-instagram --only=daily')->assertSuccessful();
        $this->assertSame(3, $row->fresh()->attempts);
    }

    #[Test]
    public function nothing_is_sent_while_it_is_off(): void
    {
        Http::fake();
        config(['giftcoves.instagram.enabled' => false]);
        $this->daily(now()->toDateString());

        // Off is a valid state, not a failing job.
        $this->artisan('bc:post-instagram')->assertSuccessful();

        Http::assertNothingSent();
        $this->assertSame(0, InstagramPost::query()->count());
    }

    #[Test]
    public function a_dry_run_shows_the_caption_and_posts_nothing(): void
    {
        Http::fake();
        $this->guide('eerste-gids', now()->subDay());

        $this->artisan('bc:post-instagram --dry-run')
            ->expectsOutputToContain('Lees de hele gids op giftcoves.test/be-nl/guides/eerste-gids')
            ->assertSuccessful();

        Http::assertNothingSent();
    }

    #[Test]
    public function the_guide_and_daily_cards_exist_as_jpeg_for_instagram(): void
    {
        $this->guide('eerste-gids', now()->subDay());
        $this->daily('2026-08-08', CarbonImmutable::parse('2026-08-08 06:00'));

        $guide = $this->get('/be-nl/og/guide/eerste-gids.jpg')->assertOk()->assertHeader('Content-Type', 'image/jpeg');
        $this->assertSame([1200, 630], array_slice(getimagesizefromstring($guide->getContent()), 0, 2));
        $this->assertSame(IMAGETYPE_JPEG, getimagesizefromstring($guide->getContent())[2]);

        $this->get('/be-nl/og/daily/2026-08-08.jpg')->assertOk()->assertHeader('Content-Type', 'image/jpeg');

        // Its own ETag, so a client holding the PNG never gets a 304 for the JPEG.
        $png = $this->get('/be-nl/og/guide/eerste-gids.png');
        $this->assertNotSame($png->headers->get('ETag'), $guide->headers->get('ETag'));

        $this->get('/be-nl/og/guide/bestaat-niet.jpg')->assertNotFound();
    }

    private function fakeGraph(): void
    {
        $n = 0;

        Http::fake(function (Request $request) use (&$n) {
            if (str_ends_with($request->url(), '/media')) {
                $n++;

                return Http::response(['id' => "container-{$n}"]);
            }

            if (str_ends_with($request->url(), '/media_publish')) {
                return Http::response(['id' => 'media-'.$n]);
            }

            return Http::response(['status_code' => 'FINISHED']);
        });
    }

    private function daily(string $date, ?CarbonImmutable $publishedAt = null): DailyPickSet
    {
        return DailyPickSet::create([
            'market' => Market::BeNl->value,
            'drop_date' => $date,
            'theme_title' => 'Alles voor de barbecue',
            'theme_slug' => 'barbecue-'.$date,
            'theme_blurb' => 'Voor wie de grill nooit koud laat worden.',
            'theme_source' => 'theme',
            'status' => PublishStatus::Published->value,
            'published_at' => $publishedAt ?? now()->subHour(),
        ]);
    }

    private function guide(string $slug, \DateTimeInterface $publishedAt): DailyPickSet
    {
        return DailyPickSet::create([
            'market' => Market::BeNl->value,
            'kind' => CoveKind::Guide->value,
            'slug' => $slug,
            'theme_title' => 'De beste koptelefoons',
            'theme_slug' => $slug,
            'theme_blurb' => 'Welke [[brand:Sony|Sony]] past bij jou?',
            'status' => PublishStatus::Published->value,
            'published_at' => $publishedAt,
        ]);
    }
}
