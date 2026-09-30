<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Catalogue\TitleBrand;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class TitleBrandTest extends TestCase
{
    private function brands(): TitleBrand
    {
        return new TitleBrand([
            'philips' => 'Philips',
            'philips hue' => 'Philips Hue',
            'apple' => 'Apple',
            'le creuset' => 'Le Creuset',
            'karcher' => 'Kärcher',
            'sony' => 'Sony',
        ]);
    }

    #[Test]
    public function a_title_that_starts_with_a_known_brand_gets_it(): void
    {
        $this->assertSame('Le Creuset', $this->brands()->infer('Le Creuset Signature Braadpan - 4,2 l - 24 cm'));
        $this->assertSame('Apple', $this->brands()->infer('APPLE Watch Ultra 2 GPS'));
    }

    #[Test]
    public function the_longest_brand_wins(): void
    {
        $this->assertSame('Philips Hue', $this->brands()->infer('Philips Hue Play Gradient Lightstrip'));
        $this->assertSame('Philips', $this->brands()->infer('Philips Senseo Original HD7806'));
    }

    #[Test]
    public function spelling_is_folded_to_the_most_used_one(): void
    {
        $this->assertSame('Kärcher', $this->brands()->infer('KARCHER K2 hogedrukreiniger'));
        $this->assertSame('Kärcher', $this->brands()->infer('Kärcher WD 3 alleszuiger'));
    }

    #[Test]
    public function the_brand_must_end_at_a_word_boundary(): void
    {
        // "Apple" must not claim "Applesauce".
        $this->assertNull($this->brands()->infer('Applesauce kookboek'));
        $this->assertSame('Sony', $this->brands()->infer('Sony-koptelefoon WH-1000XM5'));
    }

    #[Test]
    public function a_brand_anywhere_but_the_start_is_not_the_brand(): void
    {
        // Somebody else's case for a Sony: the reason the rule anchors at the start.
        $this->assertNull($this->brands()->infer('Hoesje voor Sony WH-1000XM5'));
    }

    #[Test]
    public function an_unknown_first_word_gives_nothing(): void
    {
        $this->assertNull($this->brands()->infer('Dames sneakers wit maat 38'));
        $this->assertNull($this->brands()->infer(''));
        $this->assertNull($this->brands()->infer(null));
    }
}
