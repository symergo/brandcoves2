<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * Where an idea on the contribute page stands (docs/features/contribute.md).
 *
 * Four states and no dates. The board says what we are thinking about and
 * what we are doing, never when: a date on a public page is a promise, and a
 * small team keeps fewer of those than it makes.
 *
 * The order of the cases is the order of the board: what is being built
 * first, then what is planned, then what is being considered (by votes), and
 * what is done last.
 */
enum FeatureStatus: string implements HasColor, HasLabel
{
    case Building = 'building';
    case Planned = 'planned';
    case Considering = 'considering';
    case Done = 'done';

    public function label(): string
    {
        return __('site.contribute.status.'.$this->value);
    }

    /** Position on the board, lowest first. */
    public function rank(): int
    {
        return match ($this) {
            self::Building => 0,
            self::Planned => 1,
            self::Considering => 2,
            self::Done => 3,
        };
    }

    /**
     * Filament receives the case, not its value, for a column cast to this
     * enum. Implementing the badge contracts here keeps a `fn (string $state)`
     * closure out of the resource: that closure is the TypeError that 500'd
     * the community queues once a row existed (see ModerationStatus).
     */
    public function getLabel(): string
    {
        return $this->label();
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Building => 'warning',
            self::Planned => 'info',
            self::Considering => 'gray',
            self::Done => 'success',
        };
    }

    /** @return array<string, string> value => label, for a select. */
    public static function options(): array
    {
        $options = [];

        foreach (self::cases() as $case) {
            $options[$case->value] = $case->label();
        }

        return $options;
    }
}
