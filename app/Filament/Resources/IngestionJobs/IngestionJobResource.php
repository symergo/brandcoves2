<?php

declare(strict_types=1);

namespace App\Filament\Resources\IngestionJobs;

use App\Filament\Resources\IngestionJobs\Pages\ListIngestionJobs;
use App\Filament\Resources\IngestionJobs\Tables\IngestionJobsTable;
use App\Models\IngestionJob;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Tables\Table;
use UnitEnum;

class IngestionJobResource extends Resource
{
    protected static ?string $model = IngestionJob::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-arrow-path';

    protected static string|UnitEnum|null $navigationGroup = 'Catalogue';

    protected static ?int $navigationSort = 3;

    protected static ?string $navigationLabel = 'Ingestion jobs';

    public static function table(Table $table): Table
    {
        return IngestionJobsTable::configure($table);
    }

    /** Cursor state is written by the job. Hand-editing one corrupts a resume point. */
    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit($record): bool
    {
        return false;
    }

    public static function canDelete($record): bool
    {
        return false;
    }

    /** A failing feed should be visible from the sidebar without opening the page. */
    public static function getNavigationBadge(): ?string
    {
        $failing = IngestionJob::query()->where('status', 'failed')->count();

        return $failing > 0 ? (string) $failing : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'danger';
    }

    public static function getPages(): array
    {
        return [
            'index' => ListIngestionJobs::route('/'),
        ];
    }
}
