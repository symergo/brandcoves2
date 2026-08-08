<?php

declare(strict_types=1);

namespace App\Filament\Resources\AiUsage\Pages;

use App\Filament\Resources\AiUsage\AiUsageResource;
use Filament\Resources\Pages\ListRecords;

class ListAiUsage extends ListRecords
{
    protected static string $resource = AiUsageResource::class;
}
