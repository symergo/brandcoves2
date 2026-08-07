<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\Market;
use App\Jobs\ScoreSerendipity;
use App\Models\ProductGroup;
use App\Services\Discovery\CatalogueStats;
use App\Services\Discovery\SerendipityEngine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The Serendipity Engine.
 *
 * The failure this suite is built around: "surprising" and "nobody stocks it
 * because it is rubbish" are numerically identical if you only measure rarity.
 * Every test below is really asking whether the quality gate held.
 */
class SerendipityTest extends TestCase
{
    use RefreshDatabase;

    private function group(array $attributes = []): ProductGroup
    {
        return ProductGroup::create([
            'market' => Market::BeNl,
            'identity_key' => 'k'.bin2hex(random_bytes(5)),
            'identity_kind' => 'ean',
            'title' => 'Sony draadloze koptelefoon',
            'slug' => 's-'.bin2hex(random_bytes(3)),
            'brand' => 'Sony',
            'category' => 'Audio',
            'image_url' => 'https://img.test/x.jpg',
            'min_price' => 12999,
            'merchant_count' => 4,
            'in_stock' => true,
            'giftable' => true,
            ...$attributes,
        ]);
    }

    /**
     * A catalogue full of the same ordinary thing, so rarity has a baseline to
     * be rare against. Serendipity is a comparison, not a property.
     */
    private function ordinaryCatalogue(int $count = 60): void
    {
        for ($i = 0; $i < $count; $i++) {
            $this->group([
                'title' => "Sony draadloze koptelefoon model {$i}",
                'brand' => 'Sony',
                'category' => 'Audio',
            ]);
        }
    }

    private function engine(): SerendipityEngine
    {
        return new SerendipityEngine(CatalogueStats::build(Market::BeNl));
    }

    #[Test]
    public function an_unusual_product_outscores_the_thing_everyone_stocks(): void
    {
        $this->ordinaryCatalogue();

        $ordinary = $this->group([
            'title' => 'Sony draadloze koptelefoon model X',
            'merchant_count' => 5,
        ]);

        $unusual = $this->group([
            'title' => 'Sous-vide circulator met vacuumsealer',
            'brand' => 'Anova',
            'category' => 'Kookgerei',
            'merchant_count' => 1,
        ]);

        $engine = $this->engine();

        $this->assertGreaterThan(
            $engine->score($ordinary)['score'],
            $engine->score($unusual)['score'],
        );
    }

    #[Test]
    public function rarity_cannot_buy_its_way_past_the_quality_gate(): void
    {
        $this->ordinaryCatalogue();

        /*
         * THE TEST THIS CLASS EXISTS FOR.
         *
         * This row wins every rarity signal there is: a category of one, a
         * brand nobody has heard of, a single merchant, brand new. It is also
         * a phone case with no picture, which is the single worst thing you
         * could put on a page whose promise is "look what we found".
         */
        $junk = $this->group([
            'title' => 'Onbekend merk siliconen hoesje ART-4471B',
            'brand' => 'Noname',
            'category' => 'Hoesjes',
            'image_url' => null,
            'min_price' => 299,
            'merchant_count' => 1,
        ]);

        $this->assertSame(0.0, $this->engine()->score($junk)['score']);
    }

    #[Test]
    public function a_product_the_classifier_rejected_is_never_a_find(): void
    {
        $this->ordinaryCatalogue();

        // Consumables and spare parts are extremely rare *and* extremely
        // unwelcome. Rarity is exactly the wrong measure for them.
        $cartridge = $this->group([
            'title' => 'HP 305XL inktcartridge zwart origineel',
            'category' => 'Printerbenodigdheden',
            'merchant_count' => 1,
            'giftable' => false,
        ]);

        $result = $this->engine()->score($cartridge);

        $this->assertSame(0.0, $result['score']);
        $this->assertArrayHasKey('gated', $result['breakdown']);
    }

    #[Test]
    public function an_out_of_stock_find_is_gated(): void
    {
        $this->ordinaryCatalogue();

        $gone = $this->group([
            'title' => 'Sous-vide circulator met vacuumsealer',
            'category' => 'Kookgerei',
            'in_stock' => false,
            'merchant_count' => 1,
        ]);

        // "Look what we found" followed by "you cannot have it" is worse than
        // showing nothing.
        $this->assertSame(0.0, $this->engine()->score($gone)['score']);
    }

    #[Test]
    public function a_title_made_of_codes_is_gated(): void
    {
        $this->ordinaryCatalogue();

        // Feed artefacts are rare by construction and worthless to show.
        $artefact = $this->group([
            'title' => 'ART.4471-B (zw)',
            'category' => 'Overig',
            'merchant_count' => 1,
        ]);

        $this->assertSame(0.0, $this->engine()->score($artefact)['score']);
    }

    #[Test]
    public function the_breakdown_names_the_reason(): void
    {
        $this->ordinaryCatalogue();

        $find = $this->group([
            'title' => 'Sous-vide circulator met vacuumsealer',
            'brand' => 'Anova',
            'category' => 'Kookgerei',
            'merchant_count' => 1,
        ]);

        $breakdown = $this->engine()->score($find)['breakdown'];

        // The card says "only one shop sells it", which a reader can check.
        // "Surprising!" is a claim they have to take on faith, and they will not.
        $this->assertArrayHasKey('lexical', $breakdown);
        $this->assertArrayHasKey('exclusivity', $breakdown);
        $this->assertSame(1.0 * 15.0, $breakdown['exclusivity']);
    }

    #[Test]
    public function scoring_the_market_writes_scores_and_breakdowns(): void
    {
        $this->ordinaryCatalogue(20);
        $find = $this->group([
            'title' => 'Sous-vide circulator met vacuumsealer',
            'category' => 'Kookgerei',
            'merchant_count' => 1,
        ]);

        (new ScoreSerendipity(Market::BeNl))->handle();

        $find->refresh();

        $this->assertNotNull($find->surprise_score);
        $this->assertGreaterThan(0, $find->surprise_score);
        $this->assertIsArray($find->surprise_breakdown);
    }

    #[Test]
    public function the_surprise_page_only_shows_scored_finds(): void
    {
        $this->ordinaryCatalogue(20);
        $this->group([
            'title' => 'Sous-vide circulator met vacuumsealer',
            'category' => 'Kookgerei',
            'merchant_count' => 1,
        ]);

        (new ScoreSerendipity(Market::BeNl))->handle();

        $this->get('/be-nl/surprise')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->has('finds'));
    }

    #[Test]
    public function a_reroll_does_not_repeat_what_was_already_shown(): void
    {
        for ($i = 0; $i < 40; $i++) {
            $this->group([
                'title' => "Bijzonder keukenapparaat variant {$i}",
                'brand' => "Merk{$i}",
                'category' => "Categorie{$i}",
                'merchant_count' => 1,
            ]);
        }

        (new ScoreSerendipity(Market::BeNl))->handle();

        $first = $this->get('/be-nl/surprise')->viewData('page')['props']['finds'];
        $seen = array_column($first, 'id');

        $second = $this->get('/be-nl/surprise?'.http_build_query(['seen' => $seen]))
            ->viewData('page')['props']['finds'];

        // Pressing "show me another" and getting the same thing back is the
        // fastest way to make a discovery surface feel broken.
        $this->assertSame([], array_intersect($seen, array_column($second, 'id')));
    }
}
