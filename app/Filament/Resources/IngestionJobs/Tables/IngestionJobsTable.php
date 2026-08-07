<?php

declare(strict_types=1);

namespace App\Filament\Resources\IngestionJobs\Tables;

use App\Enums\JobStatus;
use App\Enums\Market;
use App\Models\IngestionJob;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

/**
 * Read-only. These rows are written by the ingestion job and editing one by
 * hand would corrupt a resume point — the cursor is the only thing standing
 * between a redeploy and re-ingesting a 400 MB feed from the top.
 */
class IngestionJobsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('job_key')
                    ->label('Feed')
                    ->searchable()
                    ->description(fn (IngestionJob $r) => $r->market?->label()),

                TextColumn::make('status')
                    ->badge()
                    ->color(fn (JobStatus $state) => match ($state) {
                        JobStatus::Completed => 'success',
                        JobStatus::Running => 'info',
                        JobStatus::Failed => 'danger',
                        JobStatus::Cancelled => 'warning',
                        JobStatus::Pending => 'gray',
                    })
                    ->sortable(),

                TextColumn::make('processed')
                    ->label('Progress')
                    ->numeric()
                    ->formatStateUsing(function (IngestionJob $r): string {
                        $percent = $r->progressPercent();

                        // Awin exposes no cheap row count, so most runs report
                        // rows processed rather than a percentage.
                        return $percent === null
                            ? number_format($r->processed).' rows'
                            : number_format($r->processed).' / '.number_format((int) $r->total)." ({$percent}%)";
                    }),

                TextColumn::make('started_at')->since()->label('Started')->placeholder('—'),
                TextColumn::make('finished_at')->since()->label('Finished')->placeholder('—'),

                TextColumn::make('attempts')
                    ->numeric()
                    // Repeated attempts mean a feed that keeps dying part-way.
                    ->color(fn (int $state) => $state > 2 ? 'warning' : 'gray'),

                TextColumn::make('last_error')
                    ->label('Error')
                    ->color('danger')
                    ->limit(70)
                    ->tooltip(fn (IngestionJob $r) => $r->last_error)
                    ->placeholder('—'),
            ])
            ->filters([
                SelectFilter::make('status')->options(JobStatus::class),
                SelectFilter::make('market')->options(Market::class),
            ])
            ->defaultSort('updated_at', 'desc')
            // Running ingestions update continuously; a static table would look
            // stuck on exactly the screen you open to check progress.
            ->poll('10s')
            ->recordActions([])
            ->toolbarActions([]);
    }
}
