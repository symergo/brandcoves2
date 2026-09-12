<?php

declare(strict_types=1);

namespace App\Filament\Resources\Users\Tables;

use App\Enums\Market;
use App\Models\User;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;

class UsersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            // Counted in the same query as the rows, so a page of fifty
            // accounts is one query rather than a hundred and one.
            ->modifyQueryUsing(fn ($query) => $query->withCount(['wishlists', 'recipients']))
            ->columns([
                TextColumn::make('email')
                    ->searchable()
                    ->sortable()
                    ->copyable()
                    // Shown under the address rather than as its own column:
                    // most accounts have no name, and a column that is empty
                    // nine rows out of ten is noise.
                    ->description(fn (User $r) => filled($r->name) ? $r->name : null),

                TextColumn::make('preferred_market')
                    ->label('Market')
                    ->badge()
                    ->placeholder('—')
                    ->sortable(),

                IconColumn::make('is_admin')
                    ->label('Admin')
                    ->boolean()
                    // A tick only where it matters; a row of grey crosses says
                    // nothing.
                    ->falseIcon(null),

                IconColumn::make('email_opt_in')
                    ->label('Emails')
                    ->boolean()
                    ->falseIcon(null)
                    ->toggleable(),

                TextColumn::make('wishlists_count')
                    ->label('Lists')
                    ->numeric()
                    ->sortable(),

                TextColumn::make('recipients_count')
                    ->label('People')
                    ->numeric()
                    ->sortable()
                    ->toggleable(),

                TextColumn::make('created_at')
                    ->label('Joined')
                    ->since()
                    ->sortable(),

                TextColumn::make('email_verified_at')
                    ->label('Verified')
                    ->dateTime()
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                TernaryFilter::make('is_admin')
                    ->label('Administrators')
                    ->placeholder('Everyone')
                    ->trueLabel('Administrators only')
                    ->falseLabel('Shoppers only'),

                SelectFilter::make('preferred_market')
                    ->label('Market')
                    ->options(collect(Market::cases())->mapWithKeys(fn (Market $m) => [$m->value => $m->label()])->all()),

                TernaryFilter::make('email_opt_in')
                    ->label('Emails')
                    ->placeholder('All')
                    ->trueLabel('Opted in')
                    ->falseLabel('Not opted in'),
            ])
            ->recordActions([
                EditAction::make(),

                DeleteAction::make()
                    ->hidden(fn (User $r) => $r->getKey() === Auth::id())
                    ->modalDescription('Their lists, recipients, friendships and inbox go with the account. Nothing brings them back.'),
            ]);
    }
}
