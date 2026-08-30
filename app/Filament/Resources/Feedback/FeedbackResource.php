<?php

declare(strict_types=1);

namespace App\Filament\Resources\Feedback;

use App\Filament\Resources\Feedback\Pages\ListFeedback;
use App\Models\Feedback;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use UnitEnum;

/**
 * What visitors have told us is wrong.
 *
 * ## Why this exists at all
 *
 * A feedback form with nowhere to read it is a form that throws messages away
 * politely, which is worse than not having one — the visitor spent the effort
 * and believes somebody will look. This screen is the second half of the
 * feature, not an optional extra.
 *
 * ## Read, not edited
 *
 * There is no form: nothing here should be rewritten, because it is a record of
 * what somebody said. The one state worth keeping is whether a human has seen
 * it, which is `handled_at` and one action.
 *
 * The badge counts the unhandled ones, so the queue is visible from any screen
 * in the panel — a report is only useful while the price it is about is still
 * wrong.
 *
 * ## The reply address is on the row on purpose
 *
 * `Feedback::$hidden` keeps `email` out of any payload the site serialises;
 * this panel reads the attribute directly, which is the one place it is meant
 * to be legible. Replying is the only thing it was collected for, and an
 * address you have to run a query to find is one nobody replies to.
 */
class FeedbackResource extends Resource
{
    protected static ?string $model = Feedback::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedInboxArrowDown;

    protected static string|UnitEnum|null $navigationGroup = 'Community';

    protected static ?string $navigationLabel = 'Feedback';

    protected static ?string $modelLabel = 'feedback';

    protected static ?string $pluralModelLabel = 'feedback';

    public static function getNavigationBadge(): ?string
    {
        $waiting = Feedback::query()->unhandled()->count();

        return $waiting === 0 ? null : (string) $waiting;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    public static function form(Schema $schema): Schema
    {
        // Nothing here is editable. See the class docblock.
        return $schema->components([]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('created_at')->dateTime()->sortable()->label('Received'),

                TextColumn::make('market')->badge()->sortable(),

                // Wrapped and generously limited: the message is the record.
                // Truncated to a line it becomes a subject header for a body
                // nobody can open, since there is no edit page.
                TextColumn::make('message')->wrap()->limit(600)->searchable(),

                // The page they were on. Without it half the reports are
                // unanswerable — "the price is wrong" about nothing in
                // particular.
                TextColumn::make('path')->label('Page')->placeholder('—')->limit(60)->searchable(),

                TextColumn::make('email')->label('Reply to')->placeholder('—')->searchable()->copyable(),

                TextColumn::make('user.email')->label('Account')->placeholder('—')->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('handled_at')->dateTime()->label('Handled')->placeholder('—')->toggleable(),
            ])
            ->filters([
                TernaryFilter::make('handled')
                    ->label('Handled')
                    ->placeholder('All')
                    ->trueLabel('Handled')
                    ->falseLabel('Waiting')
                    ->queries(
                        true: fn ($query) => $query->whereNotNull('handled_at'),
                        false: fn ($query) => $query->whereNull('handled_at'),
                        blank: fn ($query) => $query,
                    )
                    // Opens on what still needs somebody, which is the only
                    // reason to come to this screen.
                    ->default(false),

                SelectFilter::make('market')
                    ->options(fn () => Feedback::query()
                        ->distinct()
                        ->orderBy('market')
                        ->pluck('market', 'market')
                        ->all()),
            ])
            ->recordActions([
                Action::make('handled')
                    ->icon(Heroicon::OutlinedCheck)
                    ->color('success')
                    ->visible(fn (Feedback $row) => $row->handled_at === null)
                    ->action(fn (Feedback $row) => $row->update(['handled_at' => now()])),

                // Reversible, because "handled" is a human judgement and the
                // filter defaults to hiding what it marks. Without this, one
                // mis-click removes a report from the only view of it.
                Action::make('reopen')
                    ->icon(Heroicon::OutlinedArrowUturnLeft)
                    ->color('gray')
                    ->visible(fn (Feedback $row) => $row->handled_at !== null)
                    ->action(fn (Feedback $row) => $row->update(['handled_at' => null])),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => ListFeedback::route('/')];
    }
}
