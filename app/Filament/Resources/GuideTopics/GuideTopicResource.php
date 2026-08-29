<?php

declare(strict_types=1);

namespace App\Filament\Resources\GuideTopics;

use App\Filament\Resources\CovePlans\CovePlanResource;
use App\Filament\Resources\GuideTopics\Pages\ListGuideTopics;
use App\Models\GuideTopic;
use App\Services\Guides\TopicPlanner;
use BackedEnum;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use InvalidArgumentException;
use UnitEnum;

/**
 * The Cove topic queue.
 *
 * Where `CovePlanResource` schedules *daily* editions, this is the queue of
 * evergreen Coves — the themed reference pages that earn their traffic over
 * years. Two sources feed it and they are deliberately distinguishable at a
 * glance:
 *
 *  - **search** — mined from 30 days of our own searches. Real demand nobody
 *    else can measure, and the reason `search_volume` must stay an honest number.
 *  - **seasonal** — from `config/cove_seasons.php`, with a window that opens
 *    months before the season. A search log cannot see a season coming: barbecue
 *    peaks in June, so a log-only queue commissions that Cove in July.
 *
 * The whole point of the screen is that a topic queue you cannot inspect before
 * it generates is a content farm. Nothing here publishes anything; it decides
 * what is worth writing, and the builder still has to find products.
 */
class GuideTopicResource extends Resource
{
    protected static ?string $model = GuideTopic::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedQueueList;

    protected static string|UnitEnum|null $navigationGroup = 'Content';

    protected static ?string $navigationLabel = 'Cove topics';

    protected static ?string $modelLabel = 'Cove topic';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Topic')
                ->schema([
                    TextInput::make('topic')->required()->maxLength(120)
                        ->helperText('The head noun people search for. Clusters merge on this, so "koptelefoon" absorbs "goedkope koptelefoon".'),

                    TagsInput::make('member_queries')
                        ->label('Queries in this cluster')
                        ->helperText('What the builder retrieves from. Mined queries are what people actually typed — worth keeping when you add your own.'),
                ]),

            Section::make('Season')
                ->schema([
                    /*
                     * MM-DD, and free text rather than a date picker: these
                     * recur every year, and a picker would force a year onto
                     * something that does not have one.
                     */
                    TextInput::make('season_from')->label('Window opens (MM-DD)')->maxLength(5)
                        ->helperText('Open it well before the season. A Halloween Cove written on 20 October is nearly worthless; the same Cove written on 1 August is an asset for a decade.'),
                    TextInput::make('season_to')->label('Window closes (MM-DD)')->maxLength(5)
                        ->helperText('May be earlier than the opening date — the window then wraps the year end.'),
                ])
                ->columns(2),

            Section::make('Review')
                ->schema([
                    Select::make('status')
                        ->options([
                            'candidate' => 'candidate — visible to the builder',
                            'queued' => 'queued — next up',
                            'published' => 'published',
                            'rejected' => 'rejected — never offered again',
                        ])
                        ->required()
                        ->helperText('A rejected topic stays rejected: the nightly mining pass never overturns a decision made here.'),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('topic')->searchable()->sortable(),
                TextColumn::make('market')->badge()->sortable(),

                TextColumn::make('origin')
                    ->badge()
                    ->color(fn (string $state) => $state === 'seasonal' ? 'info' : 'gray'),

                /*
                 * Whether the window is open right now.
                 *
                 * The single most useful column on the screen: an in-season topic
                 * is what the builder will pick next, ahead of anything the log
                 * scored higher.
                 */
                TextColumn::make('window')
                    ->label('Season')
                    ->state(function (GuideTopic $record): string {
                        if ($record->season_from === null || $record->season_to === null) {
                            return '—';
                        }

                        $today = CarbonImmutable::today()->format('m-d');
                        $from = (string) $record->season_from;
                        $to = (string) $record->season_to;

                        $open = $from <= $to
                            ? $today >= $from && $today <= $to
                            : $today >= $from || $today <= $to;

                        return ($open ? '● ' : '○ ')."{$from} → {$to}";
                    })
                    ->description(fn (GuideTopic $r) => $r->origin === 'seasonal' ? null : 'no window'),

                TextColumn::make('search_volume')
                    ->label('Searches / 30d')
                    ->numeric()
                    ->sortable()
                    // Zero on a seasonal topic is correct and not a bug: we never
                    // fabricate a volume, because this column is the one honest
                    // demand signal the system has.
                    ->tooltip('Measured, never estimated. A seasonal topic reads zero until people actually search for it.'),

                TextColumn::make('available_products')
                    ->label('Products')
                    ->numeric()
                    ->sortable()
                    ->color(fn (int $state) => $state < 5 ? 'danger' : null)
                    ->tooltip('Below five, a "best X" page reads as thin to a reader and to a crawler, so the builder will not take it.'),

                TextColumn::make('score')->numeric(decimalPlaces: 1)->sortable()->toggleable(),

                /*
                 * Failed build attempts.
                 *
                 * The number that makes a permanent gap legible: a topic on its
                 * ninth attempt is not waiting for stock, it is a topic whose
                 * products we do not sell — which is a reason to go and find an
                 * advertiser, not to keep retrying.
                 */
                TextColumn::make('attempts')
                    ->label('Tried')
                    ->state(fn (GuideTopic $r) => $r->attempts > 0
                        ? $r->attempts.'× · '.($r->last_attempt_at?->diffForHumans() ?? '')
                        : '—')
                    ->color(fn (GuideTopic $r) => $r->attempts >= 3 ? 'danger' : null)
                    ->tooltip('A failed build parks a topic for '.GuideTopic::RETRY_AFTER_DAYS.' days rather than banning it — thin today is not thin forever.')
                    ->toggleable(),

                TextColumn::make('status')
                    ->badge()
                    ->color(fn (string $state) => match ($state) {
                        'published' => 'success',
                        'queued' => 'info',
                        'rejected' => 'danger',
                        default => 'gray',
                    }),

                TextColumn::make('guide.title')->label('Cove')->placeholder('—')->limit(30),
            ])
            ->filters([
                SelectFilter::make('market'),
                SelectFilter::make('origin')->options(['search' => 'search log', 'seasonal' => 'seasonal']),
                SelectFilter::make('status')->options([
                    'candidate' => 'candidate',
                    'queued' => 'queued',
                    'published' => 'published',
                    'rejected' => 'rejected',
                ]),
            ])
            ->recordActions([
                EditAction::make(),

                /*
                 * A topic becomes a draft plan, not a published page.
                 *
                 * This queue used to be a second publishing pipeline: queue a
                 * topic, and one night the builder chose its own products, wrote
                 * about them and published — with no shortlist anyone could
                 * curate and nowhere to say why a product was on it.
                 *
                 * Now it is an idea feed. The topic supplies what only it knows
                 * — the phrase people actually searched for, the season, the
                 * measured volume — and a person curates the rest.
                 */
                Action::make('draft')
                    ->label('Draft a plan')
                    ->icon(Heroicon::OutlinedPencilSquare)
                    ->color('primary')
                    ->visible(fn (GuideTopic $r) => $r->plan_id === null && $r->status !== 'rejected')
                    ->action(function (GuideTopic $record, TopicPlanner $planner): void {
                        try {
                            $plan = $planner->draft($record);
                        } catch (InvalidArgumentException $e) {
                            Notification::make()->title('Could not draft it')->body($e->getMessage())->danger()->send();

                            return;
                        }

                        Notification::make()
                            ->title('Drafted with '.$plan->items()->count().' suggested product(s)')
                            ->body('Curate it in the Cove planner, then approve.')
                            ->success()
                            ->send();
                    })
                    ->after(fn (GuideTopic $record) => redirect(
                        CovePlanResource::getUrl('curate', ['record' => $record->refresh()->plan_id])
                    )),

                Action::make('openPlan')
                    ->label('Its plan')
                    ->icon(Heroicon::OutlinedSquares2x2)
                    ->visible(fn (GuideTopic $r) => $r->plan_id !== null)
                    ->url(fn (GuideTopic $r) => CovePlanResource::getUrl('curate', ['record' => $r->plan_id])),

                Action::make('reject')
                    ->icon(Heroicon::OutlinedXMark)
                    ->color('danger')
                    ->visible(fn (GuideTopic $r) => in_array($r->status, ['candidate', 'queued'], true))
                    ->requiresConfirmation()
                    ->modalDescription('The nightly pass will never offer this topic again, in this market.')
                    ->action(function (GuideTopic $record): void {
                        $record->update(['status' => 'rejected']);
                        Notification::make()->title('Rejected')->success()->send();
                    }),
            ])
            ->toolbarActions([BulkActionGroup::make([DeleteBulkAction::make()])])
            // Highest score first, which for a seasonal row is not the ordering
            // the builder uses — it goes by how soon the window shuts. The filter
            // on `origin` is how you see that view.
            ->defaultSort('score', 'desc');
    }

    public static function getPages(): array
    {
        return ['index' => ListGuideTopics::route('/')];
    }
}
