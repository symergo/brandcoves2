<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\Market;
use App\Models\ProductGroup;
use App\Services\Gift\AutoTagger;
use App\Services\Gift\SuggestionEngine;
use App\Services\Gift\TasteBrief;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Concerns\MakesGiftShelf;
use Tests\TestCase;

/**
 * Automatic tags: an unjudged product gets the interest most judged products
 * in its category carry. See docs/features/gift-tags.md.
 */
class AutoTaggerTest extends TestCase
{
    use MakesGiftShelf;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['giftcoves.auto_tags.min_products' => 4]);
    }

    /** A judged product: tagged, and stamped as every tag write stamps it. */
    private function judged(string $title, array $tags, string $category): ProductGroup
    {
        $group = $this->giftable($title, 3000, $tags, $category);
        $group->forceFill(['gift_tags_at' => now()])->save();

        return $group;
    }

    #[Test]
    public function a_category_the_judges_agree_on_tags_its_unjudged_products(): void
    {
        // Four judged cookbooks: all cooking, three of four reading (0.75), one of four baking (0.25).
        $this->judged('Kookboek een', ['interest:cooking', 'interest:reading', 'recipient:host'], 'Kookboek');
        $this->judged('Kookboek twee', ['interest:cooking', 'interest:reading'], 'Kookboek');
        $this->judged('Kookboek drie', ['interest:cooking', 'interest:reading'], 'Kookboek');
        $this->judged('Kookboek vier', ['interest:cooking', 'interest:baking'], 'Kookboek');

        // A mixed category: no interest reaches the share.
        foreach (['interest:home', 'interest:tech', 'interest:kids', 'interest:fashion'] as $i => $tag) {
            $this->judged("Cadeau {$i}", [$tag], 'Cadeaus');
        }

        // Too few judged to say anything.
        $this->judged('Verrekijker een', ['interest:nature'], 'Verrekijker');

        $new = $this->giftable('Het nieuwe kookboek', 2500, [], 'Kookboek');
        $mixed = $this->giftable('Iets leuks', 2500, [], 'Cadeaus');
        $few = $this->giftable('Verrekijker twee', 2500, [], 'Verrekijker');
        $otherMarket = $this->giftable('Livre de cuisine', 2500, [], 'Kookboek', Market::BeFr);
        // Judged "a gift, no tag fits": somebody looked, so it is not ours to tag.
        $lookedAt = $this->giftable('Kookboek zonder tags', 2500, [], 'Kookboek');
        $lookedAt->forceFill(['gift_tags_at' => now()])->save();

        $tagger = app(AutoTagger::class);

        $this->assertSame(['Kookboek' => ['interest:cooking', 'interest:reading']], $tagger->rules(Market::BeNl));

        // A dry run counts and writes nothing.
        $this->assertSame(['rules' => 1, 'tagged' => 1, 'cleared' => 0], $tagger->apply(Market::BeNl, false));
        $this->assertSame([], $new->fresh()->autoTags());

        $this->assertSame(['rules' => 1, 'tagged' => 1, 'cleared' => 0], $tagger->apply(Market::BeNl, true));
        $this->assertSame(['interest:cooking', 'interest:reading'], $new->fresh()->autoTags());
        $this->assertSame([], $new->fresh()->giftTags());

        foreach ([$mixed, $few, $otherMarket, $lookedAt] as $untouched) {
            $this->assertSame([], $untouched->fresh()->autoTags());
        }

        // A second run changes nothing.
        $this->assertSame(['rules' => 1, 'tagged' => 0, 'cleared' => 0], $tagger->apply(Market::BeNl, true));

        // Somebody judges it: the inferred tags go, the judged ones stand.
        $new->forceFill(['gift_tags' => ['interest:baking'], 'gift_tags_at' => now()])->save();
        $this->assertSame(1, $tagger->apply(Market::BeNl, true)['cleared']);
        $this->assertSame([], $new->fresh()->autoTags());
    }

    #[Test]
    public function the_engine_finds_an_inferred_product_behind_a_judged_one(): void
    {
        $judged = $this->giftable('Hario V60 set', 3500, ['interest:coffee']);
        $inferred = $this->giftable('Glazen karaf met houder', 3500);
        $inferred->forceFill(['auto_tags' => ['interest:coffee']])->save();
        $this->giftable('Wasmachine 8kg', 39999);

        $picks = app(SuggestionEngine::class)->suggest(new TasteBrief(market: Market::BeNl, interests: ['coffee']));

        // Found without a word in common, and counted for the interest at less than an editor's tag.
        $this->assertSame([$judged->id, $inferred->id], array_map(fn ($p) => $p->group->id, $picks));
        $this->assertGreaterThan($picks[1]->breakdown['interest_fit'], $picks[0]->breakdown['interest_fit']);
        $this->assertGreaterThan(0, $picks[1]->breakdown['interest_fit']);

        // Inferred products count towards "enough tagged", so tags-first holds with them.
        config(['giftcoves.gift.tags_first_minimum' => 2]);
        $byText = $this->giftable('Koffiemolen handmatig', 3000);
        $ids = array_map(fn ($p) => $p->group->id, app(SuggestionEngine::class)->suggest(new TasteBrief(market: Market::BeNl, interests: ['coffee'])));
        $this->assertNotContains($byText->id, $ids);
        $this->assertContains($inferred->id, $ids);
    }
}
