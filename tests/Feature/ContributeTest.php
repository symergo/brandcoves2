<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\ModerationStatus;
use App\Models\FeatureIdea;
use App\Models\FeatureVote;
use App\Models\User;
use App\Services\Contribute\FeatureSuggestions;
use App\Support\ContributeBar;
use App\Support\MarketPreference;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * "Denk mee": the voting board, suggestions, and the bar under the header
 * (docs/features/contribute.md).
 *
 * The properties worth holding: anybody reads the board, only an account
 * votes and only once, nothing a visitor suggests is public before a person
 * publishes it, and a closed bar stays closed from the first paint.
 */
class ContributeTest extends TestCase
{
    use RefreshDatabase;

    private function idea(array $attributes = []): FeatureIdea
    {
        return FeatureIdea::query()->create([
            'title' => ['nl' => 'Een Cove volgen', 'en' => 'Follow a Cove'],
            'body' => ['nl' => 'Een melding als er iets bijkomt.'],
            'language' => 'nl',
            'status' => 'considering',
            'moderation' => ModerationStatus::Published->value,
            'source' => FeatureIdea::SOURCE_OWNER,
            ...$attributes,
        ]);
    }

    private function member(string $email = 'ann@example.test'): User
    {
        return User::create(['email' => $email]);
    }

    #[Test]
    public function the_page_renders_for_a_guest_in_every_market_in_its_language(): void
    {
        $this->idea();

        foreach (['be-nl' => 'Een Cove volgen', 'nl-nl' => 'Een Cove volgen', 'en' => 'Follow a Cove', 'be-fr' => 'Een Cove volgen', 'es' => 'Een Cove volgen'] as $market => $title) {
            // French and Spanish have no text yet: the Dutch it was written
            // in, rather than an empty card.
            $this->get("/{$market}/contribute")
                ->assertOk()
                ->assertInertia(fn ($page) => $page
                    ->component('Contribute')
                    ->where('isSignedIn', false)
                    ->where('ideas.0.title', $title)
                    ->where('ideas.0.votedByMe', false)
                    ->where('waiting', []));
        }
    }

    #[Test]
    public function the_page_renders_for_a_member_in_every_market(): void
    {
        $this->idea();
        $ann = $this->member();

        foreach (['be-nl', 'nl-nl', 'en', 'be-fr', 'es'] as $market) {
            $this->actingAs($ann)->get("/{$market}/contribute")
                ->assertOk()
                ->assertInertia(fn ($page) => $page->component('Contribute')->where('isSignedIn', true));
        }
    }

    #[Test]
    public function the_board_is_sorted_building_planned_then_considering_by_votes_and_done_last(): void
    {
        $done = $this->idea(['status' => 'done', 'title' => ['nl' => 'Klaar']]);
        $few = $this->idea(['title' => ['nl' => 'Weinig stemmen']]);
        $many = $this->idea(['title' => ['nl' => 'Veel stemmen']]);
        $planned = $this->idea(['status' => 'planned', 'title' => ['nl' => 'Gepland']]);
        $building = $this->idea(['status' => 'building', 'title' => ['nl' => 'In aanbouw']]);

        foreach (['a', 'b'] as $who) {
            FeatureVote::query()->create(['feature_idea_id' => $many->id, 'user_id' => $this->member("{$who}@example.test")->id]);
        }

        $ids = collect($this->get('/be-nl/contribute')->viewData('page')['props']['ideas'])->pluck('id')->all();

        $this->assertSame([$building->id, $planned->id, $many->id, $few->id, $done->id], $ids);
    }

    #[Test]
    public function a_member_votes_once_and_can_take_it_back(): void
    {
        $idea = $this->idea();
        $ann = $this->member();

        $this->actingAs($ann)->post("/be-nl/contribute/ideas/{$idea->id}/vote")->assertRedirect();
        $this->actingAs($ann)->post("/be-nl/contribute/ideas/{$idea->id}/vote")->assertRedirect();

        $this->assertSame(1, FeatureVote::query()->count());

        $this->actingAs($ann)->get('/be-nl/contribute')
            ->assertInertia(fn ($page) => $page
                ->where('ideas.0.votes', 1)
                ->where('ideas.0.votedByMe', true));

        $this->actingAs($ann)->delete("/be-nl/contribute/ideas/{$idea->id}/vote")->assertRedirect();
        $this->assertSame(0, FeatureVote::query()->count());
    }

    #[Test]
    public function a_guest_cannot_vote(): void
    {
        $idea = $this->idea();

        $this->post("/be-nl/contribute/ideas/{$idea->id}/vote")->assertRedirect('/be-nl/login');

        $this->assertSame(0, FeatureVote::query()->count());
    }

    #[Test]
    public function nobody_votes_on_a_suggestion_that_is_not_published_or_an_idea_that_is_done(): void
    {
        $ann = $this->member();
        $pending = $this->idea(['moderation' => ModerationStatus::Pending->value]);
        $done = $this->idea(['status' => 'done']);

        $this->actingAs($ann)->post("/be-nl/contribute/ideas/{$pending->id}/vote")->assertNotFound();
        $this->actingAs($ann)->post("/be-nl/contribute/ideas/{$done->id}/vote")->assertNotFound();

        $this->assertSame(0, FeatureVote::query()->count());
    }

    #[Test]
    public function a_suggestion_waits_for_a_person_and_only_its_author_sees_it(): void
    {
        $ann = $this->member();
        $bob = $this->member('bob@example.test');

        $this->actingAs($ann)->post('/be-fr/contribute/suggestions', [
            'title' => 'Partager une liste sur WhatsApp',
            'body' => 'Un bouton sous la liste.',
        ])->assertRedirect()->assertSessionHas('status');

        $idea = FeatureIdea::query()->sole();
        $this->assertSame(ModerationStatus::Pending, $idea->moderation);
        $this->assertSame(FeatureIdea::SOURCE_VISITOR, $idea->source);
        $this->assertSame('fr', $idea->language);
        $this->assertSame(['fr' => 'Partager une liste sur WhatsApp'], $idea->title);
        $this->assertSame($ann->id, $idea->suggested_by);

        // Not on the board, for anybody.
        $this->actingAs($ann)->get('/be-fr/contribute')
            ->assertInertia(fn ($page) => $page
                ->where('ideas', [])
                ->where('waiting.0.title', 'Partager une liste sur WhatsApp'));

        $this->actingAs($bob)->get('/be-fr/contribute')
            ->assertInertia(fn ($page) => $page->where('ideas', [])->where('waiting', []));

        // Published by a person in the admin: on the board, in its own
        // language wherever there is no other text yet.
        $idea->update(['moderation' => ModerationStatus::Published->value, 'decided_at' => now()]);

        $this->get('/be-nl/contribute')
            ->assertInertia(fn ($page) => $page->where('ideas.0.title', 'Partager une liste sur WhatsApp'));
    }

    #[Test]
    public function a_guest_cannot_suggest(): void
    {
        $this->post('/be-nl/contribute/suggestions', ['title' => 'Something worth having'])
            ->assertRedirect('/be-nl/login');

        $this->assertSame(0, FeatureIdea::query()->count());
    }

    #[Test]
    public function suggestions_are_limited_per_day(): void
    {
        $ann = $this->member();

        for ($i = 0; $i < FeatureSuggestions::PER_DAY; $i++) {
            $this->actingAs($ann)->post('/be-nl/contribute/suggestions', ['title' => "Idee nummer {$i}"])
                ->assertSessionHasNoErrors();
        }

        $this->actingAs($ann)->post('/be-nl/contribute/suggestions', ['title' => 'Nog een idee'])
            ->assertSessionHasErrors('title');

        $this->assertSame(FeatureSuggestions::PER_DAY, FeatureIdea::query()->count());
    }

    #[Test]
    public function the_bar_shows_until_it_is_closed(): void
    {
        // A visitor at home in their chosen market, so the market bar is not
        // up (the two never show together).
        $this->withCookie(MarketPreference::COOKIE, 'be-nl')
            ->get('/be-nl')
            ->assertInertia(fn ($page) => $page->where('contributeBar', true));

        $closed = $this->withHeader('Accept', 'application/json')->post('/contribute-bar');
        $closed->assertNoContent()->assertCookie(ContributeBar::COOKIE, 'closed');

        $this->withCookie(MarketPreference::COOKIE, 'be-nl')
            ->withCookie(ContributeBar::COOKIE, 'closed')
            ->get('/be-nl')
            ->assertInertia(fn ($page) => $page->where('contributeBar', false));
    }

    #[Test]
    public function the_bar_waits_for_the_market_bar_and_skips_crawlers(): void
    {
        // No market chosen and a browser pointing elsewhere: the market bar
        // asks first.
        $this->withHeader('Accept-Language', 'fr-BE')
            ->get('/be-nl')
            ->assertInertia(fn ($page) => $page->whereNot('marketBar', null)->where('contributeBar', false));

        $this->withCookie(MarketPreference::COOKIE, 'be-nl')
            ->withHeader('User-Agent', 'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)')
            ->get('/be-nl')
            ->assertInertia(fn ($page) => $page->where('contributeBar', false));
    }

    #[Test]
    public function the_layout_draws_the_bar_and_the_footer_link_and_help_links_the_page(): void
    {
        // Source checks: SSR does not run in the suite, so rendered HTML would
        // pass for the wrong reason (the same approach as HelpPageTest).
        $layout = file_get_contents(resource_path('js/Layouts/SiteLayout.tsx'));
        $this->assertStringContainsString('<ContributeBar />', $layout);
        $this->assertStringContainsString('/contribute`', $layout);

        $bar = file_get_contents(resource_path('js/Components/ContributeBar.tsx'));
        $this->assertStringContainsString("'Contribute'", $bar, 'the bar must not show on the page it points at');

        $this->assertStringContainsString('/contribute`', file_get_contents(resource_path('js/Pages/Help.tsx')));
    }

    #[Test]
    public function deleting_an_account_takes_its_votes_and_leaves_its_published_idea(): void
    {
        $ann = $this->member();
        $idea = $this->idea(['source' => FeatureIdea::SOURCE_VISITOR, 'suggested_by' => $ann->id]);
        FeatureVote::query()->create(['feature_idea_id' => $idea->id, 'user_id' => $ann->id]);

        $ann->delete();

        $this->assertSame(0, FeatureVote::query()->count());
        $this->assertNull($idea->fresh()->suggested_by);
    }

    #[Test]
    public function a_rejected_suggestion_is_pruned_a_year_after_the_decision(): void
    {
        $ann = $this->member();
        $old = $this->idea(['source' => FeatureIdea::SOURCE_VISITOR, 'suggested_by' => $ann->id, 'moderation' => 'rejected', 'decided_at' => now()->subDays(366)]);
        $recent = $this->idea(['source' => FeatureIdea::SOURCE_VISITOR, 'suggested_by' => $ann->id, 'moderation' => 'rejected', 'decided_at' => now()->subDays(10)]);
        $published = $this->idea(['source' => FeatureIdea::SOURCE_VISITOR, 'suggested_by' => $ann->id, 'decided_at' => now()->subDays(400)]);
        $ownRejected = $this->idea(['moderation' => 'rejected', 'decided_at' => now()->subDays(400)]);

        $this->artisan('bc:prune-personal-data')->assertSuccessful();

        $this->assertNull(FeatureIdea::query()->find($old->id));
        $this->assertNotNull(FeatureIdea::query()->find($recent->id));
        $this->assertNotNull(FeatureIdea::query()->find($published->id));
        $this->assertNotNull(FeatureIdea::query()->find($ownRejected->id));
    }

    #[Test]
    public function the_scrub_removes_suggestions_nobody_else_has_seen(): void
    {
        $ann = $this->member();
        $pending = $this->idea(['source' => FeatureIdea::SOURCE_VISITOR, 'suggested_by' => $ann->id, 'moderation' => 'pending']);
        $published = $this->idea(['source' => FeatureIdea::SOURCE_VISITOR, 'suggested_by' => $ann->id]);

        $this->artisan('bc:scrub', ['--force' => true])->assertSuccessful();

        $this->assertNull(FeatureIdea::query()->find($pending->id));
        $this->assertNotNull(FeatureIdea::query()->find($published->id));
    }
}
