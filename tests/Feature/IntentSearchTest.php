<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Concerns\MakesTaggedGroups;
use Tests\TestCase;

/**
 * The search page reads a gift search as intent (roadmap step 4): the reading
 * is shown back as chips, and the suggestion engine answers it.
 */
class IntentSearchTest extends TestCase
{
    use MakesTaggedGroups;
    use RefreshDatabase;

    private const GIFT = 'cadeau voor mijn zus die van tuinieren houdt, €30-€50';

    #[Test]
    public function a_gift_search_is_shown_back_and_answered_by_the_brief(): void
    {
        $gloves = $this->taggedGroup('Tuinhandschoenen van leer', ['interest:gardening'], 3900, category: 'Tuin');
        $this->taggedGroup('Tuinset van staal', ['interest:gardening'], 8900, category: 'Tuin');

        $this->get('/be-nl/search?q='.urlencode(self::GIFT))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('intent.chips.0.label', 'Zus')
                ->where('intent.chips.1.label', 'Tuinieren')
                ->where('intent.budget.min', 3000)
                ->where('intent.budget.max', 5000)
                // Within the budget only: the €89 set is out.
                ->where('results.items', fn ($items) => collect($items)->pluck('id')->all() === [$gloves->id])
                // A sentence gets no Amazon search of the
                // sentence, and no offer to watch it.
                ->where('amazonSearch', null)
                ->where('watch', null));
    }

    /**
     * The reading as one sentence (owner, 2026-10-25), instead of "We lezen
     * dit als" over chips. The person as typed, with "je".
     */
    #[Test]
    public function the_reading_is_one_sentence(): void
    {
        $this->taggedGroup('Tuinhandschoenen van leer', ['interest:gardening'], 3900, category: 'Tuin');

        $this->get('/be-nl/search?q='.urlencode(self::GIFT))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where(
                'intent.sentence',
                'We geven je cadeau-ideeën voor je zus, die houdt van tuinieren, tussen €30 en €50.',
            ));
    }

    #[Test]
    public function each_chip_carries_the_search_without_it(): void
    {
        $this->taggedGroup('Tuinhandschoenen van leer', ['interest:gardening'], 3900, category: 'Tuin');

        $page = $this->get('/be-nl/search?q='.urlencode(self::GIFT))->viewData('page')['props'];

        $withoutSister = urldecode($page['intent']['chips'][0]['without']);
        $this->assertStringNotContainsString('zus', $withoutSister);
        $this->assertStringContainsString('tuinieren', $withoutSister);
        $this->assertStringContainsString('as=words', $page['intent']['asWordsUrl']);
    }

    #[Test]
    public function the_words_can_be_searched_as_typed(): void
    {
        $this->taggedGroup('Tuinhandschoenen van leer', ['interest:gardening'], 3900, category: 'Tuin');

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
}
