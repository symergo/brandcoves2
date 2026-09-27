<?php

declare(strict_types=1);

namespace App\Filament\Resources\FeatureIdeas\Pages;

use App\Filament\Resources\FeatureIdeas\FeatureIdeaResource;
use App\Models\FeatureIdea;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditFeatureIdea extends EditRecord
{
    protected static string $resource = FeatureIdeaResource::class;

    protected function getHeaderActions(): array
    {
        return [DeleteAction::make()];
    }

    /**
     * Two things a save has to record that the form does not show.
     *
     * - A shipped idea somebody edited is theirs now (`seed` becomes
     *   `owner`), so the next `bc:seed-feature-ideas` leaves it alone. A
     *   visitor's suggestion stays `visitor`: it is still their idea, and
     *   that is what the prune reads.
     * - When it was published or rejected, if that changed: a rejected
     *   suggestion is deleted a year after that date.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        /** @var FeatureIdea $record */
        $record = $this->getRecord();

        $data = self::withoutEmptyLanguages($data);

        if ($record->source === FeatureIdea::SOURCE_SEED) {
            $data['source'] = FeatureIdea::SOURCE_OWNER;
        }

        if (isset($data['moderation']) && $data['moderation'] !== $record->moderation->value) {
            $data['decided_at'] = now();
        }

        return $data;
    }

    /**
     * Drop the languages left empty, so "not translated yet" is a missing key
     * and not an empty string the fallback would have to see through.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function withoutEmptyLanguages(array $data): array
    {
        foreach (['title', 'body'] as $field) {
            $data[$field] = array_filter(
                (array) ($data[$field] ?? []),
                fn ($value): bool => is_string($value) && trim($value) !== '',
            );
        }

        return $data;
    }
}
