<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Enums\RecipientType;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The relations split by gender (owner, 2026-09-29), with the combined ones
 * gone. See docs/features/relations-by-gender.md.
 */
class RecipientTypeTest extends TestCase
{
    #[Test]
    public function every_relation_is_one_person_and_the_combined_ones_are_gone(): void
    {
        $values = RecipientType::values();

        $this->assertCount(16, $values);

        foreach (['grandmother', 'grandfather', 'son', 'daughter', 'brother', 'sister', 'male_friend', 'female_friend', 'female_teacher', 'male_teacher', 'male_host', 'female_host'] as $value) {
            $this->assertContains($value, $values);
        }

        foreach (['grandparent', 'child', 'sibling', 'friend', 'teacher', 'host'] as $combined) {
            $this->assertNull(RecipientType::tryFrom($combined), $combined);
        }
    }
}
