<?php

declare(strict_types=1);

namespace App\Filament\Resources\Users\Pages;

use App\Filament\Resources\Users\UserResource;
use Filament\Resources\Pages\ListRecords;

class ListUsers extends ListRecords
{
    protected static string $resource = UserResource::class;

    /**
     * No create button. An account is a proven address — see the resource —
     * and the first administrator is made by `bc:make-admin` from a shell.
     */
    protected function getHeaderActions(): array
    {
        return [];
    }
}
