<?php

declare(strict_types=1);

namespace App\Filament\Resources\CovePlans;

use App\Enums\Market;
use App\Filament\Resources\CovePlans\Pages\ListCovePlans;
use App\Jobs\BuildDailyEdition;
use App\Models\CovePlan;
use App\Models\ProductGroup;
use App\Services\Cove\ObservanceCalendar;
use BackedEnum;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use UnitEnum;

/**
 * The editorial calendar.
 *
 * Until this existed, a Daily Cove was assembled at 06:00 and published at
 * 09:00, and nobody saw it before the readers did. Fine while the theme was a
 * generated line; useless the moment it is an occasion, because you cannot plan
 * around Mother's Day three hours before it starts.
 *
 * A plan is an *intention*, not an edition. Approving one tells the builder
 * what the day is for; the edition is still what gets published, and a plan
 * whose catalogue turns out too thin simply does not come off. That separation
 * is what stops a scheduled date from guaranteeing an empty page.
 */
class CovePlanResource extends Resource
{
    protected static ?string $model = CovePlan::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCalendar;

    protected static string|UnitEnum|null $navigationGroup = 'Content';

    protected static ?string $navigationLabel = 'Cove calendar';

    protected static ?string $modelLabel = 'planned Cove';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('What and when')
                ->schema([
                    Select::make('market')
                        ->options(collect(Market::cases())
                            ->mapWithKeys(fn (Market $m) => [$m->value => $m->label()])->all())
                        ->required(),

                    DatePicker::make('drop_date')
                        ->label('Date')
                        ->helperText('Leave empty for a themed Cove with no fixed day — it waits in the queue until someone builds it. A date makes it a Daily Cove.')
                        // The unique index covers dated rows only, so two ideas
                        // can sit undated but one Tuesday cannot have two plans.
                        ->unique(ignoreRecord: true, modifyRuleUsing: fn ($rule, $get) => $rule->where('market', $get('market'))),

                    TextInput::make('title')->required()->maxLength(120),
                    Textarea::make('blurb')->rows(2)
                        ->helperText('One line, shown under the title and used as the meta description.'),

                    /*
                     * The article itself, when someone wrote one.
                     *
                     * Usually arrives through the editorial API rather than
                     * being typed here — but it has to be visible and editable
                     * in the panel, because reviewing what an automated writer
                     * produced before approving it is the entire point of the
                     * draft/approve split. See docs/features/editorial-api.md.
                     */
                    Textarea::make('editorial')
                        ->label('Editorial')
                        ->rows(10)
                        ->columnSpanFull()
                        ->maxLength((int) config('giftcoves.editorial_api.max_editorial_chars'))
                        ->helperText('Two or three paragraphs, blank line between them. Link with tokens, never URLs: [[product:1234|label]], [[brand:Sony]], [[search:phrase]] — anything outside the edition\'s own products is rendered as plain text. Written here, it replaces the AI pass entirely and survives every rebuild.'),
                ])
                ->columns(2),

            Section::make('What to show')
                ->schema([
                    TagsInput::make('queries')
                        ->label('Steer the finds toward')
                        ->helperText('Product words, not themes: "hondenmand" finds products, "cadeau voor hondenliefhebbers" finds nothing. A bias, never a filter — a thin day still publishes.'),

                    Select::make('pinned_group_ids')
                        ->label('Pinned products')
                        ->multiple()
                        ->searchable()
                        // Searched rather than listed: the catalogue is tens of
                        // thousands of rows and a dropdown of it is unusable.
                        ->getSearchResultsUsing(fn (string $search) => ProductGroup::query()
                            ->presentable()
                            ->where('title', 'ilike', '%'.$search.'%')
                            ->limit(25)
                            ->pluck('title', 'id')
                            ->all())
                        ->getOptionLabelsUsing(fn (array $values) => ProductGroup::query()
                            ->whereIn('id', $values)->pluck('title', 'id')->all())
                        ->helperText('These lead the edition and are exempt from the 90-day repeat memory — the point of a pin is to override the engine, so one it could veto would not be a pin.'),
                ]),

            Section::make('Review')
                ->schema([
                    Select::make('status')
                        ->options([
                            'draft' => 'draft — not picked up by the builder',
                            'approved' => 'approved — will be used',
                            'used' => 'used',
                            'rejected' => 'rejected',
                        ])
                        ->default('draft')
                        ->required()
                        ->helperText('Only approved plans are used. The clock coming round is not a reason to publish someone thinking out loud.'),

                    Textarea::make('note')->rows(2)->label('Note to whoever reads this later'),
                ])
                ->columns(2),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('drop_date')
                    ->date()
                    ->sortable()
                    ->placeholder('— queued')
                    // The observance for that date, so an editor can see what
                    // the calendar already thinks the day is about before
                    // overriding it.
                    ->description(function (CovePlan $record): ?string {
                        if ($record->drop_date === null) {
                            return null;
                        }

                        $observance = app(ObservanceCalendar::class)->on(
                            CarbonImmutable::instance($record->drop_date),
                            $record->market,
                        );

                        return $observance === null ? null : 'also: '.$observance->title($record->market);
                    }),

                TextColumn::make('market')->badge()->sortable(),
                TextColumn::make('title')->searchable()->limit(40),

                TextColumn::make('status')
                    ->badge()
                    ->color(fn (string $state) => match ($state) {
                        'approved' => 'success',
                        'used' => 'gray',
                        'rejected' => 'danger',
                        default => 'warning',
                    }),

                TextColumn::make('pinned')
                    ->label('Pinned')
                    ->state(fn (CovePlan $r) => count((array) $r->pinned_group_ids) ?: '—'),

                TextColumn::make('edition.id')->label('Edition')->placeholder('—'),
                TextColumn::make('author.email')->label('By')->toggleable(),
            ])
            ->filters([
                SelectFilter::make('market'),
                SelectFilter::make('status')->options([
                    'draft' => 'draft',
                    'approved' => 'approved',
                    'used' => 'used',
                    'rejected' => 'rejected',
                ]),
            ])
            ->headerActions([CreateAction::make()])
            ->recordActions([
                EditAction::make(),

                Action::make('approve')
                    ->icon(Heroicon::OutlinedCheck)
                    ->visible(fn (CovePlan $r) => $r->status === 'draft')
                    ->requiresConfirmation()
                    ->action(function (CovePlan $record): void {
                        $record->update(['status' => 'approved']);
                        Notification::make()->title('Approved')->success()->send();
                    }),

                Action::make('preview')
                    ->label('Build now')
                    ->icon(Heroicon::OutlinedPlay)
                    ->visible(fn (CovePlan $r) => $r->status === 'approved' && $r->drop_date !== null)
                    ->requiresConfirmation()
                    ->modalDescription('Builds the edition for that date immediately so you can see it before the morning. Rebuilding is idempotent — it updates in place rather than creating a second edition.')
                    ->action(function (CovePlan $record): void {
                        // The plan's date, not today. Dispatching without one
                        // built today's edition from a plan written for next
                        // Tuesday — the plan for today would not match, so the
                        // button appeared to do nothing.
                        BuildDailyEdition::dispatch($record->market, $record->drop_date->toDateString());

                        Notification::make()
                            ->title('Build queued')
                            ->body('Watch Horizon, then open the edition for that date.')
                            ->success()
                            ->send();
                    }),
            ])
            ->toolbarActions([BulkActionGroup::make([DeleteBulkAction::make()])])
            // Queued ideas last, upcoming dates first: the calendar is a
            // forward-looking document.
            ->defaultSort('drop_date');
    }

    public static function getPages(): array
    {
        return ['index' => ListCovePlans::route('/')];
    }
}
