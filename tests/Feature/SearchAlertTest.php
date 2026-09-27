<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\AlertState;
use App\Enums\Availability;
use App\Enums\Market;
use App\Enums\ProductStatus;
use App\Enums\Source;
use App\Jobs\CheckSearchAlerts;
use App\Models\Merchant;
use App\Models\Notification;
use App\Models\Product;
use App\Models\ProductGroup;
use App\Models\SearchAlert;
use App\Models\User;
use App\Services\Search\SearchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Watching a search: "tell me when something new matches this, under this".
 *
 * The load-bearing assertion is the one about "new". A watch that announced
 * the same thirty results every morning would be unsubscribed from by lunch.
 */
class SearchAlertTest extends TestCase
{
    use RefreshDatabase;

    private function product(string $title, int $price): ProductGroup
    {
        $group = ProductGroup::create([
            'market' => Market::BeNl,
            'identity_key' => 'k'.bin2hex(random_bytes(4)),
            'identity_kind' => 'ean',
            'title' => $title,
            'slug' => str($title)->slug()->toString(),
            'min_price' => $price,
            'merchant_count' => 1,
            'in_stock' => true,
            'image_url' => 'https://example.test/'.bin2hex(random_bytes(3)).'.jpg',
        ]);

        $merchant = Merchant::firstOrCreate(
            ['source' => Source::Awin->value, 'external_id' => 'awin-shop'],
            ['name' => 'Shop'],
        );

        Product::create([
            'source' => Source::Awin,
            'market' => Market::BeNl,
            'merchant_id' => $merchant->id,
            'group_id' => $group->id,
            'external_id' => 'x'.bin2hex(random_bytes(4)),
            'title' => $title,
            'price' => $price,
            'currency' => 'EUR',
            'affiliate_url' => 'https://example.test/buy',
            'availability' => Availability::InStock,
            'status' => ProductStatus::Active,
            'identity_key' => $group->identity_key,
        ]);

        return $group;
    }

    #[Test]
    public function watching_a_search_needs_an_account(): void
    {
        $this->post('/be-nl/search-alerts', ['term' => 'koptelefoon'])->assertRedirect('/be-nl/login');

        $this->assertSame(0, SearchAlert::query()->count());
    }

    #[Test]
    public function a_watch_starts_from_what_is_already_on_the_page(): void
    {
        $existing = $this->product('Sony koptelefoon', 19900);
        $user = User::create(['email' => 'watcher@example.test']);

        $this->actingAs($user)
            ->post('/be-nl/search-alerts', ['term' => '  Koptelefoon ', 'max_price' => '250'])
            ->assertRedirect();

        $alert = SearchAlert::query()->firstOrFail();

        // Normalised, so "Koptelefoon " and "koptelefoon" are one watch.
        $this->assertSame('koptelefoon', $alert->term);
        $this->assertSame(25000, $alert->max_price);
        // What matches today is what the person is looking at: not news.
        $this->assertContains($existing->id, $alert->seen_group_ids);

        // Watching it again updates rather than doubling.
        $this->actingAs($user)->post('/be-nl/search-alerts', ['term' => 'koptelefoon'])->assertRedirect();
        $this->assertSame(1, SearchAlert::query()->count());
        $this->assertNull(SearchAlert::query()->firstOrFail()->max_price);
    }

    #[Test]
    public function only_a_new_match_under_the_ceiling_is_announced_and_only_once(): void
    {
        $this->product('Sony koptelefoon', 19900);
        $user = User::create(['email' => 'watcher@example.test']);

        $this->actingAs($user)
            ->post('/be-nl/search-alerts', ['term' => 'koptelefoon', 'max_price' => '250'])
            ->assertRedirect();

        // Nothing new yet: the only match was on the page when the watch was set.
        (new CheckSearchAlerts)->handle(app(SearchService::class));
        $this->assertSame(0, Notification::query()->count());

        // Two arrivals: one under the ceiling, one over it.
        $cheap = $this->product('Philips koptelefoon', 8900);
        $this->product('Bang & Olufsen koptelefoon', 79900);

        // The next morning: a watch is checked once a day (since 2026-09-28 a
        // second run on the same day skips it, so a retried run resumes).
        $this->travel(1)->days();
        (new CheckSearchAlerts)->handle(app(SearchService::class));

        $notification = Notification::query()->firstOrFail();
        $this->assertSame($user->id, $notification->user_id);
        $this->assertSame('search_match', $notification->kind);
        $this->assertSame(1, $notification->payload['count']);
        $this->assertStringContainsString('koptelefoon', $notification->title);
        $this->assertStringContainsString('/be-nl/zoek/koptelefoon', (string) $notification->url);
        $this->assertContains($cheap->id, SearchAlert::query()->firstOrFail()->seen_group_ids);

        // The next morning says nothing about the same product.
        $this->travel(1)->days();
        (new CheckSearchAlerts)->handle(app(SearchService::class));
        $this->assertSame(1, Notification::query()->count());
    }

    #[Test]
    public function a_run_checks_each_watch_once_a_day_and_each_search_once(): void
    {
        $this->product('Sony koptelefoon', 19900);
        $first = User::create(['email' => 'one@example.test']);
        $second = User::create(['email' => 'two@example.test']);

        $this->actingAs($first)->post('/be-nl/search-alerts', ['term' => 'koptelefoon'])->assertRedirect();
        $this->actingAs($second)->post('/be-nl/search-alerts', ['term' => 'Koptelefoon'])->assertRedirect();

        // Two people watching one term is ONE search, across both runs: the
        // retry on the same day finds both watches already checked.
        $this->mock(SearchService::class, fn ($mock) => $mock->shouldReceive('matchingGroupIds')->once()->andReturn([]));

        $this->travel(1)->days();
        (new CheckSearchAlerts)->handle(app(SearchService::class));
        (new CheckSearchAlerts)->handle(app(SearchService::class));

        $this->assertSame(2, SearchAlert::query()->whereDate('last_checked_at', today())->count());
    }

    #[Test]
    public function the_search_page_says_whether_it_is_watched_and_stopping_removes_it(): void
    {
        $this->product('Sony koptelefoon', 19900);
        $user = User::create(['email' => 'watcher@example.test']);

        $this->get('/be-nl/search?q=koptelefoon')
            ->assertInertia(fn ($page) => $page->where('watch.requiresAccount', true)->where('watch.watching', false));

        $this->actingAs($user)->post('/be-nl/search-alerts', ['term' => 'koptelefoon'])->assertRedirect();
        $alert = SearchAlert::query()->firstOrFail();

        $this->actingAs($user)
            ->get('/be-nl/search?q=Koptelefoon')
            ->assertInertia(fn ($page) => $page->where('watch.watching', true)->where('watch.id', $alert->id));

        // Somebody else cannot stop it.
        $this->actingAs(User::create(['email' => 'other@example.test']))
            ->delete("/be-nl/search-alerts/{$alert->id}")
            ->assertRedirect();
        $this->assertSame(AlertState::Active, $alert->fresh()->state);

        $this->actingAs($user)->delete("/be-nl/search-alerts/{$alert->id}")->assertRedirect();
        $this->assertNull($alert->fresh());
    }
}
