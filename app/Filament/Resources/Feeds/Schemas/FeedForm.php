<?php

namespace App\Filament\Resources\Feeds\Schemas;

use App\Enums\Market;
use App\Enums\Source;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

class FeedForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('source')
                    ->options(Source::class)
                    ->default('awin')
                    ->required(),
                TextInput::make('external_feed_id')
                    ->required(),
                Select::make('market')
                    ->options(Market::class)
                    ->required(),
                Select::make('merchant_id')
                    ->relationship('merchant', 'name'),
                TextInput::make('label')
                    ->required(),
                Toggle::make('enabled')
                    ->required(),
                TextInput::make('column_map'),
                DateTimePicker::make('last_run_at'),
                TextInput::make('last_row_count')
                    ->numeric(),
                Textarea::make('last_error')
                    ->columnSpanFull(),
            ]);
    }
}
