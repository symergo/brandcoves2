<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\Market;
use App\Models\ProductGroup;
use App\Services\Ai\AiClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * A product's interests and taste poles on its page, each a way into Find a
 * gift with that answer filled in (owner, 2026-09-30).
 */
class ProductGiftTagsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Invariant 1: a page view never reaches the model.
        $this->mock(AiClient::class, function ($mock): void {
            $mock->shouldNotReceive('json');
            $mock->shouldNotReceive('chat');
        });
    }

    #[Test]
    public function the_page_shows_an_editors_interests_and_vibes_as_gift_searches(): void
    {
        $group = $this->product('Espressomachine', ['interest:coffee', 'preference:design', 'recipient:father', 'vibe:practical']);

        $this->get("/be-nl/p/{$group->id}/{$group->slug}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('giftTags.interests.0.value', 'coffee')
                ->where('giftTags.interests.0.url', '/be-nl/gift?interest=coffee')
                ->where('giftTags.vibes.0.value', 'design')
                ->where('giftTags.vibes.0.url', '/be-nl/gift?vibe=design')
                // A recipient is not shown, and a retired value is dropped.
                ->has('giftTags.interests', 1)
                ->has('giftTags.vibes', 1));
    }

    #[Test]
    public function a_chip_opens_find_a_gift_with_ideas_for_that_answer(): void
    {
        $coffee = $this->product('Koffiemolen', ['interest:coffee']);

        $this->get('/be-nl/gift?interest=coffee')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Gift/Wizard')
                ->where('brief.interests', ['coffee'])
                ->where('picks', fn ($picks) => collect($picks)->pluck('id')->contains($coffee->id)));

        $this->get('/be-nl/gift?vibe=design')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('brief.preferences', ['design']));

        // Not in the vocabulary: the empty wizard, as if it were not asked.
        $this->get('/be-nl/gift?interest=nonsense')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('picks', null));
    }

    private function product(string $title, array $tags): ProductGroup
    {
        return ProductGroup::factory()->forMarket(Market::BeNl)->priced(3000)->create([
            'gift_tags' => $tags,
            'title' => $title.' '.Str::random(4),
        ]);
    }
}
