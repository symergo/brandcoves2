<?php

declare(strict_types=1);

namespace App\Services\Gift;

/**
 * One thing a person was given, as far as the giver's own record goes.
 *
 * Three sources, one shape (see {@see GiftHistory}):
 *
 * - `claimed`: the giver's own claim on a list for this person, still held;
 * - `sent`: the same, marked as bought and on its way;
 * - `noted`: written down by the giver on the person's page, or an item from
 *   their list marked "I gave this" (a `recipient_gifts` row).
 */
final readonly class PastGift
{
    public const CLAIMED = 'claimed';

    public const SENT = 'sent';

    public const NOTED = 'noted';

    public function __construct(
        public string $source,
        public string $title,
        public ?int $groupId = null,
        public ?int $year = null,
        public ?string $brand = null,
        public ?string $category = null,
        public ?string $image = null,
        public ?string $url = null,
        /** The `recipient_gifts` row, for a noted gift: what "remove" deletes. */
        public ?int $recordId = null,
        /** The list item it came from, when it came from one. */
        public ?int $itemId = null,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'source' => $this->source,
            'title' => $this->title,
            'groupId' => $this->groupId,
            'year' => $this->year,
            'image' => $this->image,
            'url' => $this->url,
            'recordId' => $this->recordId,
        ];
    }
}
