<?php

declare(strict_types=1);

namespace App\Filament\Resources\IngestionJobs\Tables;

use App\Enums\JobStatus;
use App\Enums\Market;
use App\Enums\Source;
use App\Models\Feed;
use App\Models\IngestionJob;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Read-only. These rows are written by the ingestion job and editing one by
 * hand would corrupt a resume point — the cursor is the only thing standing
 * between a redeploy and re-ingesting a 400 MB feed from the top.
 */
class IngestionJobsTable
{
    /**
     * The feeds behind the jobs, by job key, read once per request.
     *
     * A job row carries only its key ("awin:12345:be-nl"), which says nothing
     * to a person: the shop, the feed's own name and the account it is
     * downloaded through are on the feed (owner, 2026-11-14). There is no
     * foreign key between the two, the key is derived (`Feed::jobKey()`), so
     * the feeds are read in one query and matched here instead of one query
     * per row.
     *
     * @var array<string, Feed>|null
     */
    private static ?array $feeds = null;

    public static function feedFor(IngestionJob $job): ?Feed
    {
        self::$feeds ??= Feed::query()
            ->with('merchant:id,name,domain')
            ->get()
            ->keyBy(fn (Feed $feed) => $feed->jobKey())
            ->all();

        return self::$feeds[(string) $job->job_key] ?? null;
    }

    /** For a test, or a long-lived worker, that changes feeds between two reads. */
    public static function forgetFeeds(): void
    {
        self::$feeds = null;
    }

    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('job_key')
                    ->label('Feed')
                    // The feed's own name where there is one; the key underneath,
                    // because it is what the log lines and the commands use.
                    ->formatStateUsing(fn (string $state, IngestionJob $r) => self::feedFor($r)?->label ?: $state)
                    ->description(fn (IngestionJob $r) => self::feedFor($r) === null
                        ? $r->market?->label()
                        : $r->job_key.' · '.$r->market?->label())
                    // By key, by the feed's name or by the shop's.
                    ->searchable(query: function (Builder $query, string $search): Builder {
                        $like = '%'.mb_strtolower($search).'%';

                        $keys = Feed::query()
                            ->where(fn (Builder $q) => $q
                                ->whereRaw('lower(label) like ?', [$like])
                                ->orWhereHas('merchant', fn (Builder $m) => $m->whereRaw('lower(name) like ?', [$like])))
                            ->get()
                            ->map(fn (Feed $feed) => $feed->jobKey())
                            ->all();

                        return $query->where(fn (Builder $q) => $q
                            ->whereRaw('lower(job_key) like ?', [$like])
                            ->orWhereIn('job_key', $keys));
                    }),

                TextColumn::make('merchant')
                    ->label('Shop')
                    ->state(fn (IngestionJob $r) => self::feedFor($r)?->merchant?->name)
                    ->description(fn (IngestionJob $r) => self::feedFor($r)?->merchant?->domain)
                    ->placeholder('—'),

                TextColumn::make('source')
                    ->badge()
                    ->color('gray')
                    ->formatStateUsing(fn (Source $state) => $state->value)
                    // The affiliate account the feed is downloaded through: a
                    // 401 on every feed of one account is that account's key.
                    ->description(fn (IngestionJob $r) => self::feedFor($r)?->account)
                    ->toggleable(),

                TextColumn::make('feed_state')
                    ->label('Feed is')
                    ->badge()
                    ->state(fn (IngestionJob $r) => match (self::feedFor($r)?->enabled) {
                        true => 'enabled',
                        false => 'disabled',
                        null => 'no feed',
                    })
                    ->color(fn (string $state) => match ($state) {
                        'enabled' => 'success',
                        'disabled' => 'warning',
                        default => 'gray',
                    })
                    ->toggleable(),

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

                TextColumn::make('last_row_count')
                    ->label('Rows last run')
                    ->state(fn (IngestionJob $r) => self::feedFor($r)?->last_row_count)
                    ->numeric()
                    ->placeholder('—')
                    ->toggleable(),

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
                SelectFilter::make('source')->options(collect(Source::cases())
                    ->mapWithKeys(fn (Source $s) => [$s->value => $s->value])->all()),
                SelectFilter::make('merchant')
                    ->label('Shop')
                    ->searchable()
                    ->options(fn () => Feed::query()
                        ->join('merchants', 'merchants.id', '=', 'feeds.merchant_id')
                        ->orderBy('merchants.name')
                        ->pluck('merchants.name', 'merchants.id')
                        ->all())
                    ->query(function (Builder $query, array $data): Builder {
                        if (blank($data['value'] ?? null)) {
                            return $query;
                        }

                        return $query->whereIn('job_key', Feed::query()
                            ->where('merchant_id', $data['value'])
                            ->get()
                            ->map(fn (Feed $feed) => $feed->jobKey())
                            ->all());
                    }),
            ])
            ->defaultSort('updated_at', 'desc')
            // Running ingestions update continuously; a static table would look
            // stuck on exactly the screen you open to check progress.
            ->poll('10s')
            ->recordActions([])
            ->toolbarActions([]);
    }
}
