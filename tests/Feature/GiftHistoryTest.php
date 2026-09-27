<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\ListKind;
use App\Enums\Market;
use App\Models\ProductGroup;
use App\Models\Recipient;
use App\Models\RecipientGift;
use App\Models\User;
use App\Models\Wishlist;
use App\Models\WishlistItem;
use App\Services\Ai\AiClient;
use App\Services\Gift\GiftHistory;
use App\Services\Gift\PastGift;
use App\Services\Identity\GroupMerger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Gift history per person (docs/features/gift-history.md): what a giver noted
 * and their own claims, never anybody else's; never suggested again in the
 * Find a gift or This or that; and the next step after it.
 */
class GiftHistoryTest extends TestCase
{
    use RefreshDatabase;

    private User $giver;

    private Recipient $mum;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();

        // Invariant 1: nothing here may reach the model.
        $this->mock(AiClient::class, function ($mock): void {
            $mock->shouldNotReceive('json');
            $mock->shouldNotReceive('chat');
        });

        $this->giver = User::factory()->create();
        $this->mum = Recipient::factory()->create([
            'owner_user_id' => $this->giver->id,
            'name' => 'Mum',
            'interests' => ['cooking'],
        ]);
    }

    private function cooking(string $title = '', array $extra = []): ProductGroup
    {
        return ProductGroup::factory()->priced(3000)->create([
            'gift_tags' => ['interest:cooking'],
            'title' => $title !== '' ? $title : 'Kookgerei '.Str::random(6),
            ...$extra,
        ]);
    }

    private function listFor(Recipient $recipient, ?User $owner = null, ListKind $kind = ListKind::ForSomeone): Wishlist
    {
        return Wishlist::factory()->create([
            'owner_user_id' => ($owner ?? $this->giver)->id,
            'recipient_id' => $kind === ListKind::Mine ? null : $recipient->id,
            'kind' => $kind,
            'market' => Market::BeNl,
        ]);
    }

    private function claimedBy(User $user): string
    {
        return WishlistItem::identityHash('user:'.$user->id);
    }

    // ── What the history holds ─────────────────────────────────────────────

    #[Test]
    public function it_holds_what_was_noted_and_the_givers_own_claims_and_nobody_elses(): void
    {
        $sister = User::factory()->create();

        // The giver's own list about Mum: one item the giver claimed, one a
        // co-giver (the sister) claimed.
        $list = $this->listFor($this->mum);
        $mine = WishlistItem::factory()->create(['wishlist_id' => $list->id, 'claimed_by_hash' => $this->claimedBy($this->giver), 'claimed_at' => now()]);
        $hers = WishlistItem::factory()->create(['wishlist_id' => $list->id, 'claimed_by_hash' => $this->claimedBy($sister), 'claimed_at' => now()]);

        // Mum's own wish list, once she is linked: the giver bought one thing
        // off it, the sister another.
        $mumAccount = User::factory()->create();
        $this->mum->update(['user_id' => $mumAccount->id, 'status' => 'linked']);
        $wishList = $this->listFor($this->mum, $mumAccount, ListKind::Mine);
        $fromWish = WishlistItem::factory()->create([
            'wishlist_id' => $wishList->id,
            'claimed_by_hash' => $this->claimedBy($this->giver),
            'claimed_at' => now(),
            'marked_sent_at' => now(),
        ]);
        $herWish = WishlistItem::factory()->create(['wishlist_id' => $wishList->id, 'claimed_by_hash' => $this->claimedBy($sister), 'claimed_at' => now()]);

        $this->mum->gifts()->create(['title' => 'A cookbook', 'given_year' => 2024]);

        $history = app(GiftHistory::class)->for($this->mum->fresh());
        $groups = array_map(fn (PastGift $g) => $g->groupId, $history);

        $this->assertContains($mine->group_id, $groups);
        $this->assertContains($fromWish->group_id, $groups, 'Buying off her own wish list is a gift to her.');
        $this->assertNotContains($hers->group_id, $groups, 'Somebody else\'s claim is never read (invariant 4).');
        $this->assertNotContains($herWish->group_id, $groups);
        $this->assertContains('A cookbook', array_map(fn (PastGift $g) => $g->title, $history));

        $sources = collect($history)->keyBy('groupId');
        $this->assertSame(PastGift::CLAIMED, $sources[$mine->group_id]->source);
        $this->assertSame(PastGift::SENT, $sources[$fromWish->group_id]->source);
    }

    #[Test]
    public function the_page_shows_only_the_givers_own_claims_and_says_nothing_about_other_items(): void
    {
        $sister = User::factory()->create();
        $list = $this->listFor($this->mum);
        $mine = WishlistItem::factory()->create(['wishlist_id' => $list->id, 'claimed_by_hash' => $this->claimedBy($this->giver), 'claimed_at' => now()]);
        $hers = WishlistItem::factory()->create(['wishlist_id' => $list->id, 'claimed_by_hash' => $this->claimedBy($sister), 'claimed_at' => now()]);
        $open = WishlistItem::factory()->create(['wishlist_id' => $list->id]);

        $response = $this->actingAs($this->giver)->get("/be-nl/people/{$this->mum->id}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Recipients/Show')->where('person.name', 'Mum'));

        $props = $response->viewData('page')['props'];

        $this->assertSame([$mine->group_id], array_column($props['history'], 'groupId'));

        // The sister's claim and the open item look exactly alike.
        $unmarked = collect($props['unmarked']);
        $this->assertEqualsCanonicalizing([$hers->id, $open->id], $unmarked->pluck('id')->all());
        $this->assertSame(['id', 'title', 'image'], array_keys($unmarked->first()));
        $this->assertStringNotContainsString('claim', json_encode($props['unmarked']));
    }

    #[Test]
    public function only_the_owner_opens_the_page(): void
    {
        $this->get("/be-nl/people/{$this->mum->id}")->assertRedirect('/be-nl/login');
        $this->actingAs(User::factory()->create())->get("/be-nl/people/{$this->mum->id}")->assertNotFound();
    }

    #[Test]
    public function i_gave_this_from_their_list_and_by_hand_and_it_can_be_removed(): void
    {
        $list = $this->listFor($this->mum);
        $item = WishlistItem::factory()->create(['wishlist_id' => $list->id]);

        $this->actingAs($this->giver)->post("/be-nl/people/{$this->mum->id}/gifts", ['item_id' => $item->id])->assertRedirect();
        $this->actingAs($this->giver)->post("/be-nl/people/{$this->mum->id}/gifts", ['item_id' => $item->id])->assertRedirect();

        $this->assertSame(1, RecipientGift::query()->where('wishlist_item_id', $item->id)->count(), 'Twice is one line.');
        $this->assertSame($item->group_id, RecipientGift::query()->where('wishlist_item_id', $item->id)->value('group_id'));

        $this->actingAs($this->giver)->post("/be-nl/people/{$this->mum->id}/gifts", ['title' => 'Moka pot', 'year' => 2025])->assertRedirect();
        $noted = RecipientGift::query()->where('title', 'Moka pot')->firstOrFail();
        $this->assertSame(2025, $noted->given_year);

        $this->actingAs($this->giver)->delete("/be-nl/people/{$this->mum->id}/gifts/{$noted->id}")->assertRedirect();
        $this->assertModelMissing($noted);
    }

    #[Test]
    public function an_item_from_somebody_elses_list_cannot_be_marked(): void
    {
        $stranger = User::factory()->create();
        $theirs = Wishlist::factory()->create(['owner_user_id' => $stranger->id]);
        $item = WishlistItem::factory()->create(['wishlist_id' => $theirs->id]);

        $this->actingAs($this->giver)
            ->post("/be-nl/people/{$this->mum->id}/gifts", ['item_id' => $item->id])
            ->assertNotFound();

        // Nor can somebody else write to Mum's history.
        $this->actingAs($stranger)
            ->post("/be-nl/people/{$this->mum->id}/gifts", ['title' => 'Socks'])
            ->assertNotFound();

        $this->assertSame(0, RecipientGift::query()->count());
    }

    #[Test]
    public function a_line_goes_once_its_year_is_ten_years_back(): void
    {
        $year = (int) now()->year;
        $old = $this->mum->gifts()->create(['title' => 'Old', 'given_year' => $year - 11]);
        $kept = $this->mum->gifts()->create(['title' => 'Kept', 'given_year' => $year - 10]);

        $this->artisan('bc:prune-personal-data')->assertSuccessful();

        $this->assertModelMissing($old);
        $this->assertModelExists($kept);
    }

    // ── Never the same thing again ─────────────────────────────────────────

    #[Test]
    public function the_gift_finder_never_suggests_what_she_was_given_or_its_merged_twin(): void
    {
        $given = $this->cooking('Gietijzeren braadpan');
        $twin = $this->cooking('Braadpan gietijzer 24 cm');
        $others = collect(range(1, 5))->map(fn () => $this->cooking());

        // Before anything is recorded, both show.
        $before = $this->picks(['recipient_id' => $this->mum->id]);
        $this->assertContains($given->id, $before);
        $this->assertContains($twin->id, $before);

        $this->mum->gifts()->create(['title' => 'Braadpan', 'group_id' => $given->id, 'given_year' => 2025]);

        // Later found to be the same product as the twin, which is kept. Set by
        // hand: a real merge also zeroes both products' offers here, since the
        // factory gives them none, and the twin would vanish for that reason.
        $given->update(['merged_into_id' => $twin->id]);

        $after = $this->picks(['recipient_id' => $this->mum->id]);
        $this->assertNotContains($given->id, $after);
        $this->assertNotContains($twin->id, $after, 'The product it was merged into is the same gift.');
        $this->assertNotEmpty(array_intersect($others->pluck('id')->all(), $after));

        // Somebody else describing the same taste is not affected.
        $this->assertContains($twin->id, $this->picks(['interests' => ['cooking']]));
    }

    #[Test]
    public function a_merge_moves_the_history_to_the_product_that_is_kept(): void
    {
        $given = $this->cooking();
        $kept = $this->cooking();
        $line = $this->mum->gifts()->create(['title' => 'Pan', 'group_id' => $given->id, 'given_year' => 2025]);

        app(GroupMerger::class)->merge($given, $kept);

        $this->assertSame($kept->id, $line->fresh()->group_id);
    }

    #[Test]
    public function opening_the_finder_for_a_person_shows_their_ideas_without_past_gifts(): void
    {
        $given = $this->cooking();
        $fresh = $this->cooking();
        $this->mum->gifts()->create(['title' => 'Given', 'group_id' => $given->id, 'given_year' => 2025]);

        $response = $this->actingAs($this->giver)->get("/be-nl/gift?for={$this->mum->id}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Gift/Wizard')->where('brief.recipient_id', $this->mum->id));

        $props = $response->viewData('page')['props'];
        $ids = array_column($props['picks'], 'id');

        $this->assertContains($fresh->id, $ids);
        $this->assertNotContains($given->id, $ids);
        $this->assertSame("/be-nl/people/{$this->mum->id}", $props['personUrl']);

        // Somebody else's person id opens the empty wizard.
        $this->actingAs(User::factory()->create())->get("/be-nl/gift?for={$this->mum->id}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('picks', null));
    }

    #[Test]
    public function this_or_that_for_a_person_leaves_out_what_they_were_given(): void
    {
        $shown = [];
        $choices = [];

        foreach (range(0, 2) as $i) {
            $picked = $this->cooking();
            $other = ProductGroup::factory()->priced(3000)->create(['gift_tags' => ['interest:gaming']]);
            $choices[] = ['shown' => [$picked->id, $other->id], 'picked' => $picked->id];
        }

        $given = $this->cooking();
        $fresh = $this->cooking();
        $this->mum->gifts()->create(['title' => 'Given', 'group_id' => $given->id, 'given_year' => 2025]);

        $ideas = fn (array $extra) => array_column(
            $this->actingAs($this->giver)->post('/be-nl/gift/taste', ['choices' => $choices, 'for' => 'someone', ...$extra])
                ->assertOk()->viewData('page')['props']['result']['picks'],
            'id',
        );

        $this->assertContains($given->id, $ideas([]), 'Without a person, nothing is left out.');

        $forMum = $ideas(['recipient_id' => $this->mum->id]);
        $this->assertNotContains($given->id, $forMum);
        $this->assertContains($fresh->id, $forMum);
    }

    // ── The next step ──────────────────────────────────────────────────────

    #[Test]
    public function the_next_step_follows_on_from_a_past_gift(): void
    {
        $moka = ProductGroup::factory()->priced(3500)->create([
            'title' => 'Bialetti Moka Express 3 kops',
            'brand' => 'Bialetti',
            'category' => 'Koffiezetters',
        ]);
        $this->mum->gifts()->create(['title' => 'Moka pot', 'group_id' => $moka->id, 'given_year' => (int) now()->year - 1]);

        $beans = ProductGroup::factory()->priced(1500)->create(['title' => 'Lavazza Koffiebonen 1 kg', 'brand' => 'Lavazza', 'category' => 'Koffie']);
        // Coffee beans are often not classed a gift on their own; after a moka pot they are one.
        $beans->update(['giftable' => false]);
        $frother = ProductGroup::factory()->priced(4000)->create(['title' => 'Bialetti melkopschuimer', 'brand' => 'Bialetti', 'category' => 'Keuken']);
        $linked = ProductGroup::factory()->priced(2000)->create(['title' => 'Amaretti koekjes', 'brand' => 'Lazzaroni', 'category' => 'Koek']);
        $speaker = ProductGroup::factory()->priced(4000)->create(['title' => 'Bluetooth speaker', 'brand' => 'JBL', 'category' => 'Audio']);
        $sixCup = ProductGroup::factory()->priced(4500)->create(['title' => 'Bialetti Moka Express 6 kops', 'brand' => 'Bialetti', 'category' => 'Koffiezetters']);

        DB::table('product_links')->insert([
            'group_a' => min($moka->id, $linked->id),
            'group_b' => max($moka->id, $linked->id),
            'market' => 'be-nl',
            'owners' => 9,
        ]);

        $steps = collect($this->actingAs($this->giver)->get("/be-nl/people/{$this->mum->id}")
            ->assertOk()->viewData('page')['props']['nextSteps'])->keyBy('id');

        $this->assertSame('goes_with', $steps[$beans->id]['reason'] ?? null);
        $this->assertSame($moka->displayTitle(), $steps[$beans->id]['after']);
        $this->assertArrayHasKey($frother->id, $steps->all());
        $this->assertSame('often_together', $steps[$linked->id]['reason'] ?? null);
        $this->assertArrayNotHasKey($speaker->id, $steps->all());
        $this->assertArrayNotHasKey($sixCup->id, $steps->all(), 'Another moka pot is the same gift again.');
        $this->assertArrayNotHasKey($moka->id, $steps->all());

        // And Find a gift shows the same row when she is chosen.
        $finder = $this->actingAs($this->giver)->post('/be-nl/gift', ['recipient_id' => $this->mum->id])
            ->assertOk()->viewData('page')['props'];
        $this->assertContains($beans->id, array_column($finder['nextSteps'], 'id'));
    }

    #[Test]
    public function the_next_step_keeps_to_her_budget(): void
    {
        $moka = ProductGroup::factory()->priced(3500)->create(['title' => 'Moka pot', 'brand' => 'Bialetti']);
        $this->mum->update(['budget_max' => 2000]);
        $this->mum->gifts()->create(['title' => 'Moka pot', 'group_id' => $moka->id, 'given_year' => (int) now()->year]);

        $cheap = ProductGroup::factory()->priced(1500)->create(['title' => 'Koffiebonen', 'brand' => 'Illy']);
        $dear = ProductGroup::factory()->priced(9000)->create(['title' => 'Elektrische grinder', 'brand' => 'Sage']);

        $ids = array_column($this->actingAs($this->giver)->get("/be-nl/people/{$this->mum->id}")
            ->viewData('page')['props']['nextSteps'], 'id');

        $this->assertContains($cheap->id, $ids);
        $this->assertNotContains($dear->id, $ids);
    }

    #[Test]
    public function the_next_steps_are_worked_out_once_and_again_when_what_goes_in_changes(): void
    {
        // Kept an hour (speed wave 2): a second view does not fetch the
        // candidates again, and a new past gift, a new budget or an item now on
        // her list is a different key, so the row follows at once.
        $moka = ProductGroup::factory()->priced(3500)->create(['title' => 'Moka pot', 'brand' => 'Bialetti']);
        $this->mum->gifts()->create(['title' => 'Moka pot', 'group_id' => $moka->id, 'given_year' => (int) now()->year]);
        $cheap = ProductGroup::factory()->priced(1500)->create(['title' => 'Koffiebonen', 'brand' => 'Illy']);
        $dear = ProductGroup::factory()->priced(9000)->create(['title' => 'Elektrische grinder', 'brand' => 'Sage']);

        $ids = fn (): array => array_column($this->actingAs($this->giver)->get("/be-nl/people/{$this->mum->id}")
            ->viewData('page')['props']['nextSteps'], 'id');
        $fetches = function (callable $view): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $view();
            $n = collect(DB::getQueryLog())->filter(fn ($q) => str_contains($q['query'], 'product_links'))->count();
            DB::disableQueryLog();

            return $n;
        };

        $first = $ids();
        $this->assertContains($dear->id, $first);
        $this->assertSame(0, $fetches($ids), 'the second view fetched the candidates again');
        $this->assertSame($first, $ids());

        $this->mum->update(['budget_max' => 2000]);
        $this->assertNotContains($dear->id, $ids());
        $this->assertContains($cheap->id, $ids());
    }

    /**
     * @param  array<string, mixed>  $brief
     * @return list<int>
     */
    private function picks(array $brief): array
    {
        return array_column(
            $this->actingAs($this->giver)->post('/be-nl/gift', $brief)->assertOk()->viewData('page')['props']['picks'],
            'id',
        );
    }
}
