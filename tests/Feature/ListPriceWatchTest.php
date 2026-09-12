<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\Availability;
use App\Enums\Market;
use App\Enums\ProductStatus;
use App\Enums\Source;
use App\Jobs\SendListPriceDigests;
use App\Mail\ListPriceDigestMail;
use App\Models\Merchant;
use App\Models\Notification;
use App\Models\Product;
use App\Models\ProductGroup;
use App\Models\User;
use App\Models\Wishlist;
use App\Models\WishlistItem;
use App\Services\Alerts\ListPriceWatch;
use App\Services\Wishlist\ItemSaver;
use App\Support\CurrentMarket;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Watching the prices on a whole list.
 *
 * Three things are being protected. The percentage is measured against a
 * reference that moves, so one drop is one mail and not one a morning until
 * the price recovers. A person gets one mail for all their lists. And, as with
 * every alert here, a source whose programme forbids price tracking can
 * neither seed a reference nor put a line in the mail.
 */
class ListPriceWatchTest extends TestCase
{
    use RefreshDatabase;

    private function group(int $minPrice = 32999): ProductGroup
    {
        return ProductGroup::create([
            'market' => Market::BeNl,
            'identity_key' => 'k'.bin2hex(random_bytes(4)),
            'identity_kind' => 'ean',
            'title' => 'Sony WH-1000XM5',
            'slug' => 'sony-wh-1000xm5',
            'min_price' => $minPrice,
            'merchant_count' => 1,
            'in_stock' => true,
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

    private function user(string $email = 'owner@example.test'): User
    {
        return User::create(['email' => $email]);
    }

    /** A list the user owns, with one item per group, watching at the given percentage. */
    private function watchedList(User $user, array $groups, ?int $percent = 10): Wishlist
    {
        $list = Wishlist::factory()->create(['owner_user_id' => $user->id, 'price_watch_percent' => null]);

        foreach ($groups as $group) {
            WishlistItem::factory()->create([
                'wishlist_id' => $list->id,
                'group_id' => $group->id,
                'snapshot_title' => $group->title,
                'snapshot_url' => "/be-nl/p/{$group->id}/{$group->slug}",
            ]);
        }

        if ($percent !== null) {
            $this->actingAs($user)->patch('/be-nl/lists/'.$list->id, ['price_watch_percent' => $percent]);
        }

        return $list->fresh();
    }

    private function runDigest(): void
    {
        (new SendListPriceDigests)->handle(app(ListPriceWatch::class));
    }

    #[Test]
    public function switching_it_on_seeds_every_item_at_the_cheapest_trackable_price(): void
    {
        $user = $this->user();
        $watched = $this->group();
        $this->offer($watched, Source::Awin, 32999);
        $this->offer($watched, Source::Awin, 29999);
        $amazonOnly = $this->group();
        $this->offer($amazonOnly, Source::Amazon, 19999);

        $list = $this->watchedList($user, [$watched, $amazonOnly], 15);

        $this->assertSame(15, $list->price_watch_percent);

        // The cheapest offer somebody could actually pay, not the aggregate.
        $this->assertSame(29999, $list->items()->where('group_id', $watched->id)->firstOrFail()->watch_reference_price);

        // COMPLIANCE: an Amazon-only product has no trackable price, so its
        // reference is null. It is seeded, though, so a later trackable offer
        // is reported as "available again" rather than treated as new.
        $amazonItem = $list->items()->where('group_id', $amazonOnly->id)->firstOrFail();
        $this->assertNull($amazonItem->watch_reference_price);
        $this->assertNotNull($amazonItem->watch_seeded_at);
    }

    #[Test]
    public function a_stranger_cannot_switch_it_on(): void
    {
        $list = Wishlist::factory()->create(['owner_user_id' => $this->user()->id]);

        $this->actingAs($this->user('stranger@example.test'))
            ->patch('/be-nl/lists/'.$list->id, ['price_watch_percent' => 10])
            ->assertNotFound();

        $this->assertNull($list->fresh()->price_watch_percent);
    }

    #[Test]
    public function only_the_offered_percentages_are_accepted(): void
    {
        $user = $this->user();
        $list = Wishlist::factory()->create(['owner_user_id' => $user->id]);

        $this->actingAs($user)
            ->from('/be-nl/lists/'.$list->id)
            ->patch('/be-nl/lists/'.$list->id, ['price_watch_percent' => 7])
            ->assertSessionHasErrors('price_watch_percent');

        $this->assertNull($list->fresh()->price_watch_percent);
    }

    #[Test]
    public function a_drop_of_at_least_the_percentage_is_mailed_once_and_moves_the_reference(): void
    {
        Mail::fake();
        $user = $this->user();
        $group = $this->group();
        $offer = $this->offer($group, Source::Awin, 32999);
        $list = $this->watchedList($user, [$group], 10);

        // 32999 → 29000 is an 12% drop.
        $offer->update(['price' => 29000]);
        $this->runDigest();

        Mail::assertSent(ListPriceDigestMail::class, function (ListPriceDigestMail $mail) use ($user, $list, $group): bool {
            $section = $mail->sections[0];

            return $mail->hasTo($user->email)
                && count($mail->sections) === 1
                && $section['title'] === $list->displayTitle('nl')
                && count($section['drops']) === 1
                && $section['drops'][0]['was'] === 32999
                && $section['drops'][0]['now'] === 29000
                && $section['drops'][0]['percent'] === 12
                && str_contains($section['drops'][0]['url'], "/be-nl/p/{$group->id}/");
        });

        $notice = Notification::query()->where('kind', 'list_price_digest')->firstOrFail();
        $this->assertSame($user->id, $notice->user_id);
        $this->assertSame(1, $notice->payload['count']);
        $this->assertSame("/be-nl/lists/{$list->id}", $notice->url);

        // The reference moved, so tomorrow's pass says nothing about the same drop.
        $this->assertSame(29000, $list->items()->firstOrFail()->watch_reference_price);
        $this->runDigest();
        Mail::assertSent(ListPriceDigestMail::class, 1);
    }

    #[Test]
    public function a_smaller_drop_is_not_reported(): void
    {
        Mail::fake();
        $group = $this->group();
        $offer = $this->offer($group, Source::Awin, 32999);
        $this->watchedList($this->user(), [$group], 10);

        // 6% off: under the 10% the owner asked for.
        $offer->update(['price' => 31000]);
        $this->runDigest();

        Mail::assertNothingSent();
        $this->assertSame(0, Notification::query()->count());
    }

    #[Test]
    public function a_rise_moves_the_reference_up_so_the_next_drop_is_measured_from_there(): void
    {
        Mail::fake();
        $group = $this->group();
        $offer = $this->offer($group, Source::Awin, 20000);
        $list = $this->watchedList($this->user(), [$group], 10);

        // Up to 30000: silent, but remembered.
        $offer->update(['price' => 30000]);
        $this->runDigest();
        Mail::assertNothingSent();
        $this->assertSame(30000, $list->items()->firstOrFail()->watch_reference_price);

        // Back to 25000: 17% under the new reference, though still dearer
        // than the day the watch started. That is the drop a person sees.
        $offer->update(['price' => 25000]);
        $this->runDigest();
        Mail::assertSent(ListPriceDigestMail::class, 1);
    }

    #[Test]
    public function an_item_that_gains_a_trackable_price_is_reported_as_available_again(): void
    {
        Mail::fake();
        $group = $this->group();
        $offer = $this->offer($group, Source::Awin, 32999, inStock: false);
        $list = $this->watchedList($this->user(), [$group], 10);

        $this->assertNull($list->items()->firstOrFail()->watch_reference_price);

        $offer->update(['availability' => Availability::InStock]);
        $this->runDigest();

        Mail::assertSent(ListPriceDigestMail::class, function (ListPriceDigestMail $mail): bool {
            $section = $mail->sections[0];

            return $section['drops'] === []
                && count($section['back']) === 1
                && $section['back'][0]['now'] === 32999;
        });
        $this->assertSame(32999, $list->items()->firstOrFail()->watch_reference_price);
    }

    #[Test]
    public function a_drop_at_a_source_that_forbids_tracking_does_not_count(): void
    {
        Mail::fake();
        $group = $this->group();
        $this->offer($group, Source::Awin, 32999);
        $amazon = $this->offer($group, Source::Amazon, 30000);
        $list = $this->watchedList($this->user(), [$group], 10);

        // COMPLIANCE. Amazon at 19999 is a 39% drop nobody may be told about.
        $amazon->update(['price' => 19999]);
        $this->runDigest();

        Mail::assertNothingSent();
        $this->assertSame(32999, $list->items()->firstOrFail()->watch_reference_price);
    }

    #[Test]
    public function two_watched_lists_make_one_mail_and_two_notifications(): void
    {
        Mail::fake();
        $user = $this->user();
        $first = $this->group();
        $firstOffer = $this->offer($first, Source::Awin, 10000);
        $second = $this->group();
        $secondOffer = $this->offer($second, Source::Awin, 20000);
        $this->watchedList($user, [$first], 10);
        $this->watchedList($user, [$second], 10);

        $firstOffer->update(['price' => 8000]);
        $secondOffer->update(['price' => 15000]);
        $this->runDigest();

        Mail::assertSent(ListPriceDigestMail::class, 1);
        Mail::assertSent(ListPriceDigestMail::class, fn (ListPriceDigestMail $mail) => count($mail->sections) === 2);
        $this->assertSame(2, Notification::query()->where('kind', 'list_price_digest')->count());
    }

    #[Test]
    public function a_list_that_is_not_watching_is_left_alone(): void
    {
        Mail::fake();
        $group = $this->group();
        $offer = $this->offer($group, Source::Awin, 32999);
        $list = $this->watchedList($this->user(), [$group], percent: null);

        $offer->update(['price' => 10000]);
        $this->runDigest();

        Mail::assertNothingSent();
        $this->assertNull($list->items()->firstOrFail()->watch_seeded_at);
    }

    #[Test]
    public function switching_it_off_forgets_the_references(): void
    {
        $user = $this->user();
        $group = $this->group();
        $this->offer($group, Source::Awin, 32999);
        $list = $this->watchedList($user, [$group], 10);

        $this->assertNotNull($list->items()->firstOrFail()->watch_seeded_at);

        $this->actingAs($user)->patch('/be-nl/lists/'.$list->id, ['price_watch_percent' => null]);

        $item = $list->items()->firstOrFail();
        $this->assertNull($list->fresh()->price_watch_percent);
        $this->assertNull($item->watch_reference_price);
        $this->assertNull($item->watch_seeded_at);
    }

    #[Test]
    public function a_product_saved_onto_a_watching_list_is_seeded_on_save(): void
    {
        $user = $this->user();
        $list = $this->watchedList($user, [], 10);
        $group = $this->group();
        $this->offer($group, Source::Awin, 24999);

        $item = app(ItemSaver::class)->saveGroup($list, $group, new CurrentMarket(Market::BeNl));

        // From today's price, not tomorrow morning's: a drop in between would
        // otherwise be measured from nothing.
        $this->assertSame(24999, $item->watch_reference_price);
        $this->assertNotNull($item->watch_seeded_at);
    }
}
