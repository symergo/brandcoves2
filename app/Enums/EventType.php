<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The occasion a list is for.
 *
 * Not a kind of list. It is a nullable field on `wishlists`, so any list of any
 * kind may carry one: a wedding registry of my own, a birthday list about my
 * father, a group present for a colleague who is leaving. The kind says who the
 * list is about; this says why it exists, and the two are independent.
 *
 * ## It was five values, and they were the registry's five
 *
 * Wedding, baby, housewarming, birthday, other — the occasions somebody sets up
 * a *registry* for, which is what this enum was written for and where the panel
 * was gated. The moment an occasion can sit on a list about somebody else, the
 * ordinary gifting calendar is missing entirely: Christmas is the single
 * biggest one and had to be filed under "Something else".
 *
 * `Other` stays, because the list is a convenience rather than a taxonomy and
 * somebody will always have a reason we did not think of. It is last on purpose.
 *
 * Values are lower_snake and stable — they are stored in a CHECK constraint and
 * read back as strings. Renaming one is a migration, not an edit.
 */
enum EventType: string
{
    case Birthday = 'birthday';
    case Christmas = 'christmas';
    case Wedding = 'wedding';
    case Anniversary = 'anniversary';
    case Baby = 'baby';
    case Housewarming = 'housewarming';
    case Graduation = 'graduation';
    case Retirement = 'retirement';
    case Farewell = 'farewell';
    case Valentines = 'valentines';
    case MothersDay = 'mothers_day';
    case FathersDay = 'fathers_day';
    case ThankYou = 'thank_you';
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
