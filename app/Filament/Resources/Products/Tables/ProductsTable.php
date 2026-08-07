<?php

declare(strict_types=1);

namespace App\Filament\Resources\Products\Tables;

use App\Enums\Availability;
use App\Enums\IdentityKind;
use App\Enums\Market;
use App\Enums\ProductStatus;
use App\Enums\Source;
use App\Models\Product;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

/**
 * The offer browser.
 *
 * Rows here are OFFERS, not products — one merchant selling one thing in one
 * market. The grouping column is the one to watch: an offer with no group never
 * appears in a comparison, so a sudden rise in ungrouped rows means identity
 * resolution has stopped working on a feed.
 */
class ProductsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                ImageColumn::make('image_url')
                    ->label('')
                    ->size(40)
                    // Feed images 404 constantly; a broken icon in every row
                    // makes the table unreadable.
                    ->defaultImageUrl(fn () => 'data:image/svg+xml;base64,'.base64_encode(
                        '<svg xmlns="http://www.w3.org/2000/svg" width="40" height="40"><rect width="40" height="40" fill="#e5ded4"/></svg>'
                    )),

                TextColumn::make('title')
                    ->searchable()
                    ->limit(60)
                    ->description(fn (Product $r) => $r->brand),

                TextColumn::make('merchant.name')->label('Shop')->searchable(),
                TextColumn::make('market')->badge()->sortable(),
                TextColumn::make('source')->badge(),

                TextColumn::make('price')
                    ->sortable()
                    // Stored as integer cents; displayed as money.
                    ->formatStateUsing(fn (?int $state, Product $r) => $state === null
                        ? '—'
                        : number_format($state / 100, 2).' '.$r->currency),

                TextColumn::make('availability')
                    ->badge()
                    ->color(fn (Availability $state) => $state->isBuyable() ? 'success' : 'gray'),

                TextColumn::make('identity_kind')
                    ->label('Grouped by')
                    ->badge()
                    ->placeholder('ungrouped')
                    ->color(fn (?IdentityKind $state) => match ($state) {
                        IdentityKind::Ean => 'success',
                        IdentityKind::Title => 'warning',
                        default => 'danger',
                    })
                    ->tooltip(fn (Product $r) => $r->identity_key ?? 'No EAN and no usable brand+title — left ungrouped on purpose'),

                TextColumn::make('status')->badge()->toggleable(),
                TextColumn::make('last_seen_at')->since()->label('Last seen')->sortable()->toggleable(),
            ])
            ->filters([
                SelectFilter::make('market')->options(Market::class),
                SelectFilter::make('source')->options(Source::class),
                SelectFilter::make('status')->options(ProductStatus::class),
                SelectFilter::make('availability')->options(Availability::class),
                SelectFilter::make('merchant')->relationship('merchant', 'name')->searchable(),

                Filter::make('ungrouped')
                    ->label('Ungrouped only')
                    ->query(fn ($q) => $q->whereNull('group_id'))
                    ->toggle(),

                Filter::make('comparable')
                    ->label('In a multi-shop group')
                    ->query(fn ($q) => $q->whereHas('group', fn ($g) => $g->where('merchant_count', '>', 1)))
                    ->toggle(),
            ])
            ->defaultSort('last_seen_at', 'desc')
            ->recordActions([])
            ->toolbarActions([]);
    }
}
