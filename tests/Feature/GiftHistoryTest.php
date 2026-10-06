<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\ListKind;
use App\Enums\Market;
use App\Models\ProductGroup;
use App\Models\Recipient;
use App\Models\User;
use App\Models\Wishlist;
use App\Models\WishlistItem;
use App\Services\Ai\AiClient;
use App\Services\Gift\GiftHistory;
use App\Services\Gift\PastGift;
use App\Services\Identity\GroupMerger;
use DateTimeInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Gift history per person (docs/features/gift-history.md): the giver's own
 * claims, never anybody else's; never suggested again in Find a gift or This
 * or that; and the next step after it. "Wat je gaf" (gifts noted by hand or
 * with "I gave this") was removed on 2026-09-29.
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

    /**
     * Mum was given this product: the giver's own claim ("Ik koop dit") on
     * the giver's list about her. Since "Wat je gaf" went (2026-09-29) a
     * claim is the only way a past gift is recorded.
     */
    private function gave(ProductGroup $group, ?DateTimeInterface $when = null): WishlistItem
    {
        return WishlistItem::factory()->of($group)->create([
            'wishlist_id' => $this->listFor($this->mum)->id,
            'claimed_by_hash' => $this->claimedBy($this->giver),
            'claimed_at' => $when ?? now(),
        ]);
    }

    #[Test]
    public function it_holds_the_givers_own_claims_and_nobody_elses(): void
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

        $history = app(GiftHistory::class)->for($this->mum->fresh());
        $groups = array_map(fn (PastGift $g) => $g->groupId, $history);

        $this->assertContains($mine->group_id, $groups);
        $this->assertContains($fromWish->group_id, $groups, 'Buying off her own wish list is a gift to her.');
        $this->assertNotContains($hers->group_id, $groups, 'Somebody else\'s claim is never read (invariant 4).');
        $this->assertNotContains($herWish->group_id, $groups);
        $this->assertCount(2, $history);

        $sources = collect($history)->keyBy('groupId');
        $this->assertSame(PastGift::CLAIMED, $sources[$mine->group_id]->source);
        $this->assertSame(PastGift::SENT, $sources[$fromWish->group_id]->source);
    }

    #[Test]
    public function the_page_no_longer_lists_what_was_given_nor_offers_to_note_it(): void
    {
        // "Wat je gaf" and "Op je lijsten voor ..." were removed on
        // 2026-09-29. The claims behind them still count (the tests below);
        // the page just stops listing them, and "I gave this" is gone.
        $this->gave($this->cooking());

        $response = $this->actingAs($this->giver)->get("/be-nl/people/{$this->mum->id}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Recipients/Show')->where('person.name', 'Mum'));

        $props = $response->viewData('page')['props'];

        $this->assertArrayNotHasKey('history', $props);
        $this->assertArrayNotHasKey('unmarked', $props);
        $this->assertArrayNotHasKey('gifts', $props['urls']);

        $this->assertFalse(Route::has('people.gifts.store'));
        $this->assertFalse(Route::has('people.gifts.destroy'));
    }

    #[Test]
    public function only_the_owner_opens_the_page(): void
    {
        $this->get("/be-nl/people/{$this->mum->id}")->assertRedirect('/be-nl/login');
        $this->actingAs(User::factory()->create())->get("/be-nl/people/{$this->mum->id}")->assertNotFound();
    }

    #[Test]
    public function a_noted_line_left_from_before_goes_once_its_year_is_ten_years_back(): void
    {
        // Nothing writes `recipient_gifts` since 2026-09-29, but the rows
        // noted before then are personal data until the table is dropped, so
        // the privacy cleanup keeps pruning them.
        $year = (int) now()->year;
        $line = fn (string $title, int $given) => DB::table('recipient_gifts')->insertGetId([
            'recipient_id' => $this->mum->id,
            'title' => $title,
            'given_year' => $given,
        ]);
        $old = $line('Old', $year - 11);
        $kept = $line('Kept', $year - 10);

        $this->artisan('bc:prune-personal-data')->assertSuccessful();

        $this->assertDatabaseMissing('recipient_gifts', ['id' => $old]);
        $this->assertDatabaseHas('recipient_gifts', ['id' => $kept]);
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

        $this->gave($given);

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
    public function after_a_merge_the_product_that_is_kept_stays_left_out(): void
    {
        $given = $this->cooking();
        $kept = $this->cooking();
        $this->gave($given);

        app(GroupMerger::class)->merge($given, $kept);

        $this->assertContains($kept->id, app(GiftHistory::class)->excludedGroupIds($this->mum->fresh()));
    }

    #[Test]
    public function opening_the_finder_for_a_person_shows_their_ideas_without_past_gifts(): void
    {
        $given = $this->cooking();
        $fresh = $this->cooking();
        $this->gave($given);

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
    public function starting_from_a_person_opens_the_wizard_with_them_chosen(): void
    {
        /*
         * "Cadeau vinden" on a person's page went to `?for=`, straight to
         * eight ideas, with no way to swipe (owner, 2026-10-05). `?person=`
         * opens the wizard with them chosen instead; the page starts on the
         * ways step.
         */
        $this->actingAs($this->giver)->get("/be-nl/gift?person={$this->mum->id}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Gift/Wizard')
                ->where('startWith', $this->mum->id)
                ->where('picks', null)
                ->where('brief', null));

        // Somebody else's person id opens the plain wizard, chosen for nobody.
        $this->actingAs(User::factory()->create())->get("/be-nl/gift?person={$this->mum->id}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('startWith', null));
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
        $this->gave($given);

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
        $this->gave($moka, now()->subYear());

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
    public function the_ideas_start_from_what_is_on_her_lists_bought_or_not(): void
    {
        // "Geïnspireerd op hun lijsten" (owner, 2026-10-06): a list with
        // nothing bought yet is enough; the same category counts too.
        $moka = ProductGroup::factory()->priced(3500)->create(['title' => 'Moka pot', 'brand' => 'Bialetti', 'category' => 'Koffiezetters']);
        WishlistItem::factory()->of($moka)->create(['wishlist_id' => $this->listFor($this->mum)->id]);

        $sameBrand = ProductGroup::factory()->priced(2000)->create(['title' => 'Melkopschuimer', 'brand' => 'Bialetti', 'category' => 'Keuken']);
        $sameKind = ProductGroup::factory()->priced(4000)->create(['title' => 'Pulcina zesKops', 'brand' => 'Alessi', 'category' => 'Koffiezetters']);

        $steps = $this->actingAs($this->giver)->get("/be-nl/people/{$this->mum->id}")
            ->viewData('page')['props']['nextSteps'];

        $this->assertContains($sameBrand->id, array_column($steps, 'id'));
        $this->assertContains($sameKind->id, array_column($steps, 'id'));
        // What is on the list is not suggested back.
        $this->assertNotContains($moka->id, array_column($steps, 'id'));
    }

    #[Test]
    public function without_a_list_there_are_no_ideas(): void
    {
        ProductGroup::factory()->priced(2000)->create(['title' => 'Melkopschuimer', 'brand' => 'Bialetti']);

        $this->assertSame([], $this->actingAs($this->giver)->get("/be-nl/people/{$this->mum->id}")
            ->viewData('page')['props']['nextSteps']);
    }

    #[Test]
    public function the_next_step_keeps_to_her_budget(): void
    {
        $moka = ProductGroup::factory()->priced(3500)->create(['title' => 'Moka pot', 'brand' => 'Bialetti']);
        $this->budgetOnHerList(2000);
        $this->gave($moka);

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
        $this->gave($moka);
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

        $this->budgetOnHerList(2000);
        $this->assertNotContains($dear->id, $ids());
        $this->assertContains($cheap->id, $ids());
    }

    /** The budget is her list's, not hers (2026-10-05); see ListBudget. */
    private function budgetOnHerList(int $cents): void
    {
        $list = Wishlist::query()->where('recipient_id', $this->mum->id)->where('kind', ListKind::ForSomeone->value)->latest('created_at')->first()
            ?? Wishlist::factory()->forSomeone($this->mum)->create(['owner_user_id' => $this->giver->id]);

        $list->update(['budget_max' => $cents]);
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
