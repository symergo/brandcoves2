<?php

declare(strict_types=1);

namespace App\Filament\Resources\FeatureIdeas\Pages;

use App\Filament\Resources\FeatureIdeas\FeatureIdeaResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListFeatureIdeas extends ListRecords
{
    protected static string $resource = FeatureIdeaResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->label('Add an idea')];
    }
}
