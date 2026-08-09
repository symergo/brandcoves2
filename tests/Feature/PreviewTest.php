<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\Market;
use App\Enums\PublishStatus;
use App\Models\DailyPickSet;
use App\Models\Guide;
use App\Models\User;
use App\Support\PreviewAccess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Reading a guide or an edition before it is published.
 *
 * The load-bearing assertions are the negative ones. A preview is the real page
 * at the real URL — which is what makes it worth having, and what makes an
 * unguarded one a way to read unpublished work by guessing a slug.
 */
class PreviewTest extends TestCase
{
    use RefreshDatabase;

    private function draftGuide(): Guide
    {
        return Guide::create([
            'market' => Market::BeNl,
            'slug' => 'best-coffee-grinders',
            'title' => 'The best coffee grinders',
            'intro' => 'Not finished yet.',
            'status' => PublishStatus::Draft,
        ]);
    }

    private function draftEdition(string $date): DailyPickSet
    {
        return DailyPickSet::create([
            'market' => Market::BeNl,
            'drop_date' => $date,
            'theme_title' => 'Tomorrow',
            'theme_slug' => 'tomorrow',
            'theme_source' => 'curated',
            'status' => PublishStatus::Draft,
        ]);
    }

    #[Test]
    public function a_stranger_cannot_read_a_draft_guide(): void
    {
        $this->draftGuide();

        $this->get('/be-nl/guides/best-coffee-grinders')->assertNotFound();
    }

    #[Test]
    public function an_admin_can(): void
    {
        $this->draftGuide();

        $this->actingAs(User::factory()->create(['is_admin' => true]))
            ->get('/be-nl/guides/best-coffee-grinders')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('preview', true));
    }

    #[Test]
    public function an_ordinary_signed_in_visitor_cannot(): void
    {
        $this->draftGuide();

        // Being signed in is not editorial access. The colleague who reviews the
        // Dutch has an account like everybody else.
        $this->actingAs(User::factory()->create())
            ->get('/be-nl/guides/best-coffee-grinders')
            ->assertNotFound();
    }

    #[Test]
    public function a_signed_link_lets_someone_without_an_account_read_it(): void
    {
        $this->draftGuide();

        $url = PreviewAccess::link('guides.show', [
            'market' => 'be-nl',
            'slug' => 'best-coffee-grinders',
        ]);

        // The point of the feature: the person whose opinion you want on the
        // prose usually does not have an admin account.
        $this->get($url)
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('preview', true));
    }

    #[Test]
    public function a_tampered_link_is_refused(): void
    {
        $this->draftGuide();

        Guide::create([
            'market' => Market::BeNl,
            'slug' => 'other-draft',
            'title' => 'Something else',
            'status' => PublishStatus::Draft,
        ]);

        $url = PreviewAccess::link('guides.show', [
            'market' => 'be-nl',
            'slug' => 'best-coffee-grinders',
        ]);

        // The signature covers the whole URL, so a link for one draft cannot be
        // edited into a link for another.
        $this->get(str_replace('best-coffee-grinders', 'other-draft', $url))
            ->assertNotFound();
    }

    #[Test]
    public function a_preview_is_never_indexable(): void
    {
        $this->draftGuide();

        $response = $this->actingAs(User::factory()->create(['is_admin' => true]))
            ->get('/be-nl/guides/best-coffee-grinders')
            ->assertOk();

        /*
         * A crawler following a shared preview link would otherwise put
         * unpublished copy in the index, at the address the finished piece is
         * going to use.
         */
        $this->assertStringContainsString('noindex', $response->getContent());
    }

    #[Test]
    public function tomorrows_edition_is_a_404_for_a_player_and_readable_in_a_preview(): void
    {
        $tomorrow = now()->addDay()->toDateString();
        $this->draftEdition($tomorrow);

        // Guessing tomorrow's puzzle by URL is an obvious hole in a daily game.
        $this->get("/be-nl/daily/{$tomorrow}")->assertNotFound();

        $this->actingAs(User::factory()->create(['is_admin' => true]))
            ->get("/be-nl/daily/{$tomorrow}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('preview', true));
    }

    #[Test]
    public function a_published_page_is_not_labelled_a_preview(): void
    {
        Guide::create([
            'market' => Market::BeNl,
            'slug' => 'live-guide',
            'title' => 'A live guide',
            'status' => PublishStatus::Published,
            'published_at' => now()->subDay(),
        ]);

        // An admin reading the live site is reading the live site.
        $this->actingAs(User::factory()->create(['is_admin' => true]))
            ->get('/be-nl/guides/live-guide')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('preview', false));
    }
}
