<?php

declare(strict_types=1);

namespace App\Filament\Resources\ProductGroups;

use App\Enums\IdentityKind;
use App\Enums\Market;
use App\Filament\Resources\ProductGroups\Pages\ListProductGroups;
use App\Filament\Resources\ProductGroups\Pages\ManageProductGroup;
use App\Models\ProductGroup;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/**
 * Products: the physical things offers are grouped into.
 *
 * The Offers screen lists what the feeds sent; this one lists what a visitor
 * sees, one row per product page. It exists for the two corrections the
 * grouper cannot make on its own: **merge** two products that are one, and
 * **split** offers out of a product they do not belong to. Both live in
 * identity (aliases and overrides) so the next grouping run keeps them; see
 * docs/features/product-identity.md.
 *
 * Nothing else is editable here. The display fields are rewritten by every
 * grouping run from the offers, so an edit would be silently undone.
 */
class ProductGroupResource extends Resource
{
    protected static ?string $model = ProductGroup::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCube;

    protected static string|UnitEnum|null $navigationGroup = 'Catalogue';

    protected static ?int $navigationSort = 6;

    protected static ?string $navigationLabel = 'Products';

    protected static ?string $modelLabel = 'product';

    protected static ?string $pluralModelLabel = 'products';

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                ImageColumn::make('image_url')->label('')->size(40),

                TextColumn::make('title')
                    ->searchable()
                    ->limit(70)
                    ->description(fn (ProductGroup $r) => $r->brand),

                TextColumn::make('id')->label('#')->sortable()->searchable(),
                TextColumn::make('market')->badge(),

                TextColumn::make('identity_kind')
                    ->label('Grouped by')
                    ->badge()
                    ->color(fn (IdentityKind $state) => $state === IdentityKind::Ean ? 'success' : 'warning')
                    ->tooltip(fn (ProductGroup $r) => $r->identity_key),

                TextColumn::make('offer_count')->label('Offers')->sortable(),
                TextColumn::make('merchant_count')->label('Shops')->sortable(),

                TextColumn::make('min_price')
                    ->label('From')
                    ->sortable()
                    ->formatStateUsing(fn (?int $state) => $state === null ? '-' : number_format($state / 100, 2).' EUR'),

                TextColumn::make('merged_into_id')
                    ->label('Merged into')
                    ->placeholder('')
                    ->formatStateUsing(fn (?int $state) => $state === null ? '' : "#{$state}"),
            ])
            ->filters([
                SelectFilter::make('market')->options(Market::class),

                // Merged products are kept for their URLs, not for browsing.
                Filter::make('live')
                    ->label('Hide merged products')
                    ->query(fn (Builder $q) => $q->whereNull('merged_into_id'))
                    ->toggle()
                    ->default(),

                Filter::make('title_only')
                    ->label('Grouped by title only')
                    ->query(fn (Builder $q) => $q->where('identity_kind', IdentityKind::Title->value))
                    ->toggle(),
            ])
            ->recordUrl(fn (ProductGroup $r) => self::getUrl('manage', ['record' => $r]))
            ->recordActions([
                Action::make('open')
                    ->label('On the site')
                    ->icon(Heroicon::OutlinedArrowTopRightOnSquare)
                    ->url(fn (ProductGroup $r) => $r->path())
                    ->openUrlInNewTab(),
            ])
            ->defaultSort('id', 'desc');
    }

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

    public static function getPages(): array
    {
        return [
            'index' => ListProductGroups::route('/'),
            'manage' => ManageProductGroup::route('/{record}'),
        ];
    }
}
