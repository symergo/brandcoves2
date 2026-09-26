<?php

declare(strict_types=1);

namespace App\Services\PageReading;

use RuntimeException;

/**
 * A fetch we would not make, or that did not give us what we asked for.
 *
 * One exception for both, because the caller does the same thing either way:
 * leave the item as the person typed it. `reason` is for the log and the tests.
 */
final class FetchRefused extends RuntimeException
{
    public function __construct(public readonly string $reason, string $detail = '')
    {
        parent::__construct($detail === '' ? $reason : "{$reason}: {$detail}");
    }
}
