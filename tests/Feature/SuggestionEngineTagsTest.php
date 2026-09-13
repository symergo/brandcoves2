<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\Availability;
use App\Enums\Market;
use App\Enums\ProductStatus;
use App\Enums\Source;
use App\Enums\Vibe;
use App\Models\Merchant;
use App\Models\Product;
use App\Models\ProductGroup;
use App\Services\Gift\SuggestionEngine;
use App\Services\Gift\TasteBrief;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * An editor's gift tags reach the Whisperer.
 *
 * A tag is a decision where a text match is a guess: the engine retrieves a
 * tagged product whether or not its title agrees, scores the tag at full
 * strength on its slot, and answers the occasion, recipient, vibe and values
 * questions from tags before it looks for words.
 */
class SuggestionEngineTagsTest extends TestCase
{
    use RefreshDatabase;

    private Merchant $merchant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->merchant = Merchant::create([
            'source' => Source::Awin->value,
            'external_id' => 'shop',
            'name' => 'Shop',
        ]);
    }

    /** @param list<string> $tags */
    private function giftable(string $title, int $price, array $tags = [], ?string $category = 'Divers'): ProductGroup
    {
        $group = ProductGroup::create([
            'market' => Market::BeNl,
            'identity_key' => 'k'.bin2hex(random_bytes(5)),
            'identity_kind' => 'ean',
            'title' => $title,
            'slug' => 'p-'.bin2hex(random_bytes(3)),
            'category' => $category,
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
            'merchant_id' => $this->merchant->id,
            'group_id' => $group->id,
            'external_id' => 'e'.bin2hex(random_bytes(5)),
            'identity_kind' => 'ean',
            'title' => $title,
            'merchant_category' => $category,
            'price' => $price,
            'currency' => 'EUR',
            'affiliate_url' => 'https://example.test/buy',
            'availability' => Availability::InStock,
            'status' => ProductStatus::Active,
            'identity_key' => $group->identity_key,
        ]);

        return $group;
    }

    private function engine(): SuggestionEngine
    {
        return app(SuggestionEngine::class);
    }

    #[Test]
    public function a_tagged_product_is_found_without_a_word_in_common(): void
    {
        // Nothing in "Hario V60 set" answers the coffee angle queries; the
        // editor's tag does. A decision must not need the guess to agree.
        $tagged = $this->giftable('Hario V60 set', 3500, ['interest:coffee']);
        $this->giftable('Wasmachine 8kg', 39999);

        $picks = $this->engine()->suggest(new TasteBrief(market: Market::BeNl, interests: ['coffee']));

        $this->assertSame([$tagged->id], array_map(fn ($p) => $p->group->id, $picks));
        $this->assertEqualsWithDelta(40.0, $picks[0]->breakdown['interest_fit'], 0.01);
        $this->assertSame('coffee', $picks[0]->primaryInterest);
    }

    #[Test]
    public function a_tag_on_the_first_interest_outranks_a_text_match_on_the_second(): void
    {
        $tagged = $this->giftable('Handgemaakte mok', 3500, ['interest:coffee']);
        $this->giftable('Smartwatch sport GPS', 3500);

        $picks = $this->engine()->suggest(new TasteBrief(
            market: Market::BeNl,
            interests: ['coffee', 'tech'],
            limit: 2,
        ));

        $this->assertSame($tagged->id, $picks[0]->group->id);
    }

    #[Test]
    public function a_product_answering_more_of_the_brief_beats_one_answering_the_first_interest_only(): void
    {
        /*
         * The owner's ask: matching is vectorial. Four interests, three
         * products: one tagged for the first alone, one for the first two,
         * one for all four. Same price, same category, so interest fit is
         * the only difference — and coverage decides.
         */
        $first = $this->giftable('Alleen koffie', 3000, ['interest:coffee']);
        $two = $this->giftable('Koffie en koken', 3000, ['interest:coffee', 'interest:cooking']);
        $all = $this->giftable('Alles', 3000, ['interest:coffee', 'interest:cooking', 'interest:baking', 'interest:drinks']);
        $last = $this->giftable('Zonder koffie', 3000, ['interest:cooking', 'interest:baking', 'interest:drinks']);

        $picks = $this->engine()->suggest(new TasteBrief(
            market: Market::BeNl,
            interests: ['coffee', 'cooking', 'baking', 'drinks'],
            limit: 4,
        ));

        $fit = [];

        foreach ($picks as $pick) {
            $fit[$pick->group->id] = round($pick->breakdown['interest_fit'], 1);
        }

        // Weights 1.0, 0.83, 0.67, 0.5; half best, half coverage. Three of
        // four without the first beats the first alone: more of the brief
        // wins, which is what vectorial means here.
        $this->assertSame(40.0, $fit[$all->id]);
        $this->assertSame(32.2, $fit[$two->id]);
        $this->assertSame(30.0, $fit[$last->id]);
        $this->assertSame(26.7, $fit[$first->id]);
        $this->assertSame($all->id, $picks[0]->group->id);
    }

    #[Test]
    public function an_occasion_tag_counts(): void
    {
        $forChristmas = $this->giftable('Adventskalender koffie', 3000, ['interest:coffee', 'occasion:christmas']);
        $plain = $this->giftable('Koffiebonen proefpakket', 3000, ['interest:coffee']);

        $picks = $this->engine()->suggest(new TasteBrief(
            market: Market::BeNl,
            interests: ['coffee'],
            occasion: 'christmas',
            limit: 2,
        ));

        $this->assertSame($forChristmas->id, $picks[0]->group->id);
        $this->assertEqualsWithDelta(5.0, $picks[0]->breakdown['occasion'], 0.01);
        $this->assertEqualsWithDelta(2.25, $picks[1]->breakdown['occasion'], 0.01);
    }

    #[Test]
    public function the_recipient_tag_meets_the_brief(): void
    {
        $forMum = $this->giftable('Zijden sjaal', 4900, ['interest:fashion', 'recipient:mother']);
        $forDad = $this->giftable('Zijden das', 4900, ['interest:fashion', 'recipient:father']);
        $untagged = $this->giftable('Zijden zakdoek', 4900, ['interest:fashion']);

        $picks = $this->engine()->suggest(new TasteBrief(
            market: Market::BeNl,
            interests: ['fashion'],
            relationship: 'mother',
            limit: 3,
        ));

        $fit = [];

        foreach ($picks as $pick) {
            $fit[$pick->group->id] = $pick->breakdown['recipient_fit'];
        }

        // Out of 5: for her, for somebody else, for nobody in particular.
        $this->assertEqualsWithDelta(5.0, $fit[$forMum->id], 0.01);
        $this->assertEqualsWithDelta(2.25, $fit[$forDad->id], 0.01);
        $this->assertEqualsWithDelta(2.5, $fit[$untagged->id], 0.01);
        $this->assertSame($forMum->id, $picks[0]->group->id);
    }

    #[Test]
    public function the_age_tag_meets_the_brief_too(): void
    {
        $forTeen = $this->giftable('Skateboard', 4900, ['interest:outdoors', 'age:teen']);
        $forToddler = $this->giftable('Loopfiets', 4900, ['interest:outdoors', 'age:toddler']);

        $picks = $this->engine()->suggest(new TasteBrief(
            market: Market::BeNl,
            interests: ['outdoors'],
            ageBand: 'teen',
            limit: 2,
        ));

        $this->assertSame($forTeen->id, $picks[0]->group->id);
        $this->assertEqualsWithDelta(5.0, $picks[0]->breakdown['recipient_fit'], 0.01);
        $this->assertEqualsWithDelta(2.25, $picks[1]->breakdown['recipient_fit'], 0.01);
    }

    #[Test]
    public function vibe_and_values_tags_answer_before_the_title_does(): void
    {
        $tagged = $this->giftable('Mok', 2500, ['interest:coffee', 'vibe:playful', 'values:handmade']);
        $plain = $this->giftable('Mok blauw', 2500, ['interest:coffee']);

        $picks = $this->engine()->suggest(new TasteBrief(
            market: Market::BeNl,
            interests: ['coffee'],
            vibe: Vibe::Playful,
            values: ['handmade'],
            limit: 2,
        ));

        $by = [];

        foreach ($picks as $pick) {
            $by[$pick->group->id] = $pick->breakdown;
        }

        $this->assertEqualsWithDelta(10.0, $by[$tagged->id]['vibe'], 0.01);
        $this->assertEqualsWithDelta(10.0, $by[$tagged->id]['values'], 0.01);
        $this->assertLessThan(10.0, $by[$plain->id]['vibe']);
        $this->assertLessThan(10.0, $by[$plain->id]['values']);
    }
}
