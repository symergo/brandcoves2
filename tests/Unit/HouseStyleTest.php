<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Editorial\HouseStyle;
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
    #[Test]
    public function it_replaces_a_spaced_em_dash_with_a_spaced_hyphen(): void
    {
        $this->assertSame(
            'Not because anything is wrong - simply because you changed your mind.',
            HouseStyle::prose('Not because anything is wrong — simply because you changed your mind.'),
        );
    }

    /**
     * The form a straight `str_replace` gets wrong.
     *
     * `word—word` becomes `word-word`, which reads as a compound noun rather
     * than a break, and quietly changes what the sentence says.
     */
    #[Test]
    public function it_spaces_an_unspaced_em_dash(): void
    {
        $this->assertSame('two years - a national extension', HouseStyle::prose('two years—a national extension'));
    }

    #[Test]
    public function it_handles_a_dash_that_opens_a_line(): void
    {
        $this->assertSame("A list:\n- one thing", HouseStyle::prose("A list:\n— one thing"));
    }

    #[Test]
    public function it_handles_a_dash_at_the_end(): void
    {
        $this->assertSame('and then -', HouseStyle::prose('and then —'));
    }

    /** Two passes must not differ from one, or the tidy command never settles. */
    #[Test]
    public function it_is_idempotent(): void
    {
        $once = HouseStyle::prose('one — two — three');

        $this->assertSame($once, HouseStyle::prose($once));
        $this->assertSame('one - two - three', $once);
    }

    #[Test]
    public function it_leaves_a_hyphen_and_an_en_dash_alone(): void
    {
        // An en dash is a range, not a parenthetical, and a hyphen inside a
        // word is part of the word. Neither is the habit this exists for.
        $this->assertSame('Audio-Technica, 2020–2024', HouseStyle::prose('Audio-Technica, 2020–2024'));
    }

    /**
     * Prose keeps its emphasis, because `CoveMarkup` renders it as `<strong>`.
     */
    #[Test]
    public function prose_keeps_bold_markup(): void
    {
        $this->assertSame('**There is no fixed term.**', HouseStyle::prose('**There is no fixed term.**'));
    }

    /**
     * A title, a blurb or a verdict is printed as a React text node, so the
     * asterisks would reach the reader.
     */
    #[Test]
    public function plain_takes_the_asterisks_off(): void
    {
        $this->assertSame('There is no fixed term.', HouseStyle::plain('**There is no fixed term.**'));
        $this->assertSame('Best for small kitchens', HouseStyle::plain('Best for **small kitchens**'));
    }

    #[Test]
    public function plain_leaves_an_unpaired_asterisk_alone(): void
    {
        // "5*" and a footnote marker are not markup, and deleting them would
        // change what the line says.
        $this->assertSame('rated 5* by buyers', HouseStyle::plain('rated 5* by buyers'));
        $this->assertSame('opened with ** and never closed', HouseStyle::plain('opened with ** and never closed'));
    }

    #[Test]
    public function plain_does_not_merge_two_bold_runs(): void
    {
        $this->assertSame('one and two, plain', HouseStyle::plain('**one** and **two**, plain'));
    }

    #[Test]
    public function null_survives(): void
    {
        $this->assertNull(HouseStyle::prose(null));
        $this->assertNull(HouseStyle::plain(null));
    }
}
