<?php

declare(strict_types=1);

namespace App\Enums;

enum ListVisibility: string
{
    /** Owner and collaborators only. */
    case Private = 'private';

    /** Anyone holding the share token. Not indexed. */
    case Link = 'link';

    /** Listed and indexable. */
    case Public = 'public';

    public function isShareable(): bool
    {
        return $this !== self::Private;
    }

    public function isIndexable(): bool
    {
        return $this === self::Public;
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $v) => $v->value, self::cases());
    }
}
