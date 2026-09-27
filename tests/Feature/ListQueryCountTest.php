<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\CollaboratorRole;
use App\Enums\ListKind;
use App\Enums\ListVisibility;
use App\Enums\Market;
use App\Models\Recipient;
use App\Models\User;
use App\Models\Wishlist;
use App\Models\WishlistCollaborator;
use App\Models\WishlistItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * How many queries My Coves, a list page and a shared page cost, and that the
 * number does not grow with the lists or the items on them (speed wave 2,
 * 2026-09-27, docs/features/speed.md "Lists, people and gifts").
 *
 * The ceilings are the counts measured when this was written plus a little
 * room. A page that goes past one has grown a query somewhere; the equality
 * checks are the point, since a query per row is what these pages had.
 */
class ListQueryCountTest extends TestCase
{
    use RefreshDatabase;

    private User $me;

    private User $friend;

    protected function setUp(): void
    {
        parent::setUp();

        $this->me = User::factory()->create();
        $this->friend = User::factory()->create();
    }

    #[Test]
    public function my_coves_costs_the_same_with_three_lists_or_eighteen(): void
    {
        $this->addLists(1);
        $this->queries(fn () => $this->actingAs($this->me)->get('/be-nl/lists')->assertOk()); // warm: the default list is made on the first visit

        $few = $this->queries(fn () => $this->actingAs($this->me)->get('/be-nl/lists')->assertOk());

        $this->addLists(5);
        $many = $this->queries(fn () => $this->actingAs($this->me)->get('/be-nl/lists')->assertOk());

        $this->assertSame($few, $many, "My Coves: {$few} queries with a few lists, {$many} with many");
        $this->assertLessThanOrEqual(20, $many);
    }

    #[Test]
    public function the_list_page_costs_the_same_with_three_items_or_twelve(): void
    {
        $this->addLists(2);
        $list = $this->list($this->me, ListKind::ForSomeone, ListVisibility::Link);
        $this->items($list, 3);

        $url = "/be-nl/lists/{$list->id}";
        $this->queries(fn () => $this->actingAs($this->me)->get($url)->assertOk());

        $few = $this->queries(fn () => $this->actingAs($this->me)->get($url)->assertOk());

        $this->items($list, 9);
        $this->addLists(3);
        $many = $this->queries(fn () => $this->actingAs($this->me)->get($url)->assertOk());

        $this->assertSame($few, $many, "list page: {$few} queries with a few items and copy targets, {$many} with many");
        $this->assertLessThanOrEqual(25, $many);
    }

    #[Test]
    public function the_shared_page_costs_the_same_with_three_items_or_twelve(): void
    {
        $list = $this->list($this->friend, ListKind::Mine, ListVisibility::Link);
        $this->items($list, 3);
        $url = "/be-nl/l/{$list->share_token}";

        // The first open records the bookmark and the friendship.
        $this->actingAs($this->me)->get($url)->assertOk();

        $few = $this->queries(fn () => $this->actingAs($this->me)->get($url)->assertOk());

        $this->items($list, 9);
        $many = $this->queries(fn () => $this->actingAs($this->me)->get($url)->assertOk());

        $this->assertSame($few, $many, "shared page: {$few} queries with a few items, {$many} with many");
        $this->assertLessThanOrEqual(15, $many);
    }

    #[Test]
    public function opening_a_shared_list_again_within_the_hour_writes_nothing(): void
    {
        $list = $this->list($this->friend, ListKind::Mine, ListVisibility::Link);
        $url = "/be-nl/l/{$list->share_token}";

        $this->actingAs($this->me)->get($url)->assertOk();
        $this->assertDatabaseHas('list_opens', ['wishlist_id' => $list->id, 'user_id' => $this->me->id]);
        $this->assertDatabaseHas('friendships', ['user_id' => $this->me->id, 'friend_id' => $this->friend->id]);

        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->actingAs($this->me)->get($url)->assertOk();
        $writes = collect(DB::getQueryLog())
            ->pluck('query')
            ->filter(fn (string $sql) => preg_match('/^\s*(insert|update)\b.*\b(list_opens|friendships)\b/i', $sql) === 1);
        DB::disableQueryLog();

        $this->assertCount(0, $writes, 'the second open rewrote: '.$writes->implode(' | '));

        // An hour on, it is recorded again.
        $this->travel(61)->minutes();
        $before = DB::table('list_opens')->where('wishlist_id', $list->id)->value('last_opened_at');
        $this->actingAs($this->me)->get($url)->assertOk();
        $this->assertNotEquals($before, DB::table('list_opens')->where('wishlist_id', $list->id)->value('last_opened_at'));
    }

    #[Test]
    public function removing_a_friend_and_opening_their_link_again_reconnects_at_once(): void
    {
        $list = $this->list($this->friend, ListKind::Mine, ListVisibility::Link);
        $url = "/be-nl/l/{$list->share_token}";

        $this->actingAs($this->me)->get($url)->assertOk();
        $this->actingAs($this->me)->delete("/be-nl/friends/{$this->friend->id}");
        $this->assertDatabaseMissing('friendships', ['user_id' => $this->me->id, 'friend_id' => $this->friend->id]);

        // What opening a link has always done, the hour's marker notwithstanding.
        $this->actingAs($this->me)->get($url)->assertOk();
        $this->assertDatabaseHas('friendships', ['user_id' => $this->me->id, 'friend_id' => $this->friend->id]);
    }

    private function queries(callable $request): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $request();
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        if (getenv('SHOW_QUERY_COUNTS')) {
            fwrite(STDERR, $this->name().': '.$count.PHP_EOL);
        }

        return $count;
    }

    /**
     * `$sets` of every route onto My Coves: three of my own, and three of
     * my friend's that reached me (opened, shared, collaborating), each with
     * a few items.
     */
    private function addLists(int $sets): void
    {
        for ($i = 0; $i < $sets; $i++) {
            foreach ([ListKind::Mine, ListKind::ForSomeone, ListKind::Group] as $kind) {
                $this->items($this->list($this->me, $kind, ListVisibility::Link), 2);
            }

            $opened = $this->list($this->friend, ListKind::Mine, ListVisibility::Link);
            DB::table('list_opens')->insert(['wishlist_id' => $opened->id, 'user_id' => $this->me->id, 'anon_id' => null, 'first_opened_at' => now(), 'last_opened_at' => now()]);
            $this->items($opened, 2);

            $shared = $this->list($this->friend, ListKind::ForSomeone, ListVisibility::Link);
            DB::table('wishlist_shares')->insert(['wishlist_id' => $shared->id, 'user_id' => $this->me->id, 'created_at' => now(), 'updated_at' => now()]);
            $this->items($shared, 2);

            $together = $this->list($this->friend, ListKind::Group, ListVisibility::Private);
            WishlistCollaborator::create(['wishlist_id' => $together->id, 'user_id' => $this->me->id, 'role' => CollaboratorRole::Editor->value]);
            $this->items($together, 2);
        }
    }

    private function list(User $owner, ListKind $kind, ListVisibility $visibility): Wishlist
    {
        return Wishlist::factory()->create([
            'owner_user_id' => $owner->id,
            'kind' => $kind,
            'market' => Market::BeNl,
            'visibility' => $visibility,
            'recipient_id' => $kind === ListKind::Mine
                ? null
                : Recipient::factory()->create(['owner_user_id' => $owner->id])->id,
        ]);
    }

    private function items(Wishlist $list, int $count): void
    {
        WishlistItem::factory()->count($count)->create(['wishlist_id' => $list->id]);
    }
}
