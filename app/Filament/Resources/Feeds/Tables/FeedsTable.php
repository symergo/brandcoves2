<?php

declare(strict_types=1);

namespace App\Filament\Resources\Feeds\Tables;

use App\Enums\Market;
use App\Enums\Source;
use App\Jobs\GroupProducts;
use App\Jobs\IngestFeed;
use App\Models\Feed;
use App\Models\IngestionJob;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

class FeedsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('label')
                    ->searchable()
                    ->description(fn (Feed $r) => $r->jobKey()),

                TextColumn::make('market')->badge()->sortable(),
                TextColumn::make('source')->badge(),

                TextColumn::make('merchant.name')
                    ->label('Merchant')
                    ->placeholder('— discovered on first run —')
                    ->searchable(),

                /*
                 * A switch, not an indicator.
                 *
                 * Turning one feed off was an Edit-form round trip, which is the
                 * wrong shape for the moment it is needed: a single advertiser
                 * dumping malformed rows, noticed while looking at this table.
                 * The source-wide equivalent lives on Market supply; this is the
                 * one-merchant version of the same decision.
                 *
                 * Off stops the next ingestion — IngestFeed returns on a
                 * disabled feed — and leaves everything already ingested in
                 * place. bc:withdraw-source is what removes those.
                 */
                ToggleColumn::make('enabled')
                    ->sortable()
                    ->tooltip('Off stops the next ingestion. Rows already ingested stay in search.'),

                TextColumn::make('last_run_at')
                    ->label('Last run')
                    ->since()
                    ->placeholder('never')
                    ->sortable(),

                TextColumn::make('last_row_count')
                    ->label('Rows')
                    ->numeric()
                    ->sortable(),

                // Surfaced in the table rather than hidden on the edit form: a
                // silently failing feed is the most likely thing to go wrong
                // here, and the whole catalogue depends on these running.
                TextColumn::make('last_error')
                    ->label('Error')
                    ->color('danger')
                    ->limit(60)
                    ->tooltip(fn (Feed $r) => $r->last_error)
                    ->placeholder('—'),
            ])
            ->filters([
                SelectFilter::make('market')->options(Market::class),
                SelectFilter::make('source')->options(Source::class),
                TernaryFilter::make('enabled'),
                TernaryFilter::make('last_error')
                    ->label('Failing')
                    ->nullable()
                    ->trueLabel('Failing only')
                    ->falseLabel('Healthy only')
                    ->queries(
                        true: fn ($q) => $q->whereNotNull('last_error'),
                        false: fn ($q) => $q->whereNull('last_error'),
                        blank: fn ($q) => $q,
                    ),
            ])
            ->recordActions([
                Action::make('ingest')
                    ->label('Ingest now')
                    ->icon('heroicon-o-arrow-down-tray')
                    ->requiresConfirmation()
                    ->modalDescription('Queues a full ingestion of this feed. It resumes from its saved position unless you reset it first.')
                    ->action(function (Feed $feed): void {
                        IngestFeed::dispatch($feed->id);
                        GroupProducts::dispatch($feed->market);

                        Notification::make()
                            ->title('Ingestion queued')
                            ->body('Watch progress under Ingestion jobs.')
                            ->success()
                            ->send();
                    }),

                Action::make('reset')
                    ->label('Reset cursor')
                    ->icon('heroicon-o-backward')
                    ->color('warning')
                    ->requiresConfirmation()
                    ->modalDescription('Discards the saved position so the next run ingests from the top. Use this when a feed changed shape.')
                    ->action(function (Feed $feed): void {
                        IngestionJob::query()
                            ->where('job_key', $feed->jobKey())
                            ->update(['cursor' => null, 'processed' => 0]);

                        Notification::make()->title('Cursor reset')->success()->send();
                    }),

                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ])
            ->defaultSort('market');
    }
}
