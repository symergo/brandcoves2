<?php

declare(strict_types=1);

namespace App\Filament\Resources\IngestionJobs\Pages;

use App\Filament\Resources\IngestionJobs\IngestionJobResource;
use Filament\Resources\Pages\ListRecords;

class ListIngestionJobs extends ListRecords
{
    protected static string $resource = IngestionJobResource::class;

    /** No create action: these rows are written by the ingestion job. */
    protected function getHeaderActions(): array
    {
        return [];
    }
}
