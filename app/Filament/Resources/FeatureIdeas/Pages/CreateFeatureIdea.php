<?php

declare(strict_types=1);

namespace App\Filament\Resources\FeatureIdeas\Pages;

use App\Enums\ModerationStatus;
use App\Filament\Resources\FeatureIdeas\FeatureIdeaResource;
use App\Models\FeatureIdea;
use Filament\Resources\Pages\CreateRecord;

/**
 * An idea the owner adds by hand: `source` owner, so no seed ever touches it.
 */
class CreateFeatureIdea extends CreateRecord
{
    protected static string $resource = FeatureIdeaResource::class;

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data = EditFeatureIdea::withoutEmptyLanguages($data);
        $data['source'] = FeatureIdea::SOURCE_OWNER;
        $data['decided_at'] = ($data['moderation'] ?? null) === ModerationStatus::Pending->value ? null : now();

        return $data;
    }
}
