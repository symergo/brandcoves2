<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\ListKind;
use App\Enums\Market;
use App\Models\Notification;
use App\Models\ProductGroup;
use App\Models\User;
use App\Models\Wishlist;
use App\Services\Notifications\ListActivity;
use App\Support\ListName;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * A list's name inside a sentence reaches the page in pieces, so the page can
 * draw it as a list's name (`ListName.tsx`), and reaches an e-mail bold.
 *
 * The finished sentence is still sent alongside, unchanged: every test and
 * every reader that only wants text keeps working. What these tests guard is
 * the second shape — the sentence with `:list` left in, the name and the
 * kind — because a page given only the finished sentence can no longer tell
 * where the name is.
 */
class ListNameTest extends TestCase
{
    use RefreshDatabase;

    private function list(User $user, string $title = 'Camping', ListKind $kind = ListKind::Mine): Wishlist
    {
        return Wishlist::factory()->create([
            'owner_user_id' => $user->id,
            'kind' => $kind,
            'title' => $title,
            'market' => Market::BeNl,
        ]);
    }

    #[Test]
    public function a_save_flashes_the_sentence_and_its_pieces(): void
    {
        $user = User::factory()->create();
        $list = $this->list($user);
        $group = ProductGroup::factory()->create(['market' => Market::BeNl]);

        $this->actingAs($user)
            ->post('/be-nl/list-items', ['group_id' => $group->id, 'wishlist_id' => $list->id])
            ->assertSessionHas('success', __('site.lists.added_to', ['list' => 'Camping']))
            ->assertSessionHas('success_list', function (array $mention): bool {
                return $mention['name'] === 'Camping'
                    && $mention['kind'] === 'mine'
                    && str_contains($mention['template'], ':list')
                    && ! str_contains($mention['template'], 'Camping')
                    && $mention['message'] === __('site.lists.added_to', ['list' => 'Camping']);
            });
    }

    #[Test]
    public function a_save_from_a_card_answers_with_the_kind_and_the_template(): void
    {
        $user = User::factory()->create();
        $list = $this->list($user, 'Voor Mama', ListKind::ForSomeone);
        $group = ProductGroup::factory()->create(['market' => Market::BeNl]);

        $this->actingAs($user)
            ->postJson('/be-nl/list-items', ['group_id' => $group->id, 'wishlist_id' => $list->id])
            ->assertOk()
            ->assertJsonPath('listTitle', 'Voor Mama')
            ->assertJsonPath('listKind', 'for_someone')
            ->assertJsonPath('messageTemplate', __('site.lists.added_to', ['list' => ':list']))
            ->assertJsonPath('message', __('site.lists.added_to', ['list' => 'Voor Mama']));
    }

    #[Test]
    public function the_pieces_are_shared_with_the_page_as_flash_list(): void
    {
        $user = User::factory()->create();
        $list = $this->list($user);
        $group = ProductGroup::factory()->create(['market' => Market::BeNl]);

        $this->actingAs($user)->post('/be-nl/list-items', ['group_id' => $group->id, 'wishlist_id' => $list->id]);

        $this->actingAs($user)->get("/be-nl/lists/{$list->id}")
            ->assertInertia(fn ($page) => $page
                ->where('flash.list.name', 'Camping')
                ->where('flash.list.kind', 'mine')
                ->where('flash.list.template', __('site.lists.added_to', ['list' => ':list'])));
    }

    #[Test]
    public function a_copy_names_the_list_it_went_to_in_pieces(): void
    {
        $user = User::factory()->create();
        $from = $this->list($user, 'Camping');
        $to = $this->list($user, 'Kerst');
        $group = ProductGroup::factory()->create(['market' => Market::BeNl]);

        $this->actingAs($user)->post('/be-nl/list-items', ['group_id' => $group->id, 'wishlist_id' => $from->id]);
        $item = $from->items()->sole();

        $this->actingAs($user)
            ->post("/be-nl/lists/{$from->id}/items/{$item->id}/copy", ['to' => $to->id])
            ->assertSessionHas('success_list', fn (array $mention): bool => $mention['name'] === 'Kerst' && $mention['kind'] === 'mine');
    }

    #[Test]
    public function a_notification_keeps_its_title_and_carries_the_pieces(): void
    {
        $owner = User::factory()->create();
        $friend = User::factory()->create();
        $list = $this->list($owner, 'Trouw');

        app(ListActivity::class)->shared($list, $owner, $friend, '/be-nl/lists');

        $notice = Notification::query()->where('user_id', $friend->id)->sole();

        // The title reads as before, quotes and all, for anything reading text.
        $this->assertStringContainsString('Trouw', $notice->title);
        $this->assertSame('Trouw', $notice->payload['list']['name']);
        $this->assertSame('mine', $notice->payload['list']['kind']);
        $this->assertStringContainsString(':list', $notice->payload['list']['template']);
        $this->assertSame($list->id, $notice->payload['wishlist_id']);
    }

    #[Test]
    public function quotes_around_the_name_are_dropped_as_a_pair_only(): void
    {
        $this->assertSame(['on ', '.'], ListName::withoutQuotes('on “', '”.'));
        $this->assertSame(['sur ', ' avec'], ListName::withoutQuotes("sur «\u{00A0}", "\u{00A0}» avec"));
        $this->assertSame(['called ', '.'], ListName::withoutQuotes('called "', '".'));

        // One side only belongs to something else, and stays.
        $this->assertSame(['on “', ' today'], ListName::withoutQuotes('on “', ' today'));
    }

    #[Test]
    public function an_email_names_a_list_in_bold_and_escapes_it(): void
    {
        $this->assertSame('<strong>Mama&#039;s &#42;wens&#42; &lt;lijst&gt;</strong>', ListName::inMail("Mama's *wens* <lijst>")->toHtml());

        app()->setLocale('en');
        $sentence = ListName::mailSentence('site.invitations.mail_intro', 'Camping', ['name' => 'Ann <b>']);

        // Bold, unquoted, and the other placeholder escaped rather than markup.
        $this->assertStringContainsString('<strong>Camping</strong>', $sentence->toHtml());
        $this->assertStringNotContainsString('"<strong>', $sentence->toHtml());
        $this->assertStringContainsString('Ann &lt;b&gt;', $sentence->toHtml());
    }
}
