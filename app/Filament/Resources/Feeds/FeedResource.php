<?php

namespace App\Filament\Resources\Feeds;

use App\Filament\Resources\Feeds\Pages\CreateFeed;
use App\Filament\Resources\Feeds\Pages\EditFeed;
use App\Filament\Resources\Feeds\Pages\ListFeeds;
use App\Filament\Resources\Feeds\Schemas\FeedForm;
use App\Filament\Resources\Feeds\Tables\FeedsTable;
use App\Models\Feed;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

class FeedResource extends Resource
{
    protected static ?string $model = Feed::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    // Grouped with the rest of the catalogue rather than floating above the
    // sidebar's groups. Feeds, their discovery page, the jobs they queue and the
    // products they produce are one pipeline, and Market supply is the summary
    // that links into this list — a link whose target sat outside the group its
    // siblings are in.
    protected static string|UnitEnum|null $navigationGroup = 'Catalogue';

    protected static ?int $navigationSort = 1;

    public static function form(Schema $schema): Schema
    {
        return FeedForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return FeedsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListFeeds::route('/'),
            'create' => CreateFeed::route('/create'),
            'edit' => EditFeed::route('/{record}/edit'),
        ];
    }
}
