<?php

declare(strict_types=1);

namespace App\Services\Identity;

use App\Enums\IdentityKind;

/**
 * The key that decides which physical product an offer belongs to, plus how it
 * was derived. The kind is stored so a bad merge can be audited and so the two
 * paths can be measured against each other.
 */
final readonly class Identity
{
    public function __construct(
        public string $key,
        public IdentityKind $kind,
    ) {}

    public function isAuthoritative(): bool
    {
        return $this->kind === IdentityKind::Ean;
    }
}
