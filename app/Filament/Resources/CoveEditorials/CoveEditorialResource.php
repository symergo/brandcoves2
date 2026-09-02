<?php

declare(strict_types=1);

namespace App\Filament\Resources\CoveEditorials;

use App\Enums\CoveKind;
use App\Enums\PublishStatus;
use App\Filament\Resources\CoveEditorials\Pages\EditCoveEditorial;
use App\Filament\Resources\CoveEditorials\Pages\ListCoveEditorials;
use App\Filament\Resources\CoveEditorials\RelationManagers\PicksRelationManager;
use App\Filament\Resources\CovePlans\CovePlanResource;
use App\Jobs\BuildCove;
use App\Jobs\RedoCove;
use App\Models\DailyPickSet;
use App\Support\PreviewAccess;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/**
 * Everything that has been published, whatever kind it is.
 *
 * This replaces two navigation entries that used to be separate subsystems:
 * "Daily Cove" listed `daily_pick_sets` and was nearly read-only, and "Guides"
 * listed `guides` and was fully editable. They answered different questions
 * about the same job, and neither could answer "what is live in `nl-nl`".
 *
 * Since the fold there is one editorial table, so this is one screen with tabs.
 *
 * ## Why it is editable
 *
 * The shortlist is chosen by us and the prose by a model, and the prose is the
 * part that occasionally needs a human. Publishing generated copy with no way to
 * fix a sentence is how a site ends up with a page it cannot defend. Guides were
 * always editable here; folding them into the editions table must not quietly
 * take that away.
 *
 * ## Rebuild and redo are different things
 *
 * They sit next to each other because that is where you look for both, which
 * makes the wording load-bearing. **Rebuild** reproduces the page from the same
 * plan — routine, idempotent, what the scheduler does. **Redo** deliberately
 * throws the inputs away to get a different page at the same URL, and destroys
 * the reader reactions on the way. See `EditionBuilder::redo()`.
 */
class CoveEditorialResource extends Resource
{
    protected static ?string $model = DailyPickSet::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedNewspaper;

    protected static string|UnitEnum|null $navigationGroup = 'Content';

    protected static ?string $navigationLabel = 'Cove editorials';

    protected static ?string $modelLabel = 'Cove';

    protected static ?string $recordTitleAttribute = 'theme_title';

    /**
     * The plan comes with the row.
     *
     * Four row actions ask whether this Cove has one, and lazy loading is
     * disabled application-wide — so without this the table throws
     * `LazyLoadingViolationException` on the first row rather than doing N+1
     * quietly, which is the better failure and still a 500 on a page somebody
     * opened to fix something else.
     */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with('plan');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Copy')
                ->schema([
                    TextInput::make('theme_title')
                        ->label('Title')
                        ->required()
                        ->maxLength(160),

                    Textarea::make('theme_blurb')
                        ->label('Intro')
                        ->rows(3)
                        ->helperText('One or two sentences under the title. Link tokens work here and are flattened to plain text wherever this is used as a card blurb.'),

                    Textarea::make('editorial')
                        ->label('Editorial')
                        ->rows(10)
                        ->columnSpanFull()
                        // A column's prose. An article's words are the body and
                        // the FAQ below.
                        ->visible(fn (?DailyPickSet $record) => ! ($record?->kind->isArticle() ?? false))
                        ->helperText('Stored with its link tokens unresolved, so the anchors follow the market the page is read in and a product that later disappears degrades to plain text.'),

                    Textarea::make('body')
                        ->label('How to choose')
                        ->rows(8)
                        ->columnSpanFull()
                        ->visible(fn (?DailyPickSet $record) => $record?->kind->isArticle() ?? false)
                        // Rendered as plain paragraphs, never as Markdown: the
                        // copy comes from a language model, and the one thing
                        // you never do with model output is hand it to
                        // something that interprets markup.
                        ->helperText('Plain text. Blank lines separate paragraphs; markup is not rendered.'),

                    Repeater::make('faq')
                        ->label('FAQ')
                        ->columnSpanFull()
                        ->visible(fn (?DailyPickSet $record) => $record?->kind->isArticle() ?? false)
                        ->schema([
                            TextInput::make('q')->label('Question')->required()->maxLength(200),
                            Textarea::make('a')->label('Answer')->required()->rows(2)->maxLength(600),
                        ])
                        ->columns(2)
                        ->defaultItems(0)
                        ->addActionLabel('Add a question')
                        ->helperText('Rendered as structured data. Both halves or neither — a half-empty pair renders as a broken FAQPage and Google will say so.'),
                ]),

            Section::make('Publishing')
                ->schema([
                    Select::make('status')
                        ->options(collect(PublishStatus::cases())
                            ->mapWithKeys(fn ($c) => [$c->value => $c->value])->all())
                        ->required(),

                    TextInput::make('meta_description')
                        ->maxLength(160)
                        ->helperText('Left empty, it is trimmed from the intro.'),

                    TextInput::make('focus_keyphrase')
                        ->disabled()
                        ->helperText('Set on the plan, not here — it is what the page was written to answer.'),
                ])
                ->columns(3),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                /*
                 * One column for the address, because four of the five kinds
                 * have no date. A date column alone would leave most rows blank
                 * and nothing identifying them.
                 */
                TextColumn::make('drop_date')
                    ->label('When / where')
                    ->date()
                    ->sortable()
                    ->placeholder(fn (DailyPickSet $r) => '/'.$r->slug)
                    ->searchable(query: fn ($query, string $search) => $query->where('slug', 'ilike', "%{$search}%")),

                TextColumn::make('kind')
                    ->badge()
                    ->sortable()
                    ->color(fn (CoveKind $state) => match ($state) {
                        CoveKind::Daily => 'gray',
                        CoveKind::Persona => 'info',
                        CoveKind::Advice => 'warning',
                        default => 'success',
                    })
                    ->formatStateUsing(fn (CoveKind $state) => $state->label()),

                TextColumn::make('market')->badge()->sortable(),
                TextColumn::make('theme_title')->label('Title')->searchable()->limit(40),

                TextColumn::make('status')->badge()->sortable(),

                TextColumn::make('picks_count')->counts('picks')->label('Products'),

                /*
                 * 'curated' here means the AI call did not happen — disabled,
                 * capped or failed. Worth seeing at a glance, because a run of
                 * them is a signal rather than a setting. 'planned' means a
                 * person wrote it and nothing was spent.
                 */
                TextColumn::make('editorial_source')
                    ->label('Words from')
                    ->badge()
                    ->color(fn (?string $state) => match ($state) {
                        'ai' => 'success',
                        'planned' => 'info',
                        default => 'gray',
                    })
                    ->toggleable(),

                // Why this page exists, and a fact no competitor has.
                TextColumn::make('source_volume')
                    ->label('Searches')
                    ->numeric()
                    ->sortable()
                    ->description('30-day volume when written')
                    ->toggleable(isToggledHiddenByDefault: true),

                IconColumn::make('plan_id')
                    ->label('Planned')
                    ->boolean()
                    ->state(fn (DailyPickSet $r) => $r->plan !== null)
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('published_at')->dateTime()->sortable(),
            ])
            ->filters([
                SelectFilter::make('kind')->options(collect(CoveKind::cases())
                    ->mapWithKeys(fn (CoveKind $k) => [$k->value => $k->label()])->all()),
                // No market filter: the market tab strip on the list page is
                // that control, and two controls on one axis can disagree.
                SelectFilter::make('status')->options(collect(PublishStatus::cases())
                    ->mapWithKeys(fn ($c) => [$c->value => $c->value])->all()),
            ])
            ->recordActions([
                EditAction::make(),

                Action::make('view')
                    ->label('Open')
                    ->icon(Heroicon::OutlinedArrowTopRightOnSquare)
                    ->url(fn (DailyPickSet $record) => static::publicUrl($record))
                    ->openUrlInNewTab(),

                Action::make('curate')
                    ->label('Its plan')
                    ->icon(Heroicon::OutlinedSquares2x2)
                    // Every published Cove has one, including the ones nobody
                    // planned — those carry a record minted by the build.
                    ->visible(fn (DailyPickSet $record) => $record->plan !== null)
                    ->url(fn (DailyPickSet $record) => CovePlanResource::getUrl('curate', ['record' => $record->plan])),

                /*
                 * A link somebody without an admin account can open.
                 *
                 * The person whose opinion you want on the prose — a colleague,
                 * a native speaker checking the Dutch — usually does not have
                 * one, and the alternative has been publishing the piece to find
                 * out whether it reads well.
                 *
                 * Only on a draft: a published Cove is already readable by
                 * everybody, and offering a signed link to it would imply
                 * otherwise.
                 */
                Action::make('preview')
                    ->label('Copy preview link')
                    ->icon(Heroicon::OutlinedEye)
                    ->visible(fn (DailyPickSet $record) => $record->status !== PublishStatus::Published)
                    ->action(function (DailyPickSet $record): void {
                        Notification::make()
                            ->title('Preview link, good for 7 days')
                            ->body(static::previewUrl($record))
                            ->persistent()
                            ->success()
                            ->send();
                    }),

                Action::make('unpublish')
                    ->icon(Heroicon::OutlinedEyeSlash)
                    ->requiresConfirmation()
                    ->visible(fn (DailyPickSet $record) => $record->status === PublishStatus::Published)
                    ->action(function (DailyPickSet $record): void {
                        // Draft rather than delete: an unpublished Cove keeps
                        // its slug, so re-publishing it later does not mint a
                        // second URL for the same page.
                        $record->update(['status' => PublishStatus::Draft->value]);

                        Notification::make()->title('Unpublished')->success()->send();
                    }),

                Action::make('rebuild')
                    ->icon(Heroicon::OutlinedArrowPathRoundedSquare)
                    ->color('gray')
                    ->visible(fn (DailyPickSet $record) => $record->plan !== null)
                    ->requiresConfirmation()
                    ->modalDescription('Refreshes this Cove from its plan. Idempotent — it updates in place rather than making a second one, and the URL does not change.')
                    ->action(function (DailyPickSet $record): void {
                        BuildCove::dispatch($record->plan->id);

                        Notification::make()->title('Rebuild queued')->success()->send();
                    }),

                Action::make('redo')
                    ->icon(Heroicon::OutlinedArrowPath)
                    ->color('danger')
                    ->visible(fn (DailyPickSet $record) => $record->plan !== null)
                    ->requiresConfirmation()
                    ->modalHeading('Redo this Cove?')
                    ->modalDescription('New products and a newly written article, at the same URL. The reader reactions this Cove has collected are deleted and cannot be recovered.')
                    ->schema([
                        Select::make('mode')
                            ->label('What to redo')
                            ->options([
                                'reselect' => 'Everything — reselect the products and rewrite',
                                'rewrite' => 'Only the words — keep the curated shortlist',
                            ])
                            ->default('reselect')
                            ->required(),
                    ])
                    ->action(function (DailyPickSet $record, array $data): void {
                        RedoCove::dispatch($record->plan->id, $data['mode'] === 'reselect');

                        Notification::make()
                            ->title('Redo queued')
                            ->body('The URL does not change. Reload once the worker has finished.')
                            ->success()
                            ->send();
                    }),
            ])
            ->toolbarActions([BulkActionGroup::make([DeleteBulkAction::make()])])
            /*
             * Newest first, and NULLS LAST.
             *
             * Postgres sorts `ORDER BY drop_date DESC` NULLS FIRST, and four of
             * the five kinds are dateless — so the default sort would put every
             * guide and persona above today's edition. Sorting on published_at
             * instead is the honest answer: it is the one column every kind has.
             */
            ->defaultSort('published_at', 'desc');
    }

    /** Where a reader finds this Cove. */
    public static function publicUrl(DailyPickSet $record): string
    {
        return url('/'.$record->market->value.'/'.$record->kind->path(
            $record->kind->isDated() ? $record->drop_date->toDateString() : (string) $record->slug,
            $record->market,
        ));
    }

    private static function previewUrl(DailyPickSet $record): string
    {
        return $record->kind->isArticle()
            ? PreviewAccess::link('guides.show', [
                'market' => $record->market->value,
                'slug' => $record->slug,
            ])
            : static::publicUrl($record);
    }

    public static function getRelations(): array
    {
        return [PicksRelationManager::class];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCoveEditorials::route('/'),
            'edit' => EditCoveEditorial::route('/{record}/edit'),
        ];
    }
}
