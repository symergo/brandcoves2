<?php

namespace App\Filament\Resources\Merchants\Schemas;

use App\Enums\Source;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

class MerchantForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('source')
                    ->options(Source::class)
                    ->required(),
                TextInput::make('external_id')
                    ->required(),
                TextInput::make('name')
                    ->required(),
                TextInput::make('domain'),
                TextInput::make('logo_url')
                    ->url(),
                Toggle::make('trusts_reference_price')
                    ->required(),
                Toggle::make('enabled')
                    ->required(),
            ]);
    }
}
