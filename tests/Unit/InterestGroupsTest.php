<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Enums\Interest;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The wizard's headings over the interests (2026-11-26). A new interest that is put in
 * no group would never be shown, so this is what stops it.
 */
class InterestGroupsTest extends TestCase
{
    #[Test]
    public function every_interest_is_in_exactly_one_group(): void
    {
        $grouped = array_map(fn (Interest $i) => $i->value, array_merge(...array_values(Interest::GROUPS)));

        $this->assertSame([], array_values(array_diff(Interest::values(), $grouped)), 'in no group');
        $this->assertSame(count($grouped), count(array_unique($grouped)), 'in two groups');
    }

    #[Test]
    public function every_group_has_a_heading_in_every_language(): void
    {
        foreach (['nl', 'fr', 'en', 'es'] as $locale) {
            foreach (array_keys(Interest::GROUPS) as $key) {
                $this->assertNotSame("site.gift.interest_groups.{$key}", __("site.gift.interest_groups.{$key}", [], $locale), "{$locale}: {$key}");
            }
        }
    }
}
