<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Where an idea that people typed by hand stands.
 *
 * See docs/features/offline-ideas.md.
 */
enum OfflineIdeaStatus: string
{
    /** Enough different people wrote it; waiting for a person to read it. */
    case Pending = 'pending';

    /** A person approved it, in their own wording. Only these are ever shown. */
    case Approved = 'approved';

    /** A person said no. Kept so the same idea is never proposed again. */
    case Rejected = 'rejected';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $s) => $s->value, self::cases());
    }
}
