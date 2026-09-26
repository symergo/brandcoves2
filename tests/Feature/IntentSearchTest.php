<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\Availability;
use App\Enums\Market;
use App\Enums\ProductStatus;
use App\Enums\Source;
use App\Models\Merchant;
use App\Models\Product;
use App\Models\ProductGroup;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The search page reads a gift search as intent (roadmap step 4): the reading
 * is shown back as chips, and the suggestion engine answers it.
 */
class IntentSearchTest extends TestCase
{
    use RefreshDatabase;

    private const GIFT = 'cadeau voor mijn zus die van tuinieren houdt, €30-€50';

    #[Test]
    public function a_gift_search_is_shown_back_and_answered_by_the_brief(): void
    {
        $gloves = $this->giftable('Tuinhandschoenen van leer', 3900, ['interest:gardening']);
        $this->giftable('Tuinset van staal', 8900, ['interest:gardening']);

        $this->get('/be-nl/search?q='.urlencode(self::GIFT))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('intent.chips.0.label', 'Zus')
                ->where('intent.chips.1.label', 'Tuinieren')
                ->where('intent.budget.min', 3000)
                ->where('intent.budget.max', 5000)
                // Within the budget only: the €89 set is out.
                ->where('results.items', fn ($items) => collect($items)->pluck('id')->all() === [$gloves->id])
                // A sentence gets no word pills, no Amazon search of the
                // sentence, and no offer to watch it.
                ->where('activeTerms', [])
                ->where('amazonSearch', null)
                ->where('watch', null));
    }

    #[Test]
    public function each_chip_carries_the_search_without_it(): void
    {
        $this->giftable('Tuinhandschoenen van leer', 3900, ['interest:gardening']);

        $page = $this->get('/be-nl/search?q='.urlencode(self::GIFT))->viewData('page')['props'];

        $withoutSister = urldecode($page['intent']['chips'][0]['without']);
        $this->assertStringNotContainsString('zus', $withoutSister);
        $this->assertStringContainsString('tuinieren', $withoutSister);
        $this->assertStringContainsString('as=words', $page['intent']['asWordsUrl']);
    }

    #[Test]
    public function the_words_can_be_searched_as_typed(): void
    {
        $this->giftable('Tuinhandschoenen van leer', 3900, ['interest:gardening']);

        $this->get('/be-nl/search?as=words&q='.urlencode(self::GIFT))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('intent', null));
    }

    #[Test]
    public function a_budget_on_a_product_search_becomes_the_price_filter(): void
    {
        $this->get('/be-nl/search?q='.urlencode('koptelefoon onder 100'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('q', 'koptelefoon')
                ->where('filters.max', 100)
                ->where('intent', null));
    }

    #[Test]
    public function a_product_search_is_left_alone(): void
    {
        $this->get('/be-nl/search?q=tuinhandschoenen')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('q', 'tuinhandschoenen')->where('intent', null));
    }

    #[Test]
    public function a_gift_search_the_engine_cannot_answer_falls_back_to_the_words(): void
    {
        // Nothing tagged, nothing in budget: the page still searches rather
        // than coming back empty for having understood.
        $this->get('/be-nl/search?q='.urlencode(self::GIFT))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('intent', null));
    }

    /** @param list<string> $tags */
    private function giftable(string $title, int $price, array $tags): ProductGroup
    {
        $merchant = Merchant::query()->firstOrCreate(
            ['source' => Source::Awin->value, 'external_id' => 'shop'],
            ['name' => 'Shop'],
        );

        $group = ProductGroup::create([
            'market' => Market::BeNl,
            'identity_key' => 'k'.bin2hex(random_bytes(5)),
            'identity_kind' => 'ean',
            'title' => $title,
            'slug' => 'p-'.bin2hex(random_bytes(3)),
            'category' => 'Tuin',
            'image_url' => 'https://img.test/x.jpg',
            'min_price' => $price,
            'merchant_count' => 1,
            'in_stock' => true,
            'giftable' => true,
            'gift_tags' => $tags,
        ]);

        Product::create([
            'source' => Source::Awin,
            'market' => Market::BeNl,
            'merchant_id' => $merchant->id,
            'group_id' => $group->id,
            'external_id' => 'e'.bin2hex(random_bytes(5)),
            'identity_kind' => 'ean',
            'title' => $title,
            'merchant_category' => 'Tuin',
            'price' => $price,
            'currency' => 'EUR',
            'affiliate_url' => 'https://example.test/buy',
            'availability' => Availability::InStock,
            'status' => ProductStatus::Active,
            'identity_key' => $group->identity_key,
        ]);

        return $group;
    }
}
