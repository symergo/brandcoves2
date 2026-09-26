<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\Market;
use App\Enums\PublishStatus;
use App\Models\DailyPick;
use App\Models\DailyPickSet;
use App\Models\ProductGroup;
use App\Models\SavedCove;
use App\Models\User;
use App\Models\Wishlist;
use Illuminate\Auth\Events\Login;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Saving a Cove into My Coves, and "Make it my list". See
 * docs/features/saved-coves.md.
 */
class SavedCoveTest extends TestCase
{
    use RefreshDatabase;

    /** A published Daily with three products, ranked 1 to 3. */
    private function cove(): DailyPickSet
    {
        $cove = DailyPickSet::create([
            'market' => Market::BeNl->value,
            'drop_date' => '2026-08-08',
            'theme_title' => 'Rond de tafel',
            'theme_blurb' => 'Voor avonden zonder scherm.',
            'theme_slug' => 'theme-board-games',
            'theme_source' => 'theme',
            'status' => PublishStatus::Published->value,
            'published_at' => now(),
        ]);

        foreach (range(1, 3) as $rank) {
            $group = ProductGroup::factory()->create(['market' => Market::BeNl, 'title' => "Bordspel {$rank}"]);

            DailyPick::create(['set_id' => $cove->id, 'group_id' => $group->id, 'rank' => $rank, 'slug' => "bordspel-{$rank}"]);
        }

        return $cove->refresh();
    }

    private function unpublish(DailyPickSet $cove): void
    {
        $cove->forceFill(['status' => PublishStatus::Draft->value])->save();
    }

    /** @return list<string> the list's item titles, in the order the list shows them */
    private function titlesOf(Wishlist $list): array
    {
        return $list->items()->with('group')->orderByDesc('created_at')->orderByDesc('id')->get()
            ->map(fn ($item) => $item->group?->title)
            ->all();
    }

    #[Test]
    public function saving_twice_keeps_one_bookmark_and_unsaving_removes_it(): void
    {
        $user = User::factory()->create();
        $cove = $this->cove();

        $this->actingAs($user)->post("/be-nl/coves/{$cove->id}/save")->assertRedirect();
        $this->actingAs($user)->post("/be-nl/coves/{$cove->id}/save")->assertRedirect();

        $this->assertSame(1, SavedCove::query()->where('user_id', $user->id)->count());

        $this->actingAs($user)->delete("/be-nl/coves/{$cove->id}/save")->assertRedirect();

        $this->assertSame(0, SavedCove::query()->where('user_id', $user->id)->count());
    }

    #[Test]
    public function an_unpublished_cove_cannot_be_saved_or_copied(): void
    {
        $user = User::factory()->create();
        $cove = $this->cove();
        $this->unpublish($cove);

        $this->actingAs($user)->post("/be-nl/coves/{$cove->id}/save")->assertNotFound();
        $this->actingAs($user)->post("/be-nl/coves/{$cove->id}/copy")->assertNotFound();
    }

    #[Test]
    public function a_guest_cannot_save_directly(): void
    {
        $cove = $this->cove();

        $this->post("/be-nl/coves/{$cove->id}/save");

        $this->assertSame(0, SavedCove::query()->count());
    }

    #[Test]
    public function a_guest_save_is_finished_after_sign_in(): void
    {
        $cove = $this->cove();

        $this->postJson('/be-nl/save-intent', ['cove_id' => $cove->id, 'cove_action' => 'save'])->assertOk();

        $user = User::factory()->create();
        Auth::login($user);
        event(new Login('web', $user, false));

        $this->assertTrue(SavedCove::query()->where('user_id', $user->id)->where('set_id', $cove->id)->exists());
        $this->assertStringContainsString('Rond de tafel', (string) session('success'));
    }

    #[Test]
    public function the_cove_page_says_whether_it_is_saved(): void
    {
        $user = User::factory()->create();
        $cove = $this->cove();

        $this->actingAs($user)->get("/be-nl/tips/{$cove->slug}")
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('saveCove.coveId', $cove->id)
                ->where('saveCove.isSaved', false));

        SavedCove::create(['user_id' => $user->id, 'set_id' => $cove->id]);

        $this->actingAs($user)->get("/be-nl/tips/{$cove->slug}")
            ->assertInertia(fn (AssertableInertia $page) => $page->where('saveCove.isSaved', true));
    }

    #[Test]
    public function the_saved_view_lists_the_cove_and_hides_it_once_unpublished(): void
    {
        $user = User::factory()->create();
        $cove = $this->cove();
        SavedCove::create(['user_id' => $user->id, 'set_id' => $cove->id]);

        $this->actingAs($user)->get('/be-nl/lists?view=saved')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Lists/Index')
                ->where('view', 'saved')
                ->has('savedCoves', 1)
                ->where('savedCoves.0.title', 'Rond de tafel')
                ->where('savedCoves.0.url', "/be-nl/tips/{$cove->slug}"));

        $this->unpublish($cove);

        $this->actingAs($user)->get('/be-nl/lists?view=saved')
            ->assertInertia(fn (AssertableInertia $page) => $page->has('savedCoves', 0));

        // Hidden, not deleted: it comes back if the Cove is published again.
        $this->assertSame(1, SavedCove::query()->where('user_id', $user->id)->count());
    }

    #[Test]
    public function copying_makes_a_list_in_the_coves_order_that_later_edits_do_not_touch(): void
    {
        $user = User::factory()->create();
        $cove = $this->cove();

        // A pick with no catalogue product (an Amazon one) is skipped.
        DailyPick::create(['set_id' => $cove->id, 'amazon_asin' => 'B000TEST01', 'rank' => 4, 'slug' => 'gone']);

        $this->actingAs($user)->post("/be-nl/coves/{$cove->id}/copy")->assertRedirect();

        $list = Wishlist::query()->where('owner_user_id', $user->id)->where('title', 'Rond de tafel')->firstOrFail();

        $this->assertSame(['Bordspel 1', 'Bordspel 2', 'Bordspel 3'], $this->titlesOf($list));

        // A snapshot: taking a product out of the Cove leaves the list alone.
        DailyPick::query()->where('set_id', $cove->id)->where('rank', 1)->delete();

        $this->assertCount(3, $this->titlesOf($list->refresh()));
    }
}
