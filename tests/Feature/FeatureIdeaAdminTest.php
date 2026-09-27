<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\FeatureStatus;
use App\Enums\ModerationStatus;
use App\Filament\Resources\FeatureIdeas\Pages\EditFeatureIdea;
use App\Filament\Resources\FeatureIdeas\Pages\ListFeatureIdeas;
use App\Models\FeatureIdea;
use App\Models\FeatureVote;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The shipped ideas and the admin screen that manages them
 * (docs/features/contribute.md).
 *
 * What must hold: seeding is repeatable and never overwrites an idea somebody
 * edited, and the queue renders with a row in every state (a Filament column
 * closure only runs once a row exists; see AdminPanelTest).
 */
class FeatureIdeaAdminTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        $user = User::create(['name' => 'Root', 'email' => 'root@example.test', 'password' => 'password-for-testing']);
        $user->forceFill(['is_admin' => true])->save();

        return $user;
    }

    #[Test]
    public function the_shipped_ideas_are_complete_in_four_languages_and_never_about_amazon(): void
    {
        $content = require resource_path('content/feature-ideas.php');

        // Only truly new ideas since 2026-09-27 (owner): three. The board fills
        // with visitors' suggestions from here, so this checks it is not empty.
        $this->assertNotEmpty($content);

        foreach ($content as $key => $idea) {
            $this->assertNotNull(FeatureStatus::tryFrom($idea['status']), "{$key} has an unknown status");

            foreach (FeatureIdea::LANGUAGES as $language) {
                $this->assertNotSame('', trim($idea[$language]['title'] ?? ''), "{$key} has no {$language} title");
                $this->assertNotSame('', trim($idea[$language]['body'] ?? ''), "{$key} has no {$language} text");
                $this->assertStringNotContainsStringIgnoringCase('amazon', $idea[$language]['title'].$idea[$language]['body']);
            }
        }
    }

    #[Test]
    public function seeding_is_repeatable_and_publishes_every_idea(): void
    {
        $this->artisan('bc:seed-feature-ideas')->assertSuccessful();
        $count = FeatureIdea::query()->count();

        $this->artisan('bc:seed-feature-ideas')->assertSuccessful();

        $this->assertSame($count, FeatureIdea::query()->count());
        $this->assertSame($count, FeatureIdea::query()->published()->where('source', FeatureIdea::SOURCE_SEED)->count());

        $this->get('/be-nl/contribute')
            ->assertInertia(fn ($page) => $page->has('ideas', $count));
    }

    #[Test]
    public function a_dry_run_writes_nothing(): void
    {
        $this->artisan('bc:seed-feature-ideas', ['--dry-run' => true])->assertSuccessful();

        $this->assertSame(0, FeatureIdea::query()->count());
    }

    #[Test]
    public function seeding_never_overwrites_an_idea_edited_in_the_admin(): void
    {
        $this->artisan('bc:seed-feature-ideas')->assertSuccessful();

        $idea = FeatureIdea::query()->where('seed_key', 'follow-a-cove')->sole();

        Livewire::actingAs($this->admin())
            ->test(EditFeatureIdea::class, ['record' => $idea->getRouteKey()])
            ->fillForm(['status' => FeatureStatus::Planned->value, 'title.nl' => 'Coves volgen'])
            ->call('save')
            ->assertHasNoFormErrors();

        $idea->refresh();
        $this->assertSame(FeatureIdea::SOURCE_OWNER, $idea->source);
        $this->assertSame(FeatureStatus::Planned, $idea->status);

        $this->artisan('bc:seed-feature-ideas')->assertSuccessful();

        $idea->refresh();
        $this->assertSame('Coves volgen', $idea->title['nl']);
        $this->assertSame(FeatureStatus::Planned, $idea->status);
    }

    #[Test]
    public function the_queue_renders_with_a_row_in_every_state_and_publishes_a_suggestion(): void
    {
        $admin = $this->admin();
        $voter = User::create(['email' => 'voter@example.test']);

        foreach (ModerationStatus::cases() as $moderation) {
            foreach (FeatureStatus::cases() as $status) {
                $idea = FeatureIdea::query()->create([
                    'title' => ['nl' => "Idee {$moderation->value} {$status->value}"],
                    'language' => 'nl',
                    'status' => $status->value,
                    'moderation' => $moderation->value,
                    'source' => FeatureIdea::SOURCE_VISITOR,
                    'suggested_by' => $voter->id,
                ]);
            }
        }

        FeatureVote::query()->create(['feature_idea_id' => $idea->id, 'user_id' => $voter->id]);

        $this->actingAs($admin)->get('/admin/feature-ideas')->assertOk();
        $this->actingAs($admin)->get('/admin/feature-ideas/create')->assertOk();
        $this->actingAs($admin)->get("/admin/feature-ideas/{$idea->id}/edit")->assertOk();

        $pending = FeatureIdea::query()->where('moderation', ModerationStatus::Pending->value)->firstOrFail();

        Livewire::actingAs($admin)
            ->test(ListFeatureIdeas::class)
            ->callAction(TestAction::make('publish')->table($pending));

        $pending->refresh();
        $this->assertSame(ModerationStatus::Published, $pending->moderation);
        $this->assertNotNull($pending->decided_at);
    }

    #[Test]
    public function the_panel_is_for_admins_only(): void
    {
        $this->actingAs(User::create(['email' => 'shopper@example.test']))
            ->get('/admin/feature-ideas')
            ->assertForbidden();
    }
}
