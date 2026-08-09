<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The occasion a registry is for.
 *
 * A registry is an ordinary list with three things bolted on: an event, a date,
 * and somewhere to send the parcel. It is not a separate kind of list — it is
 * still `mine`, still claimable, still owned by the person it is for — which is
 * why this is a nullable field rather than a fourth `ListKind`.
 */
enum EventType: string
{
    case Wedding = 'wedding';
    case Baby = 'baby';
    case Housewarming = 'housewarming';
    case Birthday = 'birthday';
    case Other = 'other';

    public function label(): string
    {
        return __('site.registry.types.'.$this->value);
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $t) => $t->value, self::cases());
    }
}
