<?php

declare(strict_types=1);

namespace App\Enums;

enum Source: string
{
    case Awin = 'awin';
    case Bol = 'bol';
    case Amazon = 'amazon';
    case Manual = 'manual';

    /**
     * Feed sources are ingested on a schedule into our own index. Live sources
     * are queried per request and cached — never mirrored.
     */
    public function isFeed(): bool
    {
        return $this === self::Awin;
    }

    public function isLive(): bool
    {
        return in_array($this, [self::Bol, self::Amazon], true);
    }

    /**
     * Amazon's terms forbid mirroring the catalogue: we may store the decision
     * (which ASIN, and why) but title, price, image and availability must be
     * re-fetched live at render.
     */
    public function allowsCatalogueStorage(): bool
    {
        return $this !== self::Amazon;
    }

    public function label(): string
    {
        return match ($this) {
            self::Awin => 'Awin',
            self::Bol => 'bol.com',
            self::Amazon => 'Amazon',
            self::Manual => 'Manual',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $s) => $s->value, self::cases());
    }
}
