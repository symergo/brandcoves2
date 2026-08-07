<?php

declare(strict_types=1);

namespace App\Services\Gift;

/**
 * The verdict on whether a product can be given to someone.
 *
 * `reason` is stored alongside the boolean so a rejection is explainable in
 * admin without re-running the whole classification pass — "why is this not in
 * the gift results" is otherwise an unanswerable question over 70,000 rows.
 */
final readonly class Giftability
{
    private function __construct(
        public bool $giftable,
        public string $reason,
        /** The matched phrase, for admin. Null when nothing in particular decided it. */
        public ?string $evidence = null,
    ) {}

    public static function yes(string $reason = 'ok'): self
    {
        return new self(true, $reason);
    }

    public static function no(string $reason, ?string $evidence = null): self
    {
        return new self(false, $reason, $evidence);
    }
}
