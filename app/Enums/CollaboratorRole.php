<?php

declare(strict_types=1);

namespace App\Enums;

enum CollaboratorRole: string
{
    case Viewer = 'viewer';
    case Editor = 'editor';

    public function canEdit(): bool
    {
        return $this === self::Editor;
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $r) => $r->value, self::cases());
    }
}
