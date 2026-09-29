<?php

declare(strict_types=1);

namespace App\Services\Gift;

/**
 * One thing a person was given, as far as the giver's own record goes.
 *
 * Two sources, one shape (see {@see GiftHistory}):
 *
 * - `claimed`: the giver's own claim on a list for this person, still held;
 * - `sent`: the same, marked as bought and on its way.
 *
 * A third, `noted` (written down on the person's page, or "I gave this"
 * beside a list item), went with "Wat je gaf" on 2026-09-29.
 */
final readonly class PastGift
{
    public const CLAIMED = 'claimed';

    public const SENT = 'sent';

    public function __construct(
        public string $source,
        public string $title,
        public ?int $groupId = null,
        public ?int $year = null,
        public ?string $brand = null,
        public ?string $category = null,
        public ?string $image = null,
        public ?string $url = null,
        /** The list item it came from. */
        public ?int $itemId = null,
    ) {}
}
