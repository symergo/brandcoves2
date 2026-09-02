<?php

declare(strict_types=1);

namespace App\Filament\Resources\CovePlans;

use App\Enums\CoveKind;
use App\Enums\Market;
use App\Enums\PersonaScene;
use App\Enums\PickMode;
use App\Filament\Resources\CovePlans\Pages\CuratePlan;
use App\Filament\Resources\CovePlans\Pages\ListCovePlans;
use App\Jobs\BuildCove;
use App\Jobs\RedoCove;
use App\Models\CovePlan;
use App\Services\Cove\ObservanceCalendar;
use BackedEnum;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Repeater;
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
use Illuminate\Support\Str;
use UnitEnum;

/**
 * The Cove planner: where every kind of page is decided before it is built.
 *
 * Until this existed, a Daily Cove was assembled at 06:00 and published at
 * 09:00, and nobody saw it before the readers did. Fine while the theme was a
 * generated line; useless the moment it is an occasion, because you cannot plan
 * around Mother's Day three hours before it starts.
 *
 * It now plans all five kinds. A buying guide used to be the one page nobody
 * could decide anything about — the builder chose its own products, wrote about
 * them and published, and the only human control was editing the sentences
 * afterwards. Guides, seasonal guides and advice articles are planned here on
 * the same screen as a Daily and a persona, because they are the same editorial
 * decision made at a different cadence.
 *
 * A plan is an *intention*, not an edition. Approving one tells the builder what
 * the page is for; the edition is still what gets published, and a plan whose
 * catalogue turns out too thin simply does not come off. That separation is what
 * stops a scheduled date from guaranteeing an empty page.
 *
 * Every published Cove has a plan, including the ones nobody planned — those
 * carry one minted by the build as a record, so this screen describes the past
 * as well as the future. See `CovePlan::recordFor()`.
 */
class CovePlanResource extends Resource
{
    protected static ?string $model = CovePlan::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCalendar;

    protected static string|UnitEnum|null $navigationGroup = 'Content';

    /*
     * "Calendar" was right while a plan was a date and a title. It now plans
     * five kinds of page, four of which have no date at all — a buying guide is
     * not a day, and looking for it under a calendar is how you conclude the
     * planner does not do guides.
     */
    protected static ?string $navigationLabel = 'Cove planner';

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

                    /*
                     * Five kinds, one table, one screen.
                     *
                     * They are planned together because they are the same
                     * editorial decision — "what is this one about, and which
                     * products is it about" — made at different cadences and
                     * built by one builder. What changes is how the page is
                     * addressed, how its products are chosen, and how many it
                     * needs. All three live on the enum. See App\Enums\CoveKind.
                     */
                    Select::make('kind')
                        ->options(collect(CoveKind::cases())
                            ->mapWithKeys(fn (CoveKind $k) => [$k->value => $k->label()])->all())
                        ->default(CoveKind::Daily->value)
                        ->required()
                        ->live()
                        ->helperText('Only a Daily Cove has a date. Everything else is permanent and lives at a slug.'),

                    DatePicker::make('drop_date')
                        ->label('Date')
                        ->visible(fn ($get) => $get('kind') === CoveKind::Daily->value)
                        ->required(fn ($get) => $get('kind') === CoveKind::Daily->value)
                        ->helperText('One Daily Cove per market per day.')
                        // The unique index covers dated rows only, so two ideas
                        // can sit undated but one Tuesday cannot have two plans.
                        ->unique(ignoreRecord: true, modifyRuleUsing: fn ($rule, $get) => $rule->where('market', $get('market'))),

                    TextInput::make('title')->required()->maxLength(120)->live(onBlur: true),

                    TextInput::make('slug')
                        ->label('Permanent URL')
                        ->visible(fn ($get) => $get('kind') !== CoveKind::Daily->value)
                        ->required(fn ($get) => $get('kind') !== CoveKind::Daily->value)
                        ->maxLength(80)
                        ->rule('alpha_dash')
                        // Suggested from the title, never rewritten from it: a
                        // page that is retitled keeps its address, because the
                        // address is what has been linked and indexed.
                        ->default(fn ($get) => Str::slug((string) $get('title')))
                        /*
                         * One slug namespace per market, across every dateless
                         * kind — a persona and a guide cannot share one even
                         * though they live at different paths. It keeps
                         * `[[guide:slug]]` unambiguous about which page it means.
                         */
                        ->unique(ignoreRecord: true, modifyRuleUsing: fn ($rule, $get) => $rule->where('market', $get('market')))
                        // The market matters now that the Daily segment is
                        // localised: this hint has to show the word the chosen
                        // market actually uses, not a stand-in from another one.
                        ->helperText(fn ($get) => '/{market}/'
                            .(CoveKind::tryFrom((string) $get('kind')) ?? CoveKind::Persona)->path(
                                '{slug}',
                                Market::tryFrom((string) $get('market')) ?? Market::BeNl,
                            )
                            .'. Set once — changing it breaks every link to the page.'),

                    /*
                     * When a seasonal Cove is worth showing.
                     *
                     * MM-DD and year-less, because the window recurs. A window
                     * whose end is before its start wraps the year, which is how
                     * Valentine's opens on 12-27.
                     */
                    TextInput::make('season_from')
                        ->label('Season opens')
                        ->placeholder('09-15')
                        ->visible(fn ($get) => $get('kind') === CoveKind::Seasonal->value)
                        ->rule('regex:/^\d{2}-\d{2}$/')
                        ->helperText('MM-DD. Well before the season: the search log cannot know about a season until it has already started.'),

                    TextInput::make('season_to')
                        ->label('Season closes')
                        ->placeholder('10-31')
                        ->visible(fn ($get) => $get('kind') === CoveKind::Seasonal->value)
                        ->rule('regex:/^\d{2}-\d{2}$/')
                        ->helperText('MM-DD. Earlier than the opening date means the window wraps the year.'),

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
                        // A column's prose. An article's words are the body and
                        // the FAQ below, so this only applies to the two kinds
                        // that are written as one piece about a set.
                        ->visible(fn ($get) => ! (CoveKind::tryFrom((string) $get('kind'))?->isArticle() ?? false))
                        ->maxLength((int) config('giftcoves.editorial_api.max_editorial_chars'))
                        ->helperText('Two or three paragraphs, blank line between them. Link with tokens, never URLs: [[product:1234|label]], [[brand:Sony]], [[search:phrase]] — anything outside the products on this Cove is rendered as plain text. Written here, it replaces the AI pass entirely and survives every rebuild.'),
                ])
                ->columns(2),

            /*
             * The parts of an article a person can decide before it is written.
             *
             * This is what a guide never had. Its keyphrase, its FAQ and its
             * "how to choose" were all invented at build time by the same class
             * that chose the products, so the only way to have an opinion about
             * them was to edit the published page afterwards.
             *
             * Every field here is optional: left empty, the builder writes it.
             * Filled, it survives every rebuild — that is the whole contract.
             */
            Section::make('The article')
                ->visible(fn ($get) => CoveKind::tryFrom((string) $get('kind'))?->isArticle() ?? false)
                ->schema([
                    TextInput::make('focus_keyphrase')
                        ->label('Focus keyphrase')
                        ->maxLength(120)
                        ->helperText('The phrase this page is written to answer, and what its products are searched for with. The title is a headline and may be nothing anyone types.'),

                    TextInput::make('meta_description')
                        ->label('Meta description')
                        ->maxLength(160)
                        ->helperText('Left empty, it is trimmed from the blurb.'),

                    Textarea::make('body')
                        ->label('How to choose')
                        ->rows(6)
                        ->columnSpanFull()
                        // Rendered as plain paragraphs, never as Markdown: the
                        // copy comes from a language model, and the one thing
                        // you never do with model output is hand it to
                        // something that interprets markup.
                        ->helperText('Plain text; blank lines separate paragraphs. Link tokens work here too. Written here with a blurb, it replaces the AI pass entirely.'),

                    Repeater::make('faq')
                        ->label('FAQ')
                        ->columnSpanFull()
                        ->schema([
                            TextInput::make('q')->label('Question')->required()->maxLength(200),
                            Textarea::make('a')->label('Answer')->required()->rows(2)->maxLength(600),
                        ])
                        ->columns(2)
                        ->defaultItems(0)
                        ->addActionLabel('Add a question')
                        // Both halves or neither: a half-empty pair renders as a
                        // broken FAQPage and Google will say so.
                        ->helperText('Rendered as structured data. A question with no answer is dropped rather than published half-formed.'),
                ])
                ->columns(2),

            Section::make('What to show')
                ->schema([
                    TagsInput::make('queries')
                        ->label('Steer the finds toward')
                        ->helperText('Product words, not themes: "hondenmand" finds products, "cadeau voor hondenliefhebbers" finds nothing. A bias, never a filter — a thin day still publishes.'),

                    /*
                     * How much the engine may add to what a person chose.
                     *
                     * The products themselves are chosen on the curation screen
                     * rather than here. This used to be a multi-select against
                     * `product_groups` doing an ILIKE on the title: it reached
                     * only what had already been ingested, showed nothing but a
                     * title, could not be ordered, and had nowhere to say why a
                     * product was on the list. See CuratePlan.
                     */
                    /*
                     * Direction for the writer, not prose for the reader.
                     *
                     * Lives beside the pick mode because the two are the same
                     * kind of decision — how this build should behave — and
                     * both are edited far more often from the curation screen,
                     * where the products are in front of you.
                     */
                    Textarea::make('build_instructions')
                        ->label('Instructions for the build')
                        ->rows(3)
                        ->maxLength(1000)
                        ->columnSpanFull()
                        ->helperText('How the article should be written: "keep it short", "lean on the nostalgia, not the tech". Ignored when this plan carries its own editorial, because that skips the model entirely.'),

                    Select::make('pick_mode')
                        ->label('What the engine may add')
                        ->options(collect(PickMode::cases())
                            ->mapWithKeys(fn (PickMode $m) => [$m->value => $m->label()])->all())
                        ->default(PickMode::Open->value)
                        ->required()
                        ->helperText('Locked publishes exactly the curated list, in order. Open lets the ranker fill the rest of the page around it.'),

                    /*
                     * The drawing, and only where there is one to choose.
                     *
                     * A persona's cover used to be its first buyable product's
                     * photograph, which moved whenever stock did. This is the
                     * editorial decision that replaced it, so it belongs beside
                     * the other authored fields rather than in the curation
                     * screen — it is about who the Cove is for, not about which
                     * products ended up on it.
                     *
                     * Hidden on every other kind. Nothing reads a scene on a
                     * Daily or a guide, and an always-visible select for a field
                     * with no effect is a question the form cannot answer.
                     */
                    Select::make('scene')
                        ->label('Drawing')
                        ->options(PersonaScene::options())
                        ->visible(fn ($get) => $get('kind') === CoveKind::Persona->value)
                        ->placeholder('A figure, until you choose')
                        ->helperText('The illustration on the shelf, the hub and the persona\'s own page. Pick the interest, not the person.'),

                    Placeholder::make('curated')
                        ->label('Curated products')
                        ->content(fn (?CovePlan $record) => $record === null
                            ? 'Save the plan first, then curate its products.'
                            : $record->items()->count().' chosen — use the Curate button on the calendar row.'),
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
                /*
                 * Four of the five kinds have no date, so a date column alone
                 * would leave most rows blank and nothing identifying them. The
                 * slug fills in where the date is null — it is what addresses
                 * the page, and what an editor searches for.
                 */
                TextColumn::make('drop_date')
                    ->label('When / where')
                    ->date()
                    ->sortable()
                    ->placeholder(fn (CovePlan $r) => $r->slug === null ? '— unaddressed' : '/'.$r->slug)
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

                TextColumn::make('kind')
                    ->badge()
                    ->sortable()
                    ->color(fn (CoveKind $state) => $state === CoveKind::Persona ? 'info' : 'gray')
                    ->formatStateUsing(fn (CoveKind $state) => $state->label()),

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

                TextColumn::make('curated')
                    ->label('Curated')
                    ->state(fn (CovePlan $r) => $r->items()->count() ?: '—')
                    // Locked and short is the one state worth colouring: it is
                    // the plan that will not publish at all, and nothing else
                    // on this row says so.
                    ->color(fn (CovePlan $r) => $r->isBuildable() ? null : 'danger'),

                TextColumn::make('pick_mode')
                    ->label('Engine')
                    ->badge()
                    ->color(fn (PickMode $state) => $state === PickMode::Locked ? 'info' : 'gray')
                    ->formatStateUsing(fn (PickMode $state) => $state->value)
                    ->toggleable(),

                TextColumn::make('edition.id')->label('Edition')->placeholder('—'),
                TextColumn::make('author.email')->label('By')->toggleable(),
            ])
            ->filters([
                SelectFilter::make('kind')->options(collect(CoveKind::cases())
                    ->mapWithKeys(fn (CoveKind $k) => [$k->value => $k->label()])->all()),
                // No market filter: the market tab strip on the list page is
                // that control, and two controls on one axis can disagree.
                SelectFilter::make('status')->options([
                    'draft' => 'draft',
                    'approved' => 'approved',
                    'used' => 'used',
                    'rejected' => 'rejected',
                ]),
            ])
            ->headerActions([CreateAction::make()])
            ->recordActions([
                /*
                 * Curate first, edit second.
                 *
                 * Choosing the products is the work; the title, the blurb and
                 * the approval are the paperwork around it. The old order —
                 * edit, approve, build — was right when the machine chose the
                 * products and a person only named the day.
                 */
                Action::make('curate')
                    ->label('Curate')
                    ->icon(Heroicon::OutlinedSquares2x2)
                    ->color('primary')
                    ->url(fn (CovePlan $record) => CovePlanResource::getUrl('curate', ['record' => $record])),

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
                    ->visible(fn (CovePlan $r) => $r->status === 'approved' && ($r->drop_date !== null || ! $r->kind->isDated()))
                    ->requiresConfirmation()
                    ->modalDescription('Builds this Cove immediately so you can see it before anyone else. Rebuilding is idempotent — it updates in place rather than creating a second one.')
                    ->action(function (CovePlan $record): void {
                        /*
                         * One job, whatever the kind. The branch this replaced
                         * lived in four places and had to grow a third arm every
                         * time a kind was added — see App\Jobs\BuildCove.
                         */
                        BuildCove::dispatch($record->id);

                        Notification::make()
                            ->title('Build queued')
                            ->body('Watch Horizon, then open the Cove.')
                            ->success()
                            ->send();
                    }),

                /*
                 * Redo is not rebuild, and the modal has to say so.
                 *
                 * Rebuild reproduces the page; redo replaces it. They sit next
                 * to each other because that is where you look for both, which
                 * makes the wording of this one load-bearing.
                 */
                Action::make('redo')
                    ->label('Redo')
                    ->icon(Heroicon::OutlinedArrowPath)
                    ->color('danger')
                    ->visible(fn (CovePlan $r) => $r->edition_id !== null)
                    ->requiresConfirmation()
                    ->modalHeading('Redo this Cove?')
                    ->modalDescription('New products and a newly written article, at the same URL. The reader reactions this Cove has collected are deleted and cannot be recovered. Anything already written on the plan is discarded.')
                    ->schema([
                        Select::make('mode')
                            ->label('What to redo')
                            ->options([
                                'reselect' => 'Everything — reselect the products and rewrite',
                                'rewrite' => 'Only the words — keep my curated shortlist',
                            ])
                            ->default('reselect')
                            ->required(),
                    ])
                    ->action(function (CovePlan $record, array $data): void {
                        RedoCove::dispatch($record->id, $data['mode'] === 'reselect');

                        Notification::make()
                            ->title('Redo queued')
                            ->body('The URL does not change. Reload the Cove once the worker has finished.')
                            ->success()
                            ->send();
                    }),
            ])
            ->toolbarActions([BulkActionGroup::make([DeleteBulkAction::make()])])
            // Queued ideas last, upcoming dates first: the calendar is a
            // forward-looking document.
            // The row goes where the work is. Curating is what an editor opens
            // this table to do; editing the title is the occasional errand, and
            // it keeps its own button.
            ->recordUrl(fn (CovePlan $record) => CovePlanResource::getUrl('curate', ['record' => $record]))
            ->defaultSort('drop_date');
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCovePlans::route('/'),
            'curate' => CuratePlan::route('/{record}/curate'),
        ];
    }
}
