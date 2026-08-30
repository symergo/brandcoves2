<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\Merchant;
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

    #[Test]
    public function it_drops_a_trailing_country_code(): void
    {
        $this->assertSame('Coolblue', $this->shown('Coolblue BE'));
        $this->assertSame('Vanden Borre', $this->shown('Vanden Borre BE'));
        $this->assertSame('MediaMarkt', $this->shown('MediaMarkt (NL)'));
        $this->assertSame('Fnac', $this->shown('Fnac - FR'));
    }

    #[Test]
    public function it_drops_a_trailing_market_pair(): void
    {
        $this->assertSame('Action', $this->shown('Action BE-NL'));
    }

    #[Test]
    public function it_leaves_a_name_that_has_no_suffix_alone(): void
    {
        $this->assertSame('bol.com', $this->shown('bol.com'));
        $this->assertSame('Coolblue', $this->shown('Coolblue'));
        $this->assertSame('Amazon', $this->shown('Amazon'));
    }

    #[Test]
    public function it_does_not_eat_a_real_word(): void
    {
        // Only a two-letter token goes. Anything longer is part of the name.
        $this->assertSame('Bakker Hillegom', $this->shown('Bakker Hillegom'));
        $this->assertSame('Foot Locker', $this->shown('Foot Locker'));
    }

    #[Test]
    public function a_shop_whose_whole_name_is_a_code_keeps_it(): void
    {
        // Stripping would leave an empty column header, which is worse than the
        // odd name it is trying to tidy.
        $this->assertSame('BE', $this->shown('BE'));
    }
}
