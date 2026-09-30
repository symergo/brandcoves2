<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Enums\Gender;
use App\Services\Gift\TitleGender;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/** "Dames" is for her, "Heren" for him (owner, 2026-09-30). Titles from production. */
class TitleGenderTest extends TestCase
{
    /** @return iterable<string, array{string, Gender|null}> */
    public static function titles(): iterable
    {
        yield 'dames' => ['Dr. Martens 1460 Smooth Dames Veterboots - Zwart - Maat 37', Gender::Female];
        yield 'heren' => ['UGG ASCOT Heren Slippers - Chestnut - Maat 42', Gender::Male];
        yield 'femmes' => ['Calvin Klein Eternity Femmes 100 ml', Gender::Female];
        yield 'homme' => ['Billabong Arch Sweatshirt Blauw M Homme', Gender::Male];
        yield "women's" => ["Teensokken - Women's - Teen Sokken - 6 Paar", Gender::Female];
        yield "men's" => ["NOLOGO Men's Work Briefs Microfibre", Gender::Male];
        yield 'both' => ['Heren- en damesschoenen wandel', null];
        yield 'unisex' => ['Unisex hoodie voor dames en heren', null];
        yield 'notre-dame' => ['LEGO Architecture Notre-Dame van Parijs 21061', null];
        yield 'mens erger je niet' => ['Mens erger je niet bordspel', null];
        yield 'a dutch compound' => ['Gazelle damesfiets 28 inch', Gender::Female];
        yield 'heerenveen is not heren' => ['sc Heerenveen supporterssjaal', null];
        yield 'nothing' => ['Moccamaster KBG Select', null];
    }

    #[Test]
    #[DataProvider('titles')]
    public function a_title_says_who_it_is_for(string $title, ?Gender $expected): void
    {
        $this->assertSame($expected, TitleGender::of($title));
    }
}
