<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\AlertState;
use App\Enums\Availability;
use App\Enums\Market;
use App\Enums\ProductStatus;
use App\Enums\Source;
use App\Jobs\RefreshWishlistedProducts;
use App\Models\Merchant;
use App\Models\Notification;
use App\Models\PriceAlert;
use App\Models\Product;
use App\Models\ProductGroup;
use App\Models\RestockAlert;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Price and restock alerts.
 *
 * Two things are being protected here. One is correctness: an alert that fires
 * on a price nobody can actually pay is worse than no alert. The other is
 * compliance — a source whose programme forbids a price-tracking feature must
 * not be able to trigger a notification, and that has to be enforced on the
 * server, not merely hidden in the UI.
 */
class AlertTest extends TestCase
{
    use RefreshDatabase;

    private function group(int $minPrice = 32999, bool $inStock = true): ProductGroup
    {
        return ProductGroup::create([
            'market' => Market::BeNl,
            'identity_key' => 'k'.bin2hex(random_bytes(4)),
            'identity_kind' => 'ean',
            'title' => 'Sony WH-1000XM5',
            'slug' => 'sony-wh-1000xm5',
            'min_price' => $minPrice,
            'merchant_count' => 1,
            'in_stock' => $inStock,
        ]);
    }

    private function offer(ProductGroup $group, Source $source, ?int $price, bool $inStock = true): Product
    {
        $merchant = Merchant::firstOrCreate(
            ['source' => $source->value, 'external_id' => $source->value.'-shop'],
            ['name' => ucfirst($source->value)]
        );

        return Product::create([
            'source' => $source,
            'market' => $group->market,
            'merchant_id' => $merchant->id,
            'group_id' => $group->id,
            'external_id' => 'x'.bin2hex(random_bytes(4)),
            'title' => $group->title,
            'price' => $price,
            'currency' => 'EUR',
            'affiliate_url' => 'https://example.test/buy',
            'availability' => $inStock ? Availability::InStock : Availability::OutOfStock,
            'status' => ProductStatus::Active,
            'identity_key' => $group->identity_key,
        ]);
    }

    private function user(): User
    {
        return User::create(['email' => 'watcher@example.test']);
    }

    #[Test]
    public function an_alert_needs_an_account(): void
    {
        $group = $this->group();
        $this->offer($group, Source::Awin, 32999);

        // Not a soft nudge: an alert fires days later, and a cookie identity
        // has nowhere to deliver to. Sent to login rather than refused, and to
        // the login page of the market they were browsing — the framework
        // default cannot build that URL, because every route is market-prefixed.
        $this->post('/be-nl/alerts', ['group_id' => $group->id, 'type' => 'price'])
            ->assertRedirect('/be-nl/login');

        $this->assertSame(0, PriceAlert::query()->count());
    }

    #[Test]
    public function a_price_alert_records_the_current_price_as_its_baseline(): void
    {
        $group = $this->group();
        $this->offer($group, Source::Awin, 32999);
        $this->offer($group, Source::Awin, 29999);

        $this->actingAs($this->user())
            ->post('/be-nl/alerts', ['group_id' => $group->id, 'type' => 'price'])
            ->assertRedirect();

        // The cheapest watchable offer, not the group aggregate: a drop is
        // measured against what the shopper could actually have paid.
        $this->assertSame(29999, PriceAlert::query()->firstOrFail()->baseline_price);
    }

    #[Test]
    public function a_target_price_is_stored_in_cents(): void
    {
        $group = $this->group();
        $this->offer($group, Source::Awin, 32999);

        $this->actingAs($this->user())
            ->post('/be-nl/alerts', [
                'group_id' => $group->id,
                'type' => 'price',
                'target_price' => '249.95',
            ]);

        // Floats accumulate error across comparisons; the column is cents.
        $this->assertSame(24995, PriceAlert::query()->firstOrFail()->target_price);
    }

    #[Test]
    public function watching_the_same_product_twice_does_not_create_two_alerts(): void
    {
        $group = $this->group();
        $this->offer($group, Source::Awin, 32999);
        $user = $this->user();

        $this->actingAs($user)->post('/be-nl/alerts', ['group_id' => $group->id, 'type' => 'price']);
        $this->actingAs($user)->post('/be-nl/alerts', ['group_id' => $group->id, 'type' => 'price']);

        // Otherwise one drop produces two notifications.
        $this->assertSame(1, PriceAlert::query()->count());
    }

    #[Test]
    public function a_product_sold_only_by_a_source_that_forbids_tracking_cannot_be_watched(): void
    {
        $group = $this->group();
        $this->offer($group, Source::Amazon, 32999);

        // COMPLIANCE. Enforced server-side: hiding the button is not enough,
        // because a hand-built POST would otherwise create the alert anyway.
        $this->actingAs($this->user())
            ->post('/be-nl/alerts', ['group_id' => $group->id, 'type' => 'price'])
            ->assertRedirect();

        $this->assertSame(0, PriceAlert::query()->count());
    }

    #[Test]
    public function a_drop_at_an_untrackable_source_does_not_fire_the_alert(): void
    {
        $group = $this->group();
        $this->offer($group, Source::Awin, 32999);
        $this->offer($group, Source::Amazon, 19999);

        $alert = PriceAlert::create([
            'group_id' => $group->id,
            'user_id' => $this->user()->id,
            'baseline_price' => 32999,
            'state' => AlertState::Active->value,
        ]);

        (new RefreshWishlistedProducts)->handle();

        // The cheapest offer in the group is now far below the baseline, but it
        // comes from a source whose rules forbid a price-tracking feature. The
        // alert must stay silent — this is the case a naive min(price) gets
        // wrong, because product_groups.min_price counts every source.
        $this->assertSame(AlertState::Active, $alert->fresh()->state);
        $this->assertSame(0, Notification::query()->count());
    }

    #[Test]
    public function a_real_drop_fires_once_and_leaves_a_notification(): void
    {
        $group = $this->group();
        $this->offer($group, Source::Awin, 27999);

        $alert = PriceAlert::create([
            'group_id' => $group->id,
            'user_id' => $this->user()->id,
            'baseline_price' => 32999,
            'state' => AlertState::Active->value,
        ]);

        (new RefreshWishlistedProducts)->handle();
        // Second run: the price is still below the baseline, but the alert has
        // already been honoured. Firing again would mean a notification every
        // scheduled run until the price recovers.
        (new RefreshWishlistedProducts)->handle();

        $this->assertSame(AlertState::Triggered, $alert->fresh()->state);
        $this->assertSame(1, Notification::query()->count());

        $notification = Notification::query()->firstOrFail();
        $this->assertSame('price_drop', $notification->kind);
        $this->assertSame(27999, $notification->payload['price']);
    }

    #[Test]
    public function a_target_price_suppresses_a_small_drop(): void
    {
        $group = $this->group();
        $this->offer($group, Source::Awin, 32499);

        $alert = PriceAlert::create([
            'group_id' => $group->id,
            'user_id' => $this->user()->id,
            'baseline_price' => 32999,
            'target_price' => 25000,
            'state' => AlertState::Active->value,
        ]);

        (new RefreshWishlistedProducts)->handle();

        // Someone who asked for "under €250" does not want to hear about €5 off.
        $this->assertSame(AlertState::Active, $alert->fresh()->state);
    }

    #[Test]
    public function an_out_of_stock_offer_does_not_count_as_a_price(): void
    {
        $group = $this->group();
        $this->offer($group, Source::Awin, 9999, inStock: false);

        $alert = PriceAlert::create([
            'group_id' => $group->id,
            'user_id' => $this->user()->id,
            'baseline_price' => 32999,
            'state' => AlertState::Active->value,
        ]);

        (new RefreshWishlistedProducts)->handle();

        // A price you cannot pay is not a price. Feeds routinely leave a stale
        // low figure on a listing that is sold out.
        $this->assertSame(AlertState::Active, $alert->fresh()->state);
    }

    #[Test]
    public function a_restock_alert_fires_when_the_product_returns(): void
    {
        $group = $this->group(inStock: false);
        $user = $this->user();

        $alert = RestockAlert::create([
            'group_id' => $group->id,
            'user_id' => $user->id,
            'state' => AlertState::Active->value,
        ]);

        (new RefreshWishlistedProducts)->handle();
        $this->assertSame(AlertState::Active, $alert->fresh()->state);

        $group->update(['in_stock' => true]);
        (new RefreshWishlistedProducts)->handle();

        $this->assertSame(AlertState::Triggered, $alert->fresh()->state);
        $this->assertSame('restock', Notification::query()->firstOrFail()->kind);
    }

    #[Test]
    public function stopping_an_alert_removes_both_kinds(): void
    {
        $group = $this->group();
        $user = $this->user();

        PriceAlert::create([
            'group_id' => $group->id,
            'user_id' => $user->id,
            'baseline_price' => 32999,
        ]);
        RestockAlert::create(['group_id' => $group->id, 'user_id' => $user->id]);

        // The UI presents one "stop watching" control, so it has to clear
        // everything behind it — otherwise the button appears not to work.
        $this->actingAs($user)->delete("/be-nl/alerts/{$group->id}")->assertRedirect();

        $this->assertSame(0, PriceAlert::query()->count());
        $this->assertSame(0, RestockAlert::query()->count());
    }

    #[Test]
    public function the_inbox_shows_notifications_and_clears_the_badge(): void
    {
        $user = $this->user();

        Notification::create([
            'user_id' => $user->id,
            'kind' => 'price_drop',
            'title' => 'Sony WH-1000XM5',
            'payload' => ['price' => 27999, 'baseline' => 32999],
        ]);

        $this->actingAs($user)
            ->get('/be-nl/notifications')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('notifications', 1)
                ->where('unreadCount', 1));

        $this->actingAs($user)->post('/be-nl/notifications/read')->assertRedirect();

        $this->assertNotNull(Notification::query()->firstOrFail()->read_at);
    }

    #[Test]
    public function you_cannot_see_someone_elses_notifications(): void
    {
        $other = User::create(['email' => 'other@example.test']);

        Notification::create([
            'user_id' => $other->id,
            'kind' => 'price_drop',
            'title' => 'Not yours',
        ]);

        $this->actingAs($this->user())
            ->get('/be-nl/notifications')
            ->assertInertia(fn ($page) => $page->has('notifications', 0));
    }
}
