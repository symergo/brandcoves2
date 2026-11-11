<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\ProductGroup;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The discount badge is a claim, and every case here is about not overstating
 * it. Measured against the offer's previous price, never a merchant's "was" price.
 *
 * Unsaved models: this is arithmetic on two columns and nothing else, so the
 * database would only make it slower.
 */
class DiscountPercentTest extends TestCase
{
    private function group(?int $min, ?int $previous): ProductGroup
    {
        return new ProductGroup(['min_price' => $min, 'previous_price' => $previous]);
    }

    #[Test]
    public function it_floors_rather_than_rounds(): void
    {
        // 19.6% — a badge saying 20 would be a saving we could not defend.
        $this->assertSame(19, $this->group(8040, 10000)->discountPercent());
    }

    /** @return iterable<string, array{?int, ?int}> */
    public static function notADiscount(): iterable
    {
        // Floors to 0, and "0% off" is a badge that claims nothing while
        // looking exactly like one that claims something. Seen on the by-store
        // lanes as "-0%".
        yield 'a saving below 1%' => [9950, 10000];

        yield 'a price equal to the previous one' => [10000, 10000];
        yield 'a price above the previous one' => [12000, 10000];

        // An offer whose price has never changed since we first saw it.
        yield 'no previous price' => [8000, null];
        yield 'no current price' => [null, 10000];
    }

    #[Test]
    #[DataProvider('notADiscount')]
    public function it_is_not_a_discount(?int $min, ?int $previous): void
    {
        $this->assertNull($this->group($min, $previous)->discountPercent());
    }
}
