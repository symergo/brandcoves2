<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\Availability;
use App\Enums\ListKind;
use App\Enums\Market;
use App\Enums\ProductStatus;
use App\Enums\Source;
use App\Jobs\CountListSignals;
use App\Models\Merchant;
use App\Models\Product;
use App\Models\ProductGroup;
use App\Models\Recipient;
use App\Models\User;
use App\Models\Wishlist;
use App\Models\WishlistItem;
use App\Services\Gift\SuggestionEngine;
use App\Services\Gift\TasteBrief;
use App\Services\Search\GiftIntentParser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * What people's lists teach the catalogue (roadmap step 4, engine F and G).
 * The rules held here: people not lists, nothing below the threshold, claims
 * and unaccepted suggestions never counted, editors' tags never touched, and
 * links inside one market.
 */
class ListSignalsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    #[Test]
    public function a_product_earns_a_tag_once_enough_different_people_agree(): void
    {
        $knife = $this->giftable('Koksmes', 4000);

        // Four people: below the threshold of five.
        foreach (range(1, 4) as $i) {
            $this->listFor($knife, relationship: 'father');
        }
        $this->countSignals();
        $this->assertSame([], $knife->fresh()->crowdTags());

        // One person with a second list is still four people.
        $this->listFor($knife, relationship: 'father', owner: User::query()->first());
        $this->countSignals();
        $this->assertSame([], $knife->fresh()->crowdTags());

        $this->listFor($knife, relationship: 'father');
        $this->countSignals();
        $this->assertContains('recipient:father', $knife->fresh()->crowdTags());
        $this->assertSame([], $knife->fresh()->giftTags(), 'An editor\'s tags are never touched.');
    }

    #[Test]
    public function a_lists_title_says_what_it_is_for(): void
    {
        $candle = $this->giftable('Geurkaars', 1500);

        foreach (range(1, 5) as $i) {
            $this->listFor($candle, title: 'Kerst voor oma');
        }

        $this->countSignals();

        $this->assertContains('occasion:christmas', $candle->fresh()->crowdTags());
        // "oma" is a grandmother since the gender split (2026-09-29); a search
        // for either grandparent still finds it (RecipientType::family()).
        $this->assertContains('recipient:grandmother', $candle->fresh()->crowdTags());
    }

    #[Test]
    public function products_on_one_list_share_what_its_tagged_products_say(): void
    {
        // Two products an editor tagged for cooking on one list make it a
        // cooking list; the untagged apron beside them learns that.
        $pan = $this->giftable('Pan', 4000, ['interest:cooking']);
        $knife = $this->giftable('Mes', 3000, ['interest:cooking']);
        $apron = $this->giftable('Schort', 2000);

        foreach (range(1, 5) as $i) {
            $list = $this->listFor($pan, title: 'Voor Tom');
            $this->keep($list, $knife);
            $this->keep($list, $apron);
        }

        $this->countSignals();

        $this->assertContains('interest:cooking', $apron->fresh()->crowdTags());
    }

    #[Test]
    public function a_suggestion_nobody_accepted_does_not_count(): void
    {
        $knife = $this->giftable('Koksmes', 4000);

        foreach (range(1, 5) as $i) {
            $list = $this->listFor($knife, relationship: 'father');
            $list->allItems()->update(['accepted_at' => null]);
        }

        $this->countSignals();

        $this->assertSame([], $knife->fresh()->crowdTags());
    }

    #[Test]
    public function products_on_the_same_lists_are_linked_within_one_market(): void
    {
        $a = $this->giftable('A', 1000);
        $b = $this->giftable('B', 1000);
        $elsewhere = $this->giftable('C', 1000, market: Market::NlNl);

        foreach (range(1, 5) as $i) {
            $list = $this->listFor($a, kind: ListKind::Mine);
            $this->keep($list, $b);
            $this->keep($list, $elsewhere);
        }

        $this->countSignals();

        $this->assertSame(1, DB::table('product_links')->count(), 'Only A and B: C is another market.');
        $this->assertSame(5, (int) DB::table('product_links')->value('owners'));

        $this->get("/be-nl/p/{$a->id}/{$a->slug}")
            ->assertInertia(fn ($page) => $page->where('signals.alsoOn.0.id', $b->id));
    }

    #[Test]
    public function the_engine_finds_a_product_by_what_lists_taught_it(): void
    {
        $apron = $this->giftable('Schort', 2000);
        $apron->update(['crowd_tags' => ['interest:cooking']]);
        $this->giftable('Wasmachine', 39999);

        $picks = app(SuggestionEngine::class)->suggest(new TasteBrief(market: Market::BeNl, interests: ['cooking']));

        $this->assertSame([$apron->id], array_map(fn ($p) => $p->group->id, $picks));
    }

    #[Test]
    public function a_wish_list_is_the_brief_for_a_gift_for_its_owner(): void
    {
        $owner = User::factory()->create();
        $grinder = $this->giftable('Koffiemolen', 4000, ['interest:coffee']);
        $list = $this->listFor($grinder, kind: ListKind::Mine, owner: $owner);
        $list->update(['visibility' => 'link']);

        $beans = $this->giftable('Koffiebonen', 2500, ['interest:coffee']);

        $brief = TasteBrief::fromList($list->fresh());
        $this->assertSame(['coffee'], $brief->interests);
        $this->assertSame([$grinder->id], $brief->excludeGroupIds, 'What is on the list is shown first, not suggested again.');

        $token = $list->fresh()->share_token;

        // A visitor sees ideas in the same spirit; the owner never does.
        $this->get("/be-nl/l/{$token}")
            ->assertInertia(fn ($page) => $page->where('likeThis.0.id', $beans->id));

        Cache::flush();
        $this->actingAs($owner)->get("/be-nl/l/{$token}")
            ->assertInertia(fn ($page) => $page->where('likeThis', []));
    }

    #[Test]
    public function a_list_title_is_read_as_a_gift_even_without_gift_words(): void
    {
        $parsed = app(GiftIntentParser::class)->parse('Tuinieren', Market::BeNl, giftContext: true);

        $this->assertSame(['gardening'], $parsed->interests);
    }

    private function countSignals(): void
    {
        (new CountListSignals)->handle(app(GiftIntentParser::class));
    }

    private function listFor(
        ProductGroup $group,
        ?string $relationship = null,
        string $title = 'Een lijst',
        ListKind $kind = ListKind::ForSomeone,
        ?User $owner = null,
    ): Wishlist {
        $owner ??= User::factory()->create();

        $recipient = $relationship === null ? null : Recipient::query()->create([
            'owner_user_id' => $owner->id,
            'name' => 'Iemand',
            'relationship' => $relationship,
        ]);

        $list = Wishlist::create([
            'owner_user_id' => $owner->id,
            'title' => $title,
            'market' => Market::BeNl,
            'kind' => $kind,
            'visibility' => 'private',
            'recipient_id' => $recipient?->id,
        ]);

        $this->keep($list, $group);

        return $list;
    }

    private function keep(Wishlist $list, ProductGroup $group): void
    {
        WishlistItem::query()->create([
            'wishlist_id' => $list->id,
            'group_id' => $group->id,
            'snapshot_title' => $group->title,
            'accepted_at' => now(),
        ]);
    }

    /** @param list<string> $tags */
    private function giftable(string $title, int $price, array $tags = [], Market $market = Market::BeNl): ProductGroup
    {
        $merchant = Merchant::query()->firstOrCreate(
            ['source' => Source::Awin->value, 'external_id' => 'shop'],
            ['name' => 'Shop'],
        );

        $group = ProductGroup::create([
            'market' => $market,
            'identity_key' => 'k'.bin2hex(random_bytes(5)),
            'identity_kind' => 'ean',
            'title' => $title,
            'slug' => 'p-'.bin2hex(random_bytes(3)),
            'category' => 'Divers',
            'image_url' => 'https://img.test/x.jpg',
            'min_price' => $price,
            'merchant_count' => 1,
            'in_stock' => true,
            'giftable' => true,
            'gift_tags' => $tags,
        ]);

        Product::create([
            'source' => Source::Awin,
            'market' => $market,
            'merchant_id' => $merchant->id,
            'group_id' => $group->id,
            'external_id' => 'e'.bin2hex(random_bytes(5)),
            'identity_kind' => 'ean',
            'title' => $title,
            'merchant_category' => 'Divers',
            'price' => $price,
            'currency' => 'EUR',
            'affiliate_url' => 'https://example.test/buy',
            'availability' => Availability::InStock,
            'status' => ProductStatus::Active,
            'identity_key' => $group->identity_key,
        ]);

        return $group;
    }
}
