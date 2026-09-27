<?php

declare(strict_types=1);

namespace App\Filament\Resources\Users\RelationManagers;

use App\Enums\ListKind;
use App\Enums\ListVisibility;
use App\Enums\Market;
use App\Models\Wishlist;
use App\Models\WishlistItem;
use Filament\Actions\Action;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Contracts\View\View;

/**
 * An account's lists, and the products on each (owner, 2026-09-28).
 *
 * Read-only, and narrower than the list itself on purpose: the list, and the
 * products on it. Never the notes under an item, who a list is for, the
 * delivery address, or anything about claims. Claim state is kept from a
 * list's owner by default (invariant 4), and the administrator reading this
 * may be that owner. Everything here is SELECTed column by column rather than
 * loaded whole, so what is not shown is never read and cannot leak into the
 * page's HTML. See docs/features/user-admin.md.
 */
class WishlistsRelationManager extends RelationManager
{
    protected static string $relationship = 'wishlists';

    protected static ?string $title = 'Lists';

    /** What a row may show. Anything personal about the list stays out. */
    private const LIST_COLUMNS = [
        'id', 'owner_user_id', 'title', 'kind', 'market', 'visibility', 'visible_to_friends',
        'is_default', 'published_at', 'created_at', 'updated_at',
    ];

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->select(self::LIST_COLUMNS)->withCount('items'))
            ->defaultSort('updated_at', 'desc')
            ->columns([
                TextColumn::make('title')->wrap()->searchable()
                    ->description(fn (Wishlist $record) => $record->is_default ? 'Default list' : null),
                TextColumn::make('kind')->badge()
                    ->formatStateUsing(fn ($state) => $state instanceof ListKind ? $state->value : (string) $state),
                TextColumn::make('market')->badge()
                    ->formatStateUsing(fn ($state) => $state instanceof Market ? $state->value : (string) $state),
                TextColumn::make('visibility')->label('Shared')
                    ->formatStateUsing(fn ($state) => $state instanceof ListVisibility ? $state->value : (string) $state),
                IconColumn::make('visible_to_friends')->label('Friends see it')->boolean(),
                TextColumn::make('items_count')->label('Products')->sortable(),
                TextColumn::make('published_at')->label('Community Cove')->date()->placeholder('—'),
                TextColumn::make('updated_at')->label('Changed')->since()->sortable(),
            ])
            ->recordActions([
                Action::make('products')
                    ->label('Products')
                    ->icon(Heroicon::OutlinedListBullet)
                    ->modalHeading(fn (Wishlist $record) => $record->title)
                    ->modalContent(fn (Wishlist $record): View => view('filament.users.list-products', [
                        'items' => $this->products($record),
                    ]))
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Close'),
            ]);
    }

    /**
     * The products on a list, and only what the admin is meant to see of them.
     *
     * Suggestions somebody else made that the owner has not accepted are left
     * out, as they are from the owner's own view (`Wishlist::items()`).
     *
     * @return list<array{title: string, priceCents: ?int, url: ?string, added: ?string}>
     */
    private function products(Wishlist $list): array
    {
        return WishlistItem::query()
            ->where('wishlist_id', $list->id)
            ->whereNotNull('accepted_at')
            ->with('group:id,market,slug,title,display_title,min_price')
            ->orderBy('created_at')
            ->get(['id', 'wishlist_id', 'group_id', 'snapshot_title', 'snapshot_price', 'created_at'])
            ->map(fn (WishlistItem $item): array => [
                'title' => $item->group?->displayTitle() ?? $item->snapshot_title,
                'priceCents' => $item->group?->min_price ?? $item->snapshot_price,
                // Our own product page only. An item added by hand carries a
                // link from somewhere else, which is hostile input (invariant 5)
                // and not worth a redirect from inside the panel.
                'url' => $item->group?->path(),
                'added' => $item->created_at?->toDateString(),
            ])
            ->all();
    }
}
