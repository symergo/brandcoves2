<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Catalogue\Excerpt;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The merchant description field, which is the least disciplined column in the
 * catalogue. Every case here is a shape a real feed row has arrived in.
 */
class ExcerptTest extends TestCase
{
    #[Test]
    public function it_keeps_plain_prose(): void
    {
        $this->assertSame(
            'A folding travel kettle that boils half a litre in four minutes.',
            Excerpt::make('A folding travel kettle that boils half a litre in four minutes.'),
        );
    }

    #[Test]
    public function it_does_not_weld_words_together_across_tags(): void
    {
        // `strip_tags()` alone gives "BluetoothANC met 30 uur accu" — two real
        // words fused into a nonsense one, which is worse than the markup.
        $this->assertSame(
            'Bluetooth ANC met 30 uur accuduur en snelladen',
            Excerpt::make('<ul><li>Bluetooth</li><li>ANC</li></ul>met 30 uur accuduur en snelladen'),
        );
    }

    #[Test]
    public function it_decodes_entities_after_stripping_not_before(): void
    {
        // Decoding first would hand `strip_tags()` markup it never saw, and
        // "&lt;b&gt;" would vanish along with the words around it.
        $this->assertSame(
            'Een set van 6 glazen, "tumbler" model, vaatwasserbestendig',
            Excerpt::make('Een set van 6 glazen, &quot;tumbler&quot; model, vaatwasserbestendig'),
        );
    }

    #[Test]
    public function it_collapses_every_kind_of_whitespace(): void
    {
        $this->assertSame(
            'Handgemaakte keramische mok met een matte glazuurlaag',
            Excerpt::make("Handgemaakte\u{00A0}keramische   mok\n\nmet een matte\tglazuurlaag"),
        );
    }

    #[Test]
    public function a_scrap_is_not_a_description(): void
    {
        // Real values from this column. Under a product title these read as a
        // rendering bug; absent, they read as nothing at all.
        foreach ([null, '', '   ', 'Zwart', 'One size', '<p></p>'] as $scrap) {
            $this->assertNull(Excerpt::make($scrap), var_export($scrap, true).' should not survive');
        }
    }

    #[Test]
    public function it_truncates_on_a_word_boundary(): void
    {
        $long = str_repeat('draadloze koptelefoon ', 20);

        $result = Excerpt::make($long, 60);

        $this->assertNotNull($result);
        $this->assertStringEndsWith('…', $result);
        // A cut mid-word reads as corrupted data rather than as a summary.
        $this->assertStringNotContainsString('draadlo…', $result);
        $this->assertLessThanOrEqual(61, mb_strlen($result));
    }
}
