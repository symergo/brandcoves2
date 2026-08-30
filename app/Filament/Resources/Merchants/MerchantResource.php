<?php

namespace App\Filament\Resources\Merchants;

use App\Filament\Resources\Merchants\Pages\CreateMerchant;
use App\Filament\Resources\Merchants\Pages\EditMerchant;
use App\Filament\Resources\Merchants\Pages\ListMerchants;
use App\Filament\Resources\Merchants\Schemas\MerchantForm;
use App\Filament\Resources\Merchants\Tables\MerchantsTable;
use App\Models\Merchant;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

class MerchantResource extends Resource
{
    protected static ?string $model = Merchant::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingStorefront;

    // Part of the catalogue pipeline, not a top-level concern: a merchant row is
    // discovered by feed ingestion. Grouped alongside Feeds for the same reason.
    protected static string|UnitEnum|null $navigationGroup = 'Catalogue';

    protected static ?int $navigationSort = 5;

    public static function form(Schema $schema): Schema
    {
        return MerchantForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return MerchantsTable::configure($table);
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
            'index' => ListMerchants::route('/'),
            'create' => CreateMerchant::route('/create'),
            'edit' => EditMerchant::route('/{record}/edit'),
        ];
    }
}
