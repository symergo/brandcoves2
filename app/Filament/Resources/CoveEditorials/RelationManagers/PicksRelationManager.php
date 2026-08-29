<?php

declare(strict_types=1);

namespace App\Filament\Resources\CoveEditorials\RelationManagers;

use App\Models\DailyPick;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * The products on a published Cove, and the line under each one.
 *
 * Editable, and read-only about *which* products are here. Adding or removing
 * one is a curation decision that belongs on the plan, where it survives a
 * rebuild; doing it here would produce a page that silently reverts the next
 * time anything refreshes it. The prose is the opposite: it is output, a rebuild
 * regenerates it anyway, and a sentence that reads badly needs fixing now.
 *
 * `unavailable` is the one flag worth having by hand. A guide dims a product
 * that has gone rather than hiding it, because the guide is an argument about
 * what to buy and removing the entry it argued for leaves the reasoning with a
 * hole in it.
 */
class PicksRelationManager extends RelationManager
{
    protected static string $relationship = 'picks';

    protected static ?string $title = 'Products';

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('verdict')
                ->label('Best for')
                ->maxLength(80)
                ->helperText('A short "best for X". Rendered on an article, ignored on a Daily Cove.'),

            Textarea::make('blurb')
                ->label('Copy')
                ->rows(3)
                ->helperText('Two sentences at most. Never a price — the page renders live prices, so a number written here is wrong by the time somebody reads it.'),

            Toggle::make('unavailable')
                ->label('Dim this one')
                ->helperText('Shown, greyed, with its reasoning intact. Out-of-stock products are dimmed automatically at render; this is the manual override.'),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('rank')->sortable(),
                ImageColumn::make('group.image_url')->label('')->square(),
                TextColumn::make('group.title')->label('Product')->limit(40)->wrap(),
                TextColumn::make('group.brand')->label('Brand')->toggleable(),
                TextColumn::make('verdict')->label('Best for')->limit(30)->placeholder('—'),
                TextColumn::make('blurb')->label('Copy')->limit(50)->wrap()->placeholder('— no copy'),

                // In stock now, read live rather than from the row: a snapshot
                // taken at build time is exactly the thing that goes stale.
                IconColumn::make('group.in_stock')->label('In stock')->boolean(),
                IconColumn::make('unavailable')->label('Dimmed')->boolean(),

                // An Amazon pick is stored as a decision, not a catalogue row —
                // title, price and image are re-fetched at render. Invariant 6.
                TextColumn::make('amazon_asin')->label('ASIN')->placeholder('—')->toggleable(isToggledHiddenByDefault: true),
            ])
            ->recordActions([EditAction::make()])
            ->defaultSort('rank')
            ->paginated(false);
    }

    public function isReadOnly(): bool
    {
        // Which products are here is decided on the plan. This manager exists to
        // edit what is *said* about them.
        return false;
    }

    /** @return class-string<DailyPick> */
    public static function getModel(): string
    {
        return DailyPick::class;
    }
}
