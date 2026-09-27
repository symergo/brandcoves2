<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\CollaboratorRole;
use App\Enums\ListKind;
use App\Enums\ListVisibility;
use App\Models\AnonymousIdentity;
use App\Models\Recipient;
use App\Models\User;
use App\Models\Wishlist;
use App\Models\WishlistCollaborator;
use App\Services\Wishlist\AddingMode;
use App\Support\ListAccess;
use App\Support\Owner;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Who may open a list, after `ListAccess::scope()` became two steps (speed
 * wave 2, 2026-09-27): the ids somebody was let into first, then
 * `owner = ? OR id IN (…)`.
 *
 * This is the security half of that change. Every route into a list is set up
 * against a reader, and the new scope, `ListAccess::allows()` and the pages are
 * each held to the answer of the old one-statement query, kept below verbatim
 * as the reference.
 */
class ListAccessScopeTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private User $reader;

    /** @var array<string, Wishlist> name => list, each reaching `$reader` one way */
    private array $lists = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->create();
        $this->reader = User::factory()->create();

        $make = fn (ListVisibility $visibility, ListKind $kind = ListKind::Mine) => Wishlist::factory()->create([
            'owner_user_id' => $this->owner->id,
            'visibility' => $visibility,
            'kind' => $kind,
            // A group or a gift list is always about somebody (a CHECK says so).
            'recipient_id' => $kind === ListKind::Mine ? null : Recipient::factory()->create(['owner_user_id' => $this->owner->id])->id,
        ]);

        // The reader's own lists, one private.
        $this->lists['own private'] = Wishlist::factory()->create(['owner_user_id' => $this->reader->id, 'visibility' => ListVisibility::Private]);
        $this->lists['own shared'] = Wishlist::factory()->create(['owner_user_id' => $this->reader->id, 'visibility' => ListVisibility::Link]);

        // Collaborators, private or not: a grant, whatever the visibility.
        $this->lists['collaborator viewer on private'] = $make(ListVisibility::Private, ListKind::Group);
        $this->collaborate($this->lists['collaborator viewer on private'], CollaboratorRole::Viewer);
        $this->lists['collaborator editor on link'] = $make(ListVisibility::Link, ListKind::Group);
        $this->collaborate($this->lists['collaborator editor on link'], CollaboratorRole::Editor);

        // Followed links: only while the list is still shared.
        $this->lists['opened, link'] = $make(ListVisibility::Link);
        $this->open($this->lists['opened, link']);
        $this->lists['opened, public'] = $make(ListVisibility::Public);
        $this->open($this->lists['opened, public']);
        $this->lists['opened, since made private'] = $make(ListVisibility::Private);
        $this->open($this->lists['opened, since made private']);

        // Shared with me by name: the same rule.
        $this->lists['shared with me, link'] = $make(ListVisibility::Link, ListKind::ForSomeone);
        $this->share($this->lists['shared with me, link']);
        $this->lists['shared with me, since made private'] = $make(ListVisibility::Private);
        $this->share($this->lists['shared with me, since made private']);

        // Both a private collaborator row and a stale open: the grant wins.
        $this->lists['collaborator and stale open'] = $make(ListVisibility::Private);
        $this->collaborate($this->lists['collaborator and stale open'], CollaboratorRole::Viewer);
        $this->open($this->lists['collaborator and stale open']);

        // Nothing at all to do with the reader.
        $this->lists['stranger, link'] = $make(ListVisibility::Link);
        $this->lists['stranger, private'] = $make(ListVisibility::Private);

        // Somebody else opened / was shared / collaborates: not the reader.
        $other = User::factory()->create();
        $this->lists['someone else opened'] = $make(ListVisibility::Link);
        $this->open($this->lists['someone else opened'], $other);
        $this->lists['someone else collaborates'] = $make(ListVisibility::Private);
        $this->collaborate($this->lists['someone else collaborates'], CollaboratorRole::Editor, $other);
    }

    #[Test]
    public function the_scope_matches_the_old_query_for_every_route(): void
    {
        $owner = new Owner($this->reader, null);

        $expected = $this->reference($owner)->pluck('id')->sort()->values()->all();
        $actual = ListAccess::scope(Wishlist::query(), $owner)->pluck('id')->sort()->values()->all();

        $this->assertSame($expected, $actual);

        // And by name, so a change to the reference cannot hide a change here.
        $reachable = [
            'own private', 'own shared',
            'collaborator viewer on private', 'collaborator editor on link',
            'opened, link', 'opened, public',
            'shared with me, link',
            'collaborator and stale open',
        ];

        $ids = fn (array $names) => collect($names)->map(fn ($n) => $this->lists[$n]->id)->sort()->values()->all();

        $this->assertSame($ids($reachable), $actual);
        $this->assertSame(
            $ids(array_values(array_diff(array_keys($this->lists), $reachable))),
            collect($this->lists)->reject(fn (Wishlist $l) => in_array($l->id, $actual, true))->pluck('id')->sort()->values()->all(),
        );
    }

    #[Test]
    public function allows_gives_the_scopes_answer_for_every_list(): void
    {
        $owner = new Owner($this->reader, null);
        $inScope = ListAccess::scope(Wishlist::query(), $owner)->pluck('id')->all();

        foreach ($this->lists as $name => $list) {
            $this->assertSame(
                in_array($list->id, $inScope, true),
                ListAccess::allows($list->fresh(), $owner),
                "allows() disagrees with scope() on '{$name}'",
            );
        }
    }

    #[Test]
    public function the_list_page_opens_exactly_the_lists_in_scope(): void
    {
        $owner = new Owner($this->reader, null);
        $inScope = $this->reference($owner)->pluck('id')->all();

        foreach ($this->lists as $name => $list) {
            $status = $this->actingAs($this->reader)->get("/be-nl/lists/{$list->id}")->status();

            $this->assertSame(
                in_array($list->id, $inScope, true) ? 200 : 404,
                $status,
                "the list page answered {$status} for '{$name}'",
            );
        }
    }

    #[Test]
    public function the_scope_composes_with_other_conditions(): void
    {
        // My Coves narrows it further ("not mine"); the OR must stay wrapped.
        $owner = new Owner($this->reader, null);

        $theirs = ListAccess::scope(Wishlist::query(), $owner)
            ->whereNot(fn ($q) => $owner->scope($q))
            ->pluck('id')
            ->all();

        $this->assertNotContains($this->lists['own private']->id, $theirs);
        $this->assertNotContains($this->lists['own shared']->id, $theirs);
        $this->assertContains($this->lists['opened, link']->id, $theirs);
        $this->assertNotContains($this->lists['opened, since made private']->id, $theirs);
    }

    #[Test]
    public function a_reader_with_no_routes_sees_only_their_own(): void
    {
        $nobody = User::factory()->create();
        $mine = Wishlist::factory()->create(['owner_user_id' => $nobody->id]);

        $this->assertSame(
            [$mine->id],
            ListAccess::scope(Wishlist::query(), new Owner($nobody, null))->pluck('id')->all(),
        );
    }

    #[Test]
    public function an_anonymous_visitor_keeps_plain_ownership(): void
    {
        $identity = AnonymousIdentity::factory()->create();
        $mine = Wishlist::factory()->create(['owner_user_id' => null, 'owner_anon_id' => $identity->getKey()]);
        $owner = new Owner(null, $identity);

        // An open recorded against the cookie is a bookmark, never access.
        DB::table('list_opens')->insert([
            'wishlist_id' => $this->lists['opened, link']->id,
            'user_id' => null,
            'anon_id' => $identity->getKey(),
            'first_opened_at' => now(),
            'last_opened_at' => now(),
        ]);

        $this->assertSame([$mine->id], ListAccess::scope(Wishlist::query(), $owner)->pluck('id')->all());
        $this->assertTrue(ListAccess::allows($mine, $owner));
        $this->assertFalse(ListAccess::allows($this->lists['opened, link'], $owner));
    }

    #[Test]
    public function adding_mode_ends_on_a_list_that_can_no_longer_be_edited(): void
    {
        $mode = app(AddingMode::class);
        $owner = new Owner($this->reader, null);

        $mode->start($this->lists['collaborator editor on link']);
        $this->assertSame($this->lists['collaborator editor on link']->id, $mode->current($owner)['id'] ?? null);

        // A viewer, or a list only opened by link, is not somewhere to add.
        $mode->start($this->lists['collaborator viewer on private']);
        $this->assertNull($mode->current($owner));

        $mode->start($this->lists['opened, link']);
        $this->assertNull($mode->current($owner));

        $mode->start($this->lists['stranger, private']);
        $this->assertNull($mode->current($owner));

        $mode->start($this->lists['own private']);
        $this->assertSame($this->lists['own private']->id, $mode->current($owner)['id'] ?? null);
    }

    /**
     * The scope as it was before 2026-09-27, one statement with correlated
     * EXISTS subqueries. Kept as the reference the new one is held to.
     *
     * @return Builder<Wishlist>
     */
    private function reference(Owner $owner): Builder
    {
        $user = $owner->user;

        return Wishlist::query()->where(fn (Builder $q) => $q
            ->where('owner_user_id', $user->id)
            ->orWhereExists(fn ($sub) => $sub
                ->selectRaw('1')
                ->from('wishlist_collaborators')
                ->whereColumn('wishlist_collaborators.wishlist_id', 'wishlists.id')
                ->where('wishlist_collaborators.user_id', $user->id))
            ->orWhere(fn (Builder $q) => $q
                ->where('visibility', '!=', ListVisibility::Private->value)
                ->where(fn (Builder $q) => $q
                    ->whereExists(fn ($sub) => $sub
                        ->selectRaw('1')
                        ->from('list_opens')
                        ->whereColumn('list_opens.wishlist_id', 'wishlists.id')
                        ->where('list_opens.user_id', $user->id))
                    ->orWhereExists(fn ($sub) => $sub
                        ->selectRaw('1')
                        ->from('wishlist_shares')
                        ->whereColumn('wishlist_shares.wishlist_id', 'wishlists.id')
                        ->where('wishlist_shares.user_id', $user->id)))));
    }

    private function collaborate(Wishlist $list, CollaboratorRole $role, ?User $user = null): void
    {
        WishlistCollaborator::create([
            'wishlist_id' => $list->id,
            'user_id' => ($user ?? $this->reader)->id,
            'role' => $role->value,
        ]);
    }

    private function open(Wishlist $list, ?User $user = null): void
    {
        DB::table('list_opens')->insert([
            'wishlist_id' => $list->id,
            'user_id' => ($user ?? $this->reader)->id,
            'anon_id' => null,
            'first_opened_at' => now(),
            'last_opened_at' => now(),
        ]);
    }

    private function share(Wishlist $list): void
    {
        DB::table('wishlist_shares')->insert([
            'wishlist_id' => $list->id,
            'user_id' => $this->reader->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
