<?php

declare(strict_types=1);

namespace App\Filament\Resources\PromptTemplates\Pages;

use App\Filament\Resources\PromptTemplates\PromptTemplateResource;
use Filament\Resources\Pages\ListRecords;

class ListPromptTemplates extends ListRecords
{
    protected static string $resource = PromptTemplateResource::class;

    protected function getHeaderActions(): array
    {
        // The create action lives in the table header, next to the empty state
        // that explains why the table is usually empty.
        return [];
    }
}
