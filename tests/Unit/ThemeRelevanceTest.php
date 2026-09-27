<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Cove\ThemeRelevance;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The rule that decides whether an uncurated Daily may show a product.
 *
 * Titles are the real ones, or close to them: the 27 Sep 2026 World Tourism
 * Day editions on be-nl and nl-nl, and the koffer shelf of the be-nl catalogue.
 * See docs/features/daily-cove.md.
 */
class ThemeRelevanceTest extends TestCase
{
    private const TOURISM = ['koffer', 'reisadapter', 'nekkussen'];

    /** @return iterable<string, array{string, string|null}> */
    public static function suitcases(): iterable
    {
        yield 'the word on its own' => ["Samsonite S'Cure Spinner 55cm Koffer", null];
        yield 'a plural' => ['NoBoringSuitcases.com - Kofferset 3 delig - Koffers met TSA slot', null];
        yield 'a travel compound' => ['Princess Traveller Singapore - Reiskoffer - ABS - Sage - Large - 78cm', null];
        yield 'a carry-on compound' => ['Samsonite Handbagagekoffer 55x40x20 Zwart', null];
        yield 'a roller compound' => ['Rolkoffer lichtgewicht met 4 wielen', null];
        yield 'the category, when the title says neither' => ['Eastpak Tranverz XXS 2-wielige weekendtas 48 cm', 'Reiskoffer'];
        yield 'the other words of the theme' => ['Universele reisadapter wereldwijd met USB-C', null];
        yield 'a compound of another word' => ['Traagschuim reisnekkussen grijs', null];
        yield 'an accessory for a suitcase is still about one' => ['Packing cubes set 7-delig - organizer voor koffer en backpack', 'Packing Cube'];
    }

    /** @return iterable<string, array{string, string|null}> */
    public static function strangers(): iterable
    {
        // The be-nl edition.
        yield 'a tool case' => ['STANLEY gereedschapkoffer voor onderhoud 142-delig STMT9810', null];
        yield 'a tool case, with the linking s' => ['Stanley gereedschapskoffer voor onderhoud 142-delig', null];
        yield 'hole saws in a case' => ['IRWIN gatenzagen set 9-delig in koffer', 'Gatzagen'];
        yield 'a vibrator, whose description mentions a suitcase' => ['Sensual Desire Red Lady 3-in-1 Vibrator - Stil & Waterdicht', 'Vibrator'];
        yield 'a light kit' => ['Nanoleaf Essentials Lightstrip vitrine verlichting', null];
        yield 'a tablet car holder' => ['RX Goods Premium Reistafel met Tablethouder - Auto Organizer', 'Auto-organizer'];
        // The nl-nl edition.
        yield 'a drill in a case' => ['DeWalt DCD796P2 accu schroefboormachine 18V in TSTAK koffer', null];
        yield 'a laser level with a case' => ['Bosch GLL 3-80 lijnlaser met koffer', null];
        // The rest of the be-nl koffer shelf.
        yield 'a toy doctor case' => ['Bumba dokterskoffer', null];
        yield 'a craft case' => ['Lena Creatief Jumbo Knutselkoffer 800 onderdelen', null];
        yield 'a collector case' => ['Bluey Play & Go verzamelkoffer', null];
        yield 'a microscope with a case' => ['Bresser Junior Microscoop set 40x-1024x met koffer', null];
        yield 'dumbbells including a storage case' => ['HUSH Sport dumbbells 6 kg inclusief handige opbergkoffer', 'Dumbbells'];
        yield 'a chest freezer' => ['Diepvries CHAE 2002C', 'Kofferdiepvriezers'];
        yield 'a luggage label' => ['Travelhawk Kofferlabels PU Leather', null];
        yield 'a tool case by category' => ['Stanley Fatmax 450x350', 'Gereedschapskoffers'];
    }

    #[Test]
    #[DataProvider('suitcases')]
    public function a_real_suitcase_or_travel_product_matches(string $title, ?string $category): void
    {
        $this->assertTrue((new ThemeRelevance(self::TOURISM))->matches($title, $category), $title);
    }

    #[Test]
    #[DataProvider('strangers')]
    public function what_world_tourism_day_published_by_mistake_does_not(string $title, ?string $category): void
    {
        $this->assertFalse((new ThemeRelevance(self::TOURISM))->matches($title, $category), $title);
    }

    #[Test]
    public function a_short_word_counts_only_whole_and_only_in_the_category(): void
    {
        $hats = new ThemeRelevance(['muts', 'pet', 'hoed']);

        // be-nl's highest-scoring "pet" titles on hat day.
        $this->assertFalse($hats->matches('PETCUBE PET MONITORING CAMERA', 'IP Camera'));
        $this->assertFalse($hats->matches('BRITA PACK 2 PET BOTTLES', 'Accessoires sodamachine'));
        $this->assertFalse($hats->matches('Yamaha YTR-2330 trompet', 'Trompetten'), '"pet" ends "trompet"');

        // A cap, filed as one.
        $this->assertTrue($hats->matches('Zomerse Baseball Cap - Verstelbare Sport Pet', 'Pet'));
        $this->assertTrue($hats->matches('Gebreide cap van wol', 'Petten'));

        // Four letters may be the end of a compound, in a title.
        $this->assertTrue($hats->matches('Warme wintermuts', null));

        $backup = new ThemeRelevance(['nas']);
        $this->assertTrue($backup->matches('Synology DS224+', 'NAS'));
        $this->assertFalse($backup->matches('Gedroogde ananas 500g', 'Ananas'));
    }

    #[Test]
    public function a_category_that_names_the_theme_ranks_above_a_title_that_does(): void
    {
        $travel = new ThemeRelevance(self::TOURISM);

        $this->assertSame(
            ThemeRelevance::CATEGORY,
            $travel->strength('Princess Traveller Singapore - Large - 78cm', 'Reiskoffer'),
        );
        // What outscored every real suitcase on be-nl with a title-only match.
        $this->assertSame(ThemeRelevance::TITLE, $travel->strength('Smartwares brandwerende koffer', 'wonen'));
        $this->assertSame(ThemeRelevance::NONE, $travel->strength('Bumba dokterskoffer', null));
    }

    #[Test]
    public function a_query_of_two_words_matches_them_apart_or_as_one_compound(): void
    {
        $pizza = new ThemeRelevance(['pizza oven']);

        $this->assertTrue($pizza->matches('Ooni Koda 12 pizza oven op gas', null));
        $this->assertTrue($pizza->matches('Buiten pizzaoven met steen', null));
        $this->assertFalse($pizza->matches('Pizzasnijder RVS', null), 'one of the two words is not a match');
    }

    #[Test]
    public function plurals_and_accents_fold(): void
    {
        $cards = new ThemeRelevance(['ruilkaarten']);
        $this->assertTrue($cards->matches('Pokémon ruilkaart Pikachu', null), 'singular of a plural query');

        $cafe = new ThemeRelevance(['cafe']);
        $this->assertTrue($cafe->matches('Café servies 12-delig', null));
    }

    #[Test]
    public function the_database_narrowing_never_drops_what_the_rule_keeps(): void
    {
        // Every word the SQL narrows on must be a substring of every form the
        // rule accepts, or the narrowing would drop real matches first.
        $relevance = new ThemeRelevance(['ruilkaarten', 'pizza oven', 'Koffer']);

        $this->assertSame([['ruilkaart'], ['pizza', 'oven'], ['koffer']], $relevance->terms());
    }

    #[Test]
    public function no_words_is_no_theme(): void
    {
        $none = new ThemeRelevance(['', '  ', '-']);

        $this->assertFalse($none->hasTheme());
        $this->assertFalse($none->matches('Anything at all', 'Anything'));
    }
}
