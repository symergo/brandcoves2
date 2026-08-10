<?php

declare(strict_types=1);

namespace App\Filament\Resources\DailyEditions;

use App\Enums\Market;
use App\Enums\PublishStatus;
use App\Filament\Resources\DailyEditions\Pages\ListDailyEditions;
use App\Jobs\BuildDailyEdition;
use App\Models\DailyPickSet;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use UnitEnum;

/**
 * The Daily Cove's editions.
 *
 * Mostly a window rather than an editor: an edition is assembled by a job at
 * 06:00 and publishes at 09:00, and the useful admin questions are "did today's
 * build work" and "why has nothing published".
 *
 * The rebuild action exists because the answer is sometimes "it ran on a thin
 * catalogue day and skipped". Rebuilding is idempotent — it updates the
 * edition in place rather than creating a second one for the same date.
 */
class DailyEditionResource extends Resource
{
    protected static ?string $model = DailyPickSet::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCalendarDays;

    protected static string|UnitEnum|null $navigationGroup = 'Content';

    protected static ?string $navigationLabel = 'Daily Cove';

    protected static ?string $modelLabel = 'edition';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('status')
                ->options(collect(PublishStatus::cases())
                    ->mapWithKeys(fn ($c) => [$c->value => $c->value])->all())
                ->required(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('drop_date')->date()->sortable(),
                TextColumn::make('market')->badge()->sortable(),
                TextColumn::make('theme_title')->limit(40)->searchable(),

                TextColumn::make('theme_source')
                    ->badge()
                    ->label('Theme from')
                    // 'curated' here means the AI call did not happen — either
                    // disabled, capped or failed. Worth seeing at a glance,
                    // because a run of them is a signal rather than a setting.
                    ->color(fn (string $state) => $state === 'ai' ? 'success' : 'gray'),

                TextColumn::make('picks_count')->counts('picks')->label('Finds'),

                IconColumn::make('guide_id')->boolean()->label('Guide'),
                TextColumn::make('published_at')->dateTime()->sortable(),
            ])
            ->filters([
                SelectFilter::make('market'),
                SelectFilter::make('status'),
            ])
            ->headerActions([
                Action::make('build')
                    ->label('Build today')
                    ->icon(Heroicon::OutlinedSparkles)
                    ->form([
                        Select::make('market')
                            ->options(collect(Market::cases())
                                ->mapWithKeys(fn (Market $m) => [$m->value => $m->label()])->all())
                            ->required(),
                    ])
                    ->action(function (array $data): void {
                        // Queued, not inline: the builder mines topics, may
                        // write a guide and may call a model. None of that
                        // belongs in an admin request.
                        BuildDailyEdition::dispatch(Market::from($data['market']));

                        Notification::make()
                            ->title('Build queued')
                            ->body('Watch Horizon. Rebuilding a date updates it in place rather than creating a second edition.')
                            ->success()
                            ->send();
                    }),
            ])
            ->recordActions([
                Action::make('view')
                    ->url(fn (DailyPickSet $record) => url("/{$record->market->value}/daily/{$record->drop_date->toDateString()}"))
                    ->openUrlInNewTab()
                    ->icon(Heroicon::OutlinedArrowTopRightOnSquare),
            ])
            ->toolbarActions([BulkActionGroup::make([DeleteBulkAction::make()])])
            ->defaultSort('drop_date', 'desc');
    }

    public static function getPages(): array
    {
        return ['index' => ListDailyEditions::route('/')];
    }
}
