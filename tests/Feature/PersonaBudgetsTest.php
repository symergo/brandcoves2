<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\CoveScene;
use App\Enums\Market;
use App\Enums\PickMode;
use App\Models\CovePlan;
use App\Models\DailyPickSet;
use App\Models\ProductGroup;
use App\Services\Cove\EditionBuilder;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Concerns\MakesGiftShelf;
use Tests\TestCase;

/**
 * A persona at three budgets (owner's request 7,
 * docs/features/persona-budgets.md): around 15, 40 and 100, each filled by
 * the suggestion engine from the persona's brief, within its band, in its
 * market, under the curated shelf and never instead of it.
 */
class PersonaBudgetsTest extends TestCase
{
    use MakesGiftShelf;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::today()->setTime(12, 0));
    }

    #[Test]
    public function each_band_holds_only_products_priced_within_it(): void
    {
        $this->forbidAi();

        $curated = $this->persona(['interests' => ['cooking']]);

        // Around 15: five. Around 40: four. Around 100: two, too few for a tab.
        foreach ([900, 1200, 1500, 1900, 2400] as $i => $price) {
            $this->giftable("Kruidenmolen {$i}", $price, ['interest:cooking'], 'Keuken');
        }
        foreach ([2900, 3500, 4200, 5500] as $i => $price) {
            $this->giftable("Koksmes {$i}", $price, ['interest:cooking'], 'Keuken');
        }
        foreach ([7500, 12000] as $i => $price) {
            $this->giftable("Braadpan {$i}", $price, ['interest:cooking'], 'Keuken');
        }
        // Outside every band, in another market, or about something else.
        $tooDear = $this->giftable('Pizzaoven XL', 30000, ['interest:cooking'], 'Keuken');
        $elsewhere = $this->giftable('Koksmes van elders', 1500, ['interest:cooking'], 'Keuken', Market::NlNl);
        $candle = $this->giftable('Geurkaars lavendel', 1500, [], 'Wonen');

        $props = $this->get('/be-nl/gift-ideas/de-thuiskok')->assertOk()->viewData('page')['props'];

        $bands = $props['budgets'];

        $this->assertSame([1500, 4000], array_column($bands, 'around'));

        foreach ($bands as $band) {
            $limits = collect(config('giftcoves.persona_budgets.bands'))->firstWhere('around', $band['around']);

            foreach ($band['items'] as $item) {
                $this->assertGreaterThanOrEqual($limits['min'], $item['price']);
                $this->assertLessThanOrEqual($limits['max'], $item['price']);
            }
        }

        $shown = collect($bands)->flatMap(fn (array $b) => array_column($b['items'], 'groupId'))->all();

        $this->assertCount(5, $bands[0]['items']);
        $this->assertCount(4, $bands[1]['items']);
        $this->assertNotContains($tooDear->id, $shown);
        $this->assertNotContains($elsewhere->id, $shown);
        $this->assertNotContains($candle->id, $shown);

        // Additional to the curated shelf: its products stay on it and are
        // not repeated in a tab.
        $this->assertSame($curated->pluck('id')->sort()->values()->all(), collect($props['finds'])->pluck('groupId')->sort()->values()->all());
        $this->assertSame([], array_intersect($curated->pluck('id')->all(), $shown));
    }

    #[Test]
    public function a_persona_without_a_brief_is_read_from_its_drawing(): void
    {
        $this->forbidAi();

        $this->persona(null, CoveScene::Cooking);

        foreach ([900, 1200, 1500] as $i => $price) {
            $this->giftable("Kruidenmolen {$i}", $price, ['interest:cooking'], 'Keuken');
        }

        $props = $this->get('/be-nl/gift-ideas/de-thuiskok')->assertOk()->viewData('page')['props'];

        $this->assertSame([1500], array_column($props['budgets'], 'around'));
    }

    #[Test]
    public function a_persona_without_a_brief_or_drawing_is_read_from_its_products_tags(): void
    {
        // Three hand-picked products tagged for reading: two or more agree.
        $this->persona(null);

        foreach ([900, 1200, 1500] as $i => $price) {
            $this->giftable("Boekenlegger {$i}", $price, ['interest:reading'], 'Boeken');
        }

        $props = $this->get('/be-nl/gift-ideas/de-thuiskok')->assertOk()->viewData('page')['props'];

        $this->assertSame([1500], array_column($props['budgets'], 'around'));
    }

    #[Test]
    public function a_persona_nothing_describes_shows_no_bands(): void
    {
        $this->persona(null, curatedTags: []);

        foreach ([900, 1200, 1500] as $i => $price) {
            $this->giftable("Kruidenmolen {$i}", $price, ['interest:cooking'], 'Keuken');
        }

        $this->get('/be-nl/gift-ideas/de-thuiskok')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('budgets', []));
    }

    /**
     * A published persona whose shelf is three hand-picked reading products,
     * so the bands' cooking products are all still free.
     *
     * @param  array<string, mixed>|null  $brief
     * @param  list<string>  $curatedTags
     * @return Collection<int, ProductGroup>
     */
    private function persona(?array $brief, ?CoveScene $scene = null, array $curatedTags = ['interest:reading'])
    {
        $curated = collect(range(1, 3))->map(fn (int $i) => $this->giftable("Leesboek {$i}", 2000, $curatedTags, 'Boeken'));

        $plan = CovePlan::create([
            'market' => Market::BeNl->value,
            'kind' => 'persona',
            'slug' => 'de-thuiskok',
            'title' => 'De thuiskok',
            'status' => 'approved',
            'writer' => 'authored',
            'editorial' => 'Voor wie het liefst in de keuken staat.',
            'pick_mode' => PickMode::Locked->value,
            'brief' => $brief,
            'scene' => $scene?->value,
        ]);

        foreach ($curated as $rank => $group) {
            $plan->items()->create(['group_id' => $group->id, 'rank' => $rank + 1]);
        }

        $edition = app(EditionBuilder::class)->buildPersona($plan);

        $this->assertInstanceOf(DailyPickSet::class, $edition);

        return $curated;
    }
}
