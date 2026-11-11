<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\Merchant;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Feeds name an advertiser per country. The shopper does not need telling which
 * market they are in on every chip and every lane header — they chose it, and
 * every price on the page is in its currency.
 */
class MerchantDisplayNameTest extends TestCase
{
    private function shown(string $stored): string
    {
        return (new Merchant(['name' => $stored]))->displayName();
    }

    /** @return array<string, array{string, string}> */
    public static function names(): array
    {
        return [
            'a trailing country code' => ['Coolblue BE', 'Coolblue'],
            'a trailing country code after a two-word name' => ['Vanden Borre BE', 'Vanden Borre'],
            'a trailing country code in brackets' => ['MediaMarkt (NL)', 'MediaMarkt'],
            'a trailing country code after a dash' => ['Fnac - FR', 'Fnac'],
            'a trailing market pair' => ['Action BE-NL', 'Action'],
            'no suffix: a domain' => ['bol.com', 'bol.com'],
            'no suffix: one word' => ['Coolblue', 'Coolblue'],
            'no suffix: Amazon' => ['Amazon', 'Amazon'],
            // Only a two-letter token goes. Anything longer is part of the name.
            'a real word is not eaten' => ['Bakker Hillegom', 'Bakker Hillegom'],
            'a real short word is not eaten' => ['Foot Locker', 'Foot Locker'],
            // Stripping would leave an empty column header, which is worse than
            // the odd name it is trying to tidy.
            'a shop whose whole name is a code keeps it' => ['BE', 'BE'],
        ];
    }

    #[Test]
    #[DataProvider('names')]
    public function it_shows(string $stored, string $shown): void
    {
        $this->assertSame($shown, $this->shown($stored));
    }
}
