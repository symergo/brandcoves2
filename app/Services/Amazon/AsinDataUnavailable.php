<?php

declare(strict_types=1);

namespace App\Services\Amazon;

use RuntimeException;

/** The API gave no answer about the barcode. `stopForToday`: a bad key, no credits, or too fast. */
final class AsinDataUnavailable extends RuntimeException
{
    public function __construct(string $message, public readonly bool $stopForToday)
    {
        parent::__construct($message);
    }
}
