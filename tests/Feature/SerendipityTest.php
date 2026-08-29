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
            'worth_showing' => true,
            ...$attributes,
        ]);
    }

    /**
     * A category big enough for {@see CatalogueStats::MIN_CATEGORY_SAMPLE}.
     *
     * One insert rather than 250 creates: these tests need the category to have
     * a *distribution*, not particular rows, and doing it a row at a time put
     * seconds on the suite for nothing.
     */
    private function bulkCatalogue(string $category, string $noun, int $count): void
    {
        $rows = [];

        for ($i = 0; $i < $count; $i++) {
            $rows[] = [
                'market' => Market::BeNl->value,
                'identity_key' => "{$category}-{$i}",
                'identity_kind' => 'ean',
                'title' => "Krups {$noun} type {$i}",
                'slug' => mb_strtolower("{$category}-{$noun}-{$i}"),
                'brand' => 'Krups',
                'category' => $category,
                'image_url' => 'https://img.test/x.jpg',
                'min_price' => 9999,
                'merchant_count' => 2,
                'in_stock' => true,
                'giftable' => true,
                'worth_showing' => true,
                'first_seen_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }

        ProductGroup::insert($rows);
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
    public function a_part_number_is_not_a_rare_word(): void
    {
        $this->ordinaryCatalogue();

        // Two listings share this one, one short of the support floor.
        $this->group(['title' => 'Sony koptelefoon kalebasfluit', 'category' => 'Audio']);
        $this->group(['title' => 'Sony koptelefoon kalebasfluit zwart', 'category' => 'Audio']);

        // Three share this one, which is enough to call it a word.
        foreach (range(1, 3) as $i) {
            $this->group(['title' => "Sony koptelefoon vogelhuisje {$i}", 'category' => 'Audio']);
        }

        $stats = CatalogueStats::build(Market::BeNl);

        /*
         * The bug this pins. "Unknown words are skipped" could never fire — the
         * corpus is built from these same titles, so a part number is in it
         * with a count of one, which the log scale reads as maximally rare. It
         * put 61% of the real be-nl catalogue at ≥0.99 on the signal carrying
         * 40 of the 100 rarity points.
         */
        $this->assertSame(
            0.0,
            $stats->lexicalRarity('Sony koptelefoon RX7ZX900', 'Audio'),
            'a part number must not read as a rare word',
        );

        // Same treatment for a token nearly nothing uses, digits or not.
        $this->assertSame(
            0.0,
            $stats->lexicalRarity('Sony koptelefoon kalebasfluit', 'Audio'),
            'two listings is not enough to call something a word',
        );

        $this->assertGreaterThan(
            0.0,
            $stats->lexicalRarity('Sony koptelefoon vogelhuisje', 'Audio'),
            'three listings is',
        );
    }

    #[Test]
    public function a_word_is_judged_against_its_own_category(): void
    {
        $this->bulkCatalogue('Keuken', 'espressomachine', 250);
        $this->bulkCatalogue('Audio', 'koptelefoon', 250);

        $stats = CatalogueStats::build(Market::BeNl);

        /*
         * The signal's original sin: measured across the whole market, "rare"
         * and "we have barely ingested this category" are the same number.
         *
         * "espressomachine" is half the market and *all* of Keuken. Market-wide
         * it looks half-rare; against its own category it is furniture, which
         * is the honest answer and the one that stops an ingestion gap ranking
         * as a discovery.
         */
        $this->assertSame(0.0, $stats->lexicalRarity('Krups espressomachine', 'Keuken'));
        $this->assertGreaterThan(0.0, $stats->lexicalRarity('Krups espressomachine', null));
    }

    #[Test]
    public function a_category_too_small_to_have_an_opinion_falls_back_to_the_market(): void
    {
        $this->bulkCatalogue('Keuken', 'espressomachine', 250);

        // Twelve rows cannot say what is normal for a category: the rarest a
        // word could possibly be is 1/12, and calling that "maximally rare" on
        // the evidence of one listing is how noise reaches the top.
        $this->bulkCatalogue('Optiek', 'telescoop', 12);

        $stats = CatalogueStats::build(Market::BeNl);

        // Scored against the market, where "telescoop" is genuinely uncommon —
        // not against eleven neighbours that all happen to be telescopes.
        $this->assertSame(
            $stats->lexicalRarity('Krups telescoop', null),
            $stats->lexicalRarity('Krups telescoop', 'Optiek'),
        );
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

        // Consumables and fitment are extremely rare *and* extremely unwelcome.
        // Rarity is exactly the wrong measure for them.
        $cartridge = $this->group([
            'title' => 'HP 305XL inktcartridge zwart origineel',
            'category' => 'Printerbenodigdheden',
            'merchant_count' => 1,
            'giftable' => false,
            'worth_showing' => false,
        ]);

        $result = $this->engine()->score($cartridge);

        $this->assertSame(0.0, $result['score']);
        $this->assertArrayHasKey('gated', $result['breakdown']);
    }

    #[Test]
    public function an_expensive_product_is_still_a_find(): void
    {
        $this->ordinaryCatalogue();

        /*
         * The gate reads `worth_showing`, not `giftable`.
         *
         * "Too expensive to suggest as a present" is the gift engine's rule and
         * has no bearing on a surface whose entire promise is "look what we
         * found" — an expensive unusual object is the best thing that can land
         * here. Before the split this whole price band was invisible.
         */
        $telescope = $this->group([
            'title' => 'Celestron NexStar telescoop met azimutmontering',
            'category' => 'Optiek',
            'merchant_count' => 1,
            'min_price' => 129900,
            'giftable' => false,
            'worth_showing' => true,
            'giftable_reason' => 'too_expensive',
        ]);

        $result = $this->engine()->score($telescope);

        $this->assertGreaterThan(0.0, $result['score']);
        $this->assertArrayNotHasKey('gated', $result['breakdown']);
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
