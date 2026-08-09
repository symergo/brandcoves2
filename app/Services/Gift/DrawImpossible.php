<?php

declare(strict_types=1);

namespace App\Services\Gift;

use RuntimeException;

/**
 * No valid arrangement exists.
 *
 * Carries the member the matching got stuck on, because "the draw failed" is
 * not actionable and "Sam cannot be matched" is: the organiser can go and ask
 * Sam about their exclusion list. v1 could only report the former, since a
 * retry loop has no idea which constraint defeated it.
 */
class DrawImpossible extends RuntimeException
{
    public function __construct(string $message, public readonly int|string|null $blockedBy = null)
    {
        parent::__construct($message);
    }
}
