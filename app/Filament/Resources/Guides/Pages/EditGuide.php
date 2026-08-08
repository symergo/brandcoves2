<?php

declare(strict_types=1);

namespace App\Filament\Resources\Guides\Pages;

use App\Filament\Resources\Guides\GuideResource;
use Filament\Resources\Pages\EditRecord;

class EditGuide extends EditRecord
{
    protected static string $resource = GuideResource::class;
}
