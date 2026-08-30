<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\ProductGroup;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The discount badge is a claim, and every case here is about not overstating
 * it. Measured against our own 30-day median, never a merchant's "was" price.
 *
 * Unsaved models: this is arithmetic on two columns and nothing else, so the
 * database would only make it slower.
 */
class DiscountPercentTest extends TestCase
{
    private function group(?int $min, ?int $median): ProductGroup
    {
        return new ProductGroup(['min_price' => $min, 'median_price' => $median]);
    }

    #[Test]
    public function it_floors_rather_than_rounds(): void
    {
        // 19.6% — a badge saying 20 would be a saving we could not defend.
        $this->assertSame(19, $this->group(8040, 10000)->discountPercent());
    }

    #[Test]
    public function a_saving_below_one_percent_is_not_a_discount(): void
    {
        // Floors to 0, and "0% off" is a badge that claims nothing while
        // looking exactly like one that claims something. Seen on the by-store
        // lanes as "-0%".
        $this->assertNull($this->group(9950, 10000)->discountPercent());
    }

    #[Test]
    public function a_price_at_or_above_the_median_is_not_a_discount(): void
    {
        $this->assertNull($this->group(10000, 10000)->discountPercent());
        $this->assertNull($this->group(12000, 10000)->discountPercent());
    }

    #[Test]
    public function it_is_null_without_a_median_to_compare_against(): void
    {
        // A product we have not held a price history for yet.
        $this->assertNull($this->group(8000, null)->discountPercent());
        $this->assertNull($this->group(null, 10000)->discountPercent());
    }
}
