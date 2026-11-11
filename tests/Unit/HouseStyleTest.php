<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Editorial\HouseStyle;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The two punctuation habits a language model brings to an article, and what
 * happens to them on the way to the database.
 *
 * No `Tests\TestCase`: this class touches no container, no config and no
 * database, and a unit test that boots the framework to check a regular
 * expression is a slow test that can fail for reasons unrelated to the thing it
 * is testing.
 */
class HouseStyleTest extends TestCase
{
    /** @return iterable<string, array{string, string}> */
    public static function prose(): iterable
    {
        yield 'a spaced em dash becomes a spaced hyphen' => [
            'Not because anything is wrong — simply because you changed your mind.',
            'Not because anything is wrong - simply because you changed your mind.',
        ];

        // The form a straight `str_replace` gets wrong: `word—word` becomes
        // `word-word`, which reads as a compound noun rather than a break, and
        // quietly changes what the sentence says.
        yield 'an unspaced em dash is spaced' => ['two years—a national extension', 'two years - a national extension'];

        yield 'a dash that opens a line' => ["A list:\n— one thing", "A list:\n- one thing"];
        yield 'a dash at the end' => ['and then —', 'and then -'];

        // An en dash is a range, not a parenthetical, and a hyphen inside a
        // word is part of the word. Neither is the habit this exists for.
        yield 'a hyphen and an en dash are left alone' => ['Audio-Technica, 2020–2024', 'Audio-Technica, 2020–2024'];

        // Prose keeps its emphasis, because `CoveMarkup` renders it as `<strong>`.
        yield 'bold markup is kept' => ['**There is no fixed term.**', '**There is no fixed term.**'];
    }

    #[Test]
    #[DataProvider('prose')]
    public function prose_is_put_into_house_style(string $in, string $out): void
    {
        $this->assertSame($out, HouseStyle::prose($in));
    }

    /** Two passes must not differ from one, or the tidy command never settles. */
    #[Test]
    public function it_is_idempotent(): void
    {
        $once = HouseStyle::prose('one — two — three');

        $this->assertSame($once, HouseStyle::prose($once));
        $this->assertSame('one - two - three', $once);
    }

    /**
     * A title, a blurb or a verdict is printed as a React text node, so the
     * asterisks would reach the reader.
     *
     * @return iterable<string, array{string, string}>
     */
    public static function plain(): iterable
    {
        yield 'the asterisks come off' => ['**There is no fixed term.**', 'There is no fixed term.'];
        yield 'the asterisks come off mid-line' => ['Best for **small kitchens**', 'Best for small kitchens'];

        // "5*" and a footnote marker are not markup, and deleting them would
        // change what the line says.
        yield 'an unpaired asterisk is left alone' => ['rated 5* by buyers', 'rated 5* by buyers'];
        yield 'an unclosed pair is left alone' => ['opened with ** and never closed', 'opened with ** and never closed'];

        yield 'two bold runs are not merged' => ['**one** and **two**, plain', 'one and two, plain'];
    }

    #[Test]
    #[DataProvider('plain')]
    public function plain_text_loses_its_markup(string $in, string $out): void
    {
        $this->assertSame($out, HouseStyle::plain($in));
    }

    #[Test]
    public function null_survives(): void
    {
        $this->assertNull(HouseStyle::prose(null));
        $this->assertNull(HouseStyle::plain(null));
    }
}
