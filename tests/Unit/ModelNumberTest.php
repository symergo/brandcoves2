<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Identity\ModelNumber;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * What counts as a model number in a title.
 *
 * The rule only proposes pairs for a person to confirm, so a miss here is
 * cheap in one direction and expensive in the other: a size that slips through
 * fills the review queue with "500ml" pairs until nobody reads it.
 */
class ModelNumberTest extends TestCase
{
    /** @return iterable<string, array{string, list<string>}> */
    public static function titles(): iterable
    {
        // The four spellings of one LEGO set from docs/strategy.md. Only the
        // one that carries the set number can be matched by this rule; the
        // other three are the similar-title rule's job.
        yield 'lego with set number' => ['LEGO Ferrari 488 #42125', ['42125']];
        yield 'lego set number in brackets' => ['LEGO Technic Ferrari 488 GTE (42125)', ['42125']];
        yield 'lego without set number' => ['LEGO Technic Ferrari 488 GTE', []];

        yield 'sony headphones' => ['Sony WH-1000XM5 draadloze koptelefoon zwart', ['WH1000XM5']];
        yield 'sony headphones no hyphen' => ['Sony WH1000XM5 Black', ['WH1000XM5']];
        yield 'samsung phone' => ['Samsung Galaxy S24 SM-S921B 128GB', ['SMS921B']];
        yield 'tv model' => ['Sony Bravia KD-55X85L 55 inch 4K', ['KD55X85L']];
        yield 'apple part' => ['Apple MacBook Air A2337', ['A2337']];

        // What must not match.
        yield 'years' => ['Philips Hue starter kit 2024 edition', []];
        yield 'volumes' => ['Bodum koffiepot 500ml 1,5l', []];
        yield 'sizes' => ['Garmin Forerunner 42mm horloge', []];
        yield 'capacity' => ['Anker powerbank 10000mAh 20W', []];
        yield 'dimensions' => ['Fotolijst 30x40cm wit', []];
        yield 'resolution' => ['Dashcam 1080p 2160p', []];
        yield 'standards' => ['Kabel USB3.2 HDMI21 WiFi6 IP68', []];
        yield 'short series' => ['Sony PS5 controller XM5 S24', []];
        yield 'price in the title' => ['Nu 129.99 euro', []];
        yield 'barcode-length number' => ['Artikel 8712345678901', []];
        yield 'pack' => ['Batterijen 10-pack AA', []];
        yield 'age range' => ['Puzzel 8-12jaar 1000 stukjes', []];
        yield '2 in 1' => ['Stofzuiger 2-in-1', []];
        yield 'graphics chip' => ['ASUS TUF RTX4090 OC', []];
        yield 'speed class' => ['SanDisk MicroSDXC Extreme 64GB 80MB/s', []];
        yield 'spec pair' => ['MacBook Air 15" M5 24GB/1TB Zilver', []];
        yield 'rate' => ['Step 25km/h', []];
        yield 'part number with a slash' => ['Philips Lumea BRI947/00', ['BRI94700']];
    }

    /** @param list<string> $expected */
    #[Test]
    #[DataProvider('titles')]
    public function it_finds_model_numbers_and_nothing_else(string $title, array $expected): void
    {
        $this->assertSame($expected, ModelNumber::extract($title));
    }

    /** @return iterable<string, array{string, string, bool}> */
    public static function pairs(): iterable
    {
        yield 'same numbers, other order' => ['LEGO Technic Ferrari 488 GTE', 'LEGO Ferrari 488 GTE Technic', true];
        yield 'one adds a set number' => ['LEGO Technic Ferrari 488', 'LEGO Ferrari 488 #42125', true];
        yield 'no numbers at all' => ['Cottelli jurk met kettingen', 'Cottelli jurk met slangenprint', true];
        yield 'neighbouring models' => ['Samsung Galaxy A27 128GB Zwart', 'Samsung Galaxy A17 128GB Zwart', false];
        yield 'other amount' => ['Cadeaubon 25 euro', 'Cadeaubon 75 euro', false];
        yield 'other variant code' => ['Lenovo P16 Gen 3 - 21RQ0056MB', 'Lenovo P16 Gen 3 - 21RQ0057MB', false];
        yield 'case of the number ignored' => ['KARCHER FC 537 500ML.', 'Karcher fc 537 500ml', true];
    }

    #[Test]
    #[DataProvider('pairs')]
    public function numbers_agree_only_when_one_title_adds_to_the_other(string $a, string $b, bool $agree): void
    {
        $this->assertSame($agree, ModelNumber::numbersAgree($a, $b));
        $this->assertSame($agree, ModelNumber::numbersAgree($b, $a));
    }

    #[Test]
    public function a_title_and_a_feed_part_number_meet_in_one_form(): void
    {
        $this->assertSame(ModelNumber::extract('Sony WH-1000XM5')[0], ModelNumber::fromMpn('wh1000xm5'));
    }

    #[Test]
    public function a_part_number_that_is_really_a_barcode_is_refused(): void
    {
        $this->assertNull(ModelNumber::fromMpn('8712345678901'));
        $this->assertNull(ModelNumber::fromMpn('ABC'));
        $this->assertNull(ModelNumber::fromMpn(null));
        $this->assertSame('42125', ModelNumber::fromMpn('42125'));
    }
}
