<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\IdeaPriceBand;
use App\Enums\Market;
use App\Enums\OfflineIdeaStatus;
use App\Enums\Source;
use App\Filament\Pages\OfflineIdeaReview;
use App\Jobs\CountOfflineIdeas;
use App\Models\OfflineIdea;
use App\Models\ProductGroup;
use App\Models\User;
use App\Models\Wishlist;
use App\Models\WishlistItem;
use App\Services\Ideas\OfflineIdeaCounter;
use Illuminate\Auth\Events\Login;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Offline items people typed by hand, as gift ideas for others.
 *
 * The promises held here: nothing is proposed below five different people,
 * nothing is shown before a person approves it, a visitor never sees who
 * wrote it, how many, or what they typed, ideas stay in their market, and
 * "Add to my list" puts the approved wording on the visitor's own list.
 */
class OfflineIdeasTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function an_idea_is_proposed_only_once_five_different_people_wrote_it(): void
    {
        // Four people, spelled four ways.
        foreach (['Kookworkshop', 'kook workshop', 'Een kook-workshop!', 'KOOKWORKSHOPS'] as $title) {
            $this->offlineItem($title);
        }

        // The same person again, on a second list: still four people.
        $again = User::query()->first();
        $this->offlineItem('kookworkshop', owner: $again);

        $this->countIdeas();
        $this->assertSame(0, OfflineIdea::query()->count());

        $this->offlineItem('Kookworkshop 2026');
        $this->countIdeas();

        $idea = OfflineIdea::query()->sole();
        $this->assertSame('kookworkshop', $idea->key);
        $this->assertSame(OfflineIdeaStatus::Pending, $idea->status);
        $this->assertSame(5, $idea->owners);
        $this->assertNotNull($idea->sample_title, 'the reviewer sees one spelling');
    }

    #[Test]
    public function only_accepted_offline_items_without_a_link_or_product_count(): void
    {
        foreach (range(1, 5) as $i) {
            $this->offlineItem('Spa dag', accepted: false);
            $this->offlineItem('Spa dag', url: 'https://example.com/spa');
        }

        $group = ProductGroup::factory()->create(['market' => Market::BeNl]);
        foreach (range(1, 5) as $i) {
            $item = $this->offlineItem('Spa dag');
            $item->update(['group_id' => $group->id]);
        }

        $this->countIdeas();

        $this->assertSame(0, OfflineIdea::query()->count());
    }

    #[Test]
    public function ideas_are_counted_per_market(): void
    {
        foreach (range(1, 3) as $i) {
            $this->offlineItem('Kookworkshop', market: Market::BeNl);
            $this->offlineItem('Kookworkshop', market: Market::NlNl);
        }

        $this->countIdeas();

        $this->assertSame(0, OfflineIdea::query()->count(), 'three and three are not five in one market');
    }

    #[Test]
    public function a_waiting_idea_leaves_the_queue_when_people_remove_it_but_a_decided_one_stays(): void
    {
        $items = collect(range(1, 5))->map(fn () => $this->offlineItem('Kookworkshop'));
        $rejected = collect(range(1, 5))->map(fn () => $this->offlineItem('Hotelnacht'));
        $this->countIdeas();

        OfflineIdea::query()->where('key', 'hotelnacht')->update(['status' => OfflineIdeaStatus::Rejected->value, 'sample_title' => null]);

        $items->first()->delete();
        $rejected->first()->delete();
        $this->countIdeas();

        $this->assertFalse(OfflineIdea::query()->where('key', 'kookworkshop')->exists());
        $this->assertTrue(OfflineIdea::query()->where('key', 'hotelnacht')->exists(), 'a rejection is kept, so it never comes back');
    }

    #[Test]
    public function an_approved_ideas_own_wording_coming_back_is_not_proposed_again(): void
    {
        $this->approved('Een workshop koken', ['interest:cooking'], key: 'kookworkshop');

        foreach (range(1, 5) as $i) {
            $this->offlineItem('Een workshop koken');
        }

        $this->countIdeas();

        $this->assertSame(1, OfflineIdea::query()->count());
    }

    #[Test]
    public function nothing_is_shown_before_a_person_approves_it(): void
    {
        foreach (range(1, 5) as $i) {
            $this->offlineItem('Kookworkshop');
        }
        $this->countIdeas();

        OfflineIdea::query()->update(['tags' => ['interest:cooking']]);

        $this->assertSame([], $this->ideasFor(['interests' => ['cooking']]));
    }

    #[Test]
    public function an_approved_idea_shows_in_the_gift_finder_with_nothing_identifying(): void
    {
        $idea = $this->approved('Een kookworkshop', ['interest:cooking'], key: 'qqkookworkshopqq', sample: 'kookworkshop voor Zyxwina');
        $this->approved('Een dagje karten', ['interest:cars']);

        $response = $this->post('/be-nl/gift', ['interests' => ['cooking'], 'budget_max' => 100])->assertOk();

        $ideas = $response->viewData('page')['props']['offlineIdeas'];
        $this->assertSame([['id' => $idea->id, 'title' => 'Een kookworkshop']], $ideas);

        // Not the key, not the count, not what anybody typed, anywhere on the page.
        $page = json_encode($response->viewData('page'));
        $this->assertStringNotContainsString('Zyxwina', (string) $page);
        $this->assertStringNotContainsString('"owners"', (string) $page);
        $this->assertStringNotContainsString('qqkookworkshopqq', (string) $page);
    }

    #[Test]
    public function ideas_stay_in_their_market(): void
    {
        $this->approved('Een kookworkshop', ['interest:cooking'], market: Market::NlNl);

        $this->assertSame([], $this->ideasFor(['interests' => ['cooking']]));
    }

    #[Test]
    public function the_budget_keeps_a_dear_idea_away(): void
    {
        $this->approved('Een weekend weg', ['interest:travel'], band: IdeaPriceBand::High);
        $this->approved('Een stadswandeling', ['interest:travel'], band: IdeaPriceBand::Low);

        $titles = array_column($this->ideasFor(['interests' => ['travel'], 'budget_max' => 30]), 'title');

        $this->assertSame(['Een stadswandeling'], $titles);
    }

    #[Test]
    public function this_or_that_shows_ideas_for_the_taste_it_found(): void
    {
        $choices = [];

        foreach (range(1, 3) as $i) {
            $cooking = ProductGroup::factory()->forMarket(Market::BeNl)->priced(3000)->create(['gift_tags' => ['interest:cooking'], 'title' => 'Kook '.Str::random(6)]);
            $other = ProductGroup::factory()->forMarket(Market::BeNl)->priced(3000)->create(['gift_tags' => ['interest:gaming'], 'title' => 'Spel '.Str::random(6)]);
            $choices[] = ['shown' => [$cooking->id, $other->id], 'picked' => $cooking->id];
        }

        $idea = $this->approved('Een kookworkshop', ['interest:cooking']);

        $result = $this->post('/be-nl/gift/taste', ['choices' => $choices, 'for' => 'someone'])
            ->assertOk()
            ->viewData('page')['props']['result'];

        $this->assertSame([['id' => $idea->id, 'title' => 'Een kookworkshop']], $result['offlineIdeas']);
    }

    #[Test]
    public function add_to_my_list_puts_the_approved_wording_on_the_list(): void
    {
        $idea = $this->approved('Een kookworkshop', ['interest:cooking']);
        $user = User::factory()->create();

        $this->actingAs($user)
            ->postJson('/be-nl/list-items', ['source' => 'manual', 'idea_id' => $idea->id, 'title' => 'something else entirely'])
            ->assertOk();

        $item = WishlistItem::query()->sole();
        $this->assertSame('Een kookworkshop', $item->snapshot_title, 'the wording comes from the idea, not the request');
        $this->assertSame(Source::Manual, $item->source);
        $this->assertNull($item->group_id);
        $this->assertSame($user->id, $item->wishlist->owner_user_id);
    }

    #[Test]
    public function an_idea_that_is_not_approved_cannot_be_added(): void
    {
        $pending = OfflineIdea::query()->create(['market' => Market::BeNl, 'key' => 'spadag', 'status' => OfflineIdeaStatus::Pending]);

        $this->actingAs(User::factory()->create())
            ->postJson('/be-nl/list-items', ['source' => 'manual', 'idea_id' => $pending->id])
            ->assertNotFound();

        $this->assertSame(0, WishlistItem::query()->count());
    }

    #[Test]
    public function a_guest_adds_it_after_signing_in(): void
    {
        $idea = $this->approved('Een kookworkshop', ['interest:cooking']);

        $this->postJson('/be-nl/save-intent', ['idea_id' => $idea->id, 'return_to' => '/be-nl/gift'])->assertOk();

        $user = User::factory()->create();
        Auth::login($user);
        event(new Login('web', $user, false));

        $item = WishlistItem::query()->sole();
        $this->assertSame('Een kookworkshop', $item->snapshot_title);
        $this->assertSame($user->id, $item->wishlist->owner_user_id);
    }

    #[Test]
    public function the_review_screen_approves_with_wording_and_tags_and_forgets_the_spelling(): void
    {
        foreach (range(1, 5) as $i) {
            $this->offlineItem('kookworkshop');
        }
        $this->countIdeas();

        $admin = $this->admin();

        // No tag: refused, because an untagged idea could never be shown.
        Livewire::actingAs($admin)->test(OfflineIdeaReview::class)
            ->assertSee('kookworkshop')
            ->set('wording', 'Een kookworkshop')
            ->call('approve');
        $this->assertSame(OfflineIdeaStatus::Pending, OfflineIdea::query()->sole()->status);

        Livewire::actingAs($admin)->test(OfflineIdeaReview::class)
            ->set('wording', 'Een kookworkshop')
            ->set('tags', ['interest:cooking', 'not:a-tag'])
            ->set('priceBand', 'mid')
            ->call('approve');

        $idea = OfflineIdea::query()->sole();
        $this->assertSame(OfflineIdeaStatus::Approved, $idea->status);
        $this->assertSame('Een kookworkshop', $idea->title);
        $this->assertSame(['interest:cooking'], $idea->tags);
        $this->assertSame(IdeaPriceBand::Mid, $idea->price_band);
        $this->assertNull($idea->sample_title, 'nothing anybody typed is kept once decided');
    }

    #[Test]
    public function the_review_screen_rejects(): void
    {
        foreach (range(1, 5) as $i) {
            $this->offlineItem('Tickets voor Anna');
        }
        $this->countIdeas();

        Livewire::actingAs($this->admin())->test(OfflineIdeaReview::class)->call('reject');

        $idea = OfflineIdea::query()->sole();
        $this->assertSame(OfflineIdeaStatus::Rejected, $idea->status);
        $this->assertNull($idea->sample_title);
    }

    private function countIdeas(): void
    {
        (new CountOfflineIdeas)->handle(app(OfflineIdeaCounter::class));
    }

    /**
     * @param  array<string, mixed>  $brief
     * @return list<array{id: int, title: string}>
     */
    private function ideasFor(array $brief): array
    {
        return $this->post('/be-nl/gift', $brief)->assertOk()->viewData('page')['props']['offlineIdeas'];
    }

    /** @param list<string> $tags */
    private function approved(string $title, array $tags, Market $market = Market::BeNl, ?IdeaPriceBand $band = null, ?string $key = null, ?string $sample = null): OfflineIdea
    {
        return OfflineIdea::query()->create([
            'market' => $market,
            'key' => $key ?? Str::slug($title, ''),
            'sample_title' => $sample,
            'title' => $title,
            'tags' => $tags,
            'price_band' => $band,
            'status' => OfflineIdeaStatus::Approved,
            'owners' => 5,
        ]);
    }

    private function offlineItem(string $title, Market $market = Market::BeNl, ?User $owner = null, bool $accepted = true, ?string $url = null): WishlistItem
    {
        $owner ??= User::factory()->create();

        $list = Wishlist::create([
            'owner_user_id' => $owner->id,
            'title' => 'Mijn lijst',
            'market' => $market,
            'visibility' => 'private',
        ]);

        return WishlistItem::query()->create([
            'wishlist_id' => $list->id,
            'source' => Source::Manual->value,
            'snapshot_title' => $title,
            'snapshot_url' => $url,
            'accepted_at' => $accepted ? now() : null,
        ]);
    }

    private function admin(): User
    {
        $user = User::create(['name' => 'Admin', 'email' => 'admin@example.test', 'password' => 'password-for-testing']);
        $user->forceFill(['is_admin' => true])->save();

        return $user;
    }
}
