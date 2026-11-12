<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\ProductGroup;
use App\Services\Catalogue\ProductTitle;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The product page's heading when nobody wrote a display title: the feed
 * title cut to what the product is. See {@see ProductTitle::short()}.
 */
class ProductShortTitleTest extends TestCase
{
    private function group(string $title, ?string $display = null): ProductGroup
    {
        return new ProductGroup(['title' => $title, 'brand' => null, 'display_title' => $display]);
    }

    #[Test]
    public function a_keyword_stuffed_title_keeps_what_stands_before_the_first_separator(): void
    {
        $group = $this->group('Airfryer Siliconen Bakje 2 stuks - Airfryer Accessoires - Airfryer Bakpapier - Airfryer Mandje voor Philips Airfryer 6,2L');

        $this->assertSame('Airfryer Siliconen Bakje 2 stuks', ProductTitle::short($group));
    }

    #[Test]
    public function a_short_title_comes_back_whole(): void
    {
        $this->assertSame('Oral-B iO 2 Zwart', ProductTitle::short($this->group('Oral-B iO 2 Zwart')));
    }

    #[Test]
    public function a_long_title_without_a_separator_is_cut_between_words(): void
    {
        $short = ProductTitle::short($this->group(str_repeat('Stofzuigerzakken microvezel ', 6)));

        $this->assertLessThanOrEqual(70, mb_strlen($short));
        $this->assertStringEndsNotWith(' ', $short);
        $this->assertStringStartsWith('Stofzuigerzakken microvezel', $short);
        $this->assertMatchesRegularExpression('/(Stofzuigerzakken|microvezel)$/', $short);
    }

    #[Test]
    public function a_written_display_title_always_wins(): void
    {
        $group = $this->group('Airfryer Siliconen Bakje 2 stuks - Airfryer Accessoires - Airfryer Bakpapier - Airfryer Mandje', 'Siliconen airfryerbakjes');

        $this->assertSame('Siliconen airfryerbakjes', $group->shortHeading());
    }
}
