<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Ideas\IdeaKey;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * How a hand-typed title folds to the key it is counted under. The fold is
 * what decides whether five people wrote "the same thing", so the cases here
 * are the ways people write one idea differently, and the things that must
 * never make it into a key.
 */
class IdeaKeyTest extends TestCase
{
    /** @return iterable<string, array{string, string, string}> */
    public static function sameIdea(): iterable
    {
        yield 'case and punctuation' => ['Kookworkshop!', 'kookworkshop', 'nl'];
        yield 'a Dutch compound written apart' => ['kook workshop', 'kookworkshop', 'nl'];
        yield 'a Dutch compound with a hyphen' => ['Kook-workshop', 'kookworkshop', 'nl'];
        yield 'an article and a plural' => ['Een kookworkshops', 'kookworkshop', 'nl'];
        yield 'small words for whom' => ['Een kookworkshop voor mijn zus', 'kookworkshopzus', 'nl'];
        yield 'accents' => ['Café-bon', 'cafebon', 'nl'];
        yield 'a year and a price' => ['Spa dag 2026 (€ 80)', 'spadag', 'nl'];
        yield 'an amount glued to money' => ['Cadeaubon 50euro', 'cadeaubon', 'nl'];
        yield 'a size' => ['Sjaal maat XL', 'sjaal', 'nl'];
        yield 'a count' => ['2x concertticket', 'concertticket', 'nl'];
        yield 'English inside Dutch' => ['A voucher for the sauna', 'vouchersauna', 'nl'];
        yield 'French articles and an apostrophe' => ["Un atelier d'oenologie", 'atelieroenologie', 'fr'];
        yield 'Spanish articles' => ['Una entrada para el teatro', 'entradateatro', 'es'];
        yield 'English' => ['Tickets to a cooking class', 'ticketcookingclass', 'en'];
    }

    #[Test]
    #[DataProvider('sameIdea')]
    public function titles_fold_to_their_idea(string $title, string $key, string $language): void
    {
        $this->assertSame($key, IdeaKey::of($title, $language));
    }

    #[Test]
    public function different_spellings_of_one_idea_meet(): void
    {
        $keys = array_map(
            fn (string $t) => IdeaKey::of($t, 'nl'),
            ['Kookworkshop', 'kook workshop', 'Een kook-workshop!', 'KOOKWORKSHOPS', 'kookworkshop 2026'],
        );

        $this->assertCount(1, array_unique($keys));
    }

    /** @return iterable<string, array{string}> */
    public static function notAnIdea(): iterable
    {
        yield 'nothing left' => ['Een voor de'];
        yield 'only numbers' => ['2026 - 12/10'];
        yield 'too short' => ['tv'];
        yield 'a whole description' => ['De rode sjaal zoals die van mama uit Rome, maar dan blauw'];
        yield 'empty' => ['   '];
    }

    #[Test]
    #[DataProvider('notAnIdea')]
    public function some_titles_are_never_an_idea(string $title): void
    {
        $this->assertNull(IdeaKey::of($title, 'nl'));
    }

    #[Test]
    public function a_language_only_drops_its_own_small_words(): void
    {
        // "de" is a small word in Dutch and French, not in English.
        $this->assertSame(['kookworkshop'], IdeaKey::words('de kookworkshop', 'nl'));
        $this->assertSame(['de', 'kookworkshop'], IdeaKey::words('de kookworkshop', 'en'));
    }
}
