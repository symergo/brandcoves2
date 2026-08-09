<?php

declare(strict_types=1);

namespace App\Filament\Resources\CopyTemplates;

use App\Enums\Market;
use App\Filament\Resources\CopyTemplates\Pages\ListCopyTemplates;
use App\Models\CopyTemplate;
use App\Services\Seo\CopyBank;
use App\Services\Seo\CopySlots;
use BackedEnum;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ReplicateAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use UnitEnum;

/**
 * The editable copy on search and brand pages.
 *
 * ## What an editor is actually doing here
 *
 * Not writing a page — writing one **variant** of one **slot**. A slot is a
 * position in the page's argument ("the second sentence about comparing"), and
 * every page that reaches that position draws one of its variants. Add a fifth
 * opening line for brand pages and a fifth of them start using it, immediately,
 * with no deploy.
 *
 * ## The two rails
 *
 * **Placeholders are validated against the slot.** A typo'd `:cont` renders
 * literally to a reader, and a placeholder the slot does not supply is worse: put
 * `:percent` in a sentence that renders even when nothing is discounted and the
 * page asserts a 0% saving. `CopySlots` declares what each slot may contain and
 * the save is refused otherwise.
 *
 * **Nothing here is required.** Delete every row and the site renders the copy it
 * shipped with, from the language files. That is what makes this safe to hand
 * over: the worst an editor can do is make a page ordinary again.
 */
class CopyTemplateResource extends Resource
{
    protected static ?string $model = CopyTemplate::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPencilSquare;

    protected static string|UnitEnum|null $navigationGroup = 'Content';

    protected static ?string $navigationLabel = 'Page copy';

    protected static ?string $modelLabel = 'copy variant';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Where it appears')
                ->schema([
                    Select::make('surface')
                        ->options(collect(CopySlots::surfaces())
                            ->map(fn (array $s) => $s['label'])->all())
                        ->required()
                        ->live()
                        // Changing surface invalidates the slot: the two lists do
                        // not overlap, and a stale slot would save a row nothing
                        // ever reads.
                        ->afterStateUpdated(fn ($set) => $set('slot', null)),

                    Select::make('slot')
                        ->options(fn (Get $get) => collect(CopySlots::forSurface((string) $get('surface')))
                            ->mapWithKeys(fn (array $s) => [$s['slot'] => $s['label']])->all())
                        ->required()
                        ->live()
                        ->helperText(function (Get $get): ?string {
                            $definition = CopySlots::find((string) $get('surface'), (string) $get('slot'));

                            // When the sentence renders. An editor cannot infer
                            // this from the sentence, and it is the difference
                            // between a safe line and a false claim.
                            return $definition === null ? null : 'Shown: '.$definition['guard'];
                        }),

                    Select::make('language')
                        ->options(collect(Market::cases())
                            ->mapWithKeys(fn (Market $m) => [$m->language() => strtoupper($m->language())])
                            ->all())
                        ->required()
                        ->helperText('Language, not market — be-nl and nl-nl share every word on these pages.'),
                ])
                ->columns(3),

            Section::make('The line')
                ->schema([
                    Textarea::make('body')
                        ->required()
                        ->rows(4)
                        ->live(onBlur: true)
                        ->helperText(function (Get $get): string {
                            $definition = CopySlots::find((string) $get('surface'), (string) $get('slot'));

                            if ($definition === null) {
                                return 'Choose a slot to see which placeholders it supplies.';
                            }

                            return 'Placeholders: '.implode(' ', array_map(
                                fn (string $p) => ':'.$p,
                                $definition['placeholders'],
                            ));
                        })
                        /*
                         * The rail that matters.
                         *
                         * Validated on save rather than merely hinted, because
                         * the failure is silent: a wrong placeholder renders as
                         * literal text on thousands of pages and nothing throws.
                         */
                        ->rules([
                            fn (Get $get) => function (string $attribute, mixed $value, callable $fail) use ($get): void {
                                $bad = CopySlots::disallowedIn(
                                    (string) $get('surface'),
                                    (string) $get('slot'),
                                    (string) $value,
                                );

                                if ($bad !== []) {
                                    $fail('This slot does not supply: :'.implode(', :', $bad)
                                        .'. It would render as literal text, or state a number the page cannot back up.');
                                }
                            },
                        ]),

                    // Live, so an editor sees the shape of the sentence with
                    // numbers in it rather than a template with colons.
                    Textarea::make('preview')
                        ->label('Preview, with sample values')
                        ->rows(3)
                        ->disabled()
                        ->dehydrated(false)
                        ->afterStateHydrated(fn ($component, Get $get) => $component->state(
                            app(CopyBank::class)->preview((string) $get('body'), self::sample()),
                        ))
                        ->formatStateUsing(fn (Get $get) => app(CopyBank::class)
                            ->preview((string) $get('body'), self::sample())),
                ]),

            Section::make('Rotation')
                ->schema([
                    TextInput::make('weight')
                        ->numeric()
                        ->minValue(0)
                        ->maxValue(100)
                        ->default(1)
                        ->required()
                        ->helperText('Relative, not a percentage — weight 3 appears three times as often as weight 1. Set 0 to retire a line without deleting it.'),

                    Toggle::make('enabled')->default(true),

                    Textarea::make('note')
                        ->rows(2)
                        ->label('Note')
                        ->helperText('Why this variant exists, for whoever inherits it.'),
                ])
                ->columns(2),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('surface')
                    ->badge()
                    ->formatStateUsing(fn (string $state) => CopySlots::surfaces()[$state]['label'] ?? $state)
                    ->sortable(),

                TextColumn::make('slot')
                    ->description(fn (CopyTemplate $r) => $r->slotLabel())
                    ->searchable()
                    ->sortable(),

                TextColumn::make('language')->badge()->sortable(),

                TextColumn::make('body')->limit(80)->searchable()->wrap(),

                TextColumn::make('weight')->numeric()->sortable(),

                IconColumn::make('enabled')->boolean()->sortable(),

                /*
                 * Two health signals, because both are silent failures.
                 *
                 * A row whose slot no longer exists is copy nobody will ever see;
                 * a row with a bad placeholder is copy that renders wrong. Neither
                 * throws, so neither is visible anywhere but here.
                 */
                TextColumn::make('health')
                    ->label('')
                    ->state(function (CopyTemplate $r): string {
                        if ($r->isOrphaned()) {
                            return 'orphaned slot';
                        }

                        $bad = $r->disallowedPlaceholders();

                        return $bad === [] ? '' : 'bad placeholder: :'.implode(', :', $bad);
                    })
                    ->badge()
                    ->color('danger'),
            ])
            ->filters([
                SelectFilter::make('surface')
                    ->options(collect(CopySlots::surfaces())->map(fn (array $s) => $s['label'])->all()),

                SelectFilter::make('language')
                    ->options(collect(Market::cases())
                        ->mapWithKeys(fn (Market $m) => [$m->language() => strtoupper($m->language())])
                        ->all()),

                /*
                 * Rows the code will never read.
                 *
                 * Expressible in SQL because it is a set difference against the
                 * registry. The other health signal — a disallowed placeholder —
                 * is not, so it stays a column rather than becoming a filter that
                 * silently returns everything.
                 */
                Filter::make('orphaned')
                    ->label('Slot no longer exists')
                    ->toggle()
                    ->query(fn ($query) => $query->whereNotIn(
                        'slot',
                        array_values(array_unique(array_column(CopySlots::all(), 'slot'))),
                    )),

                Filter::make('single_variant')
                    ->label('Slots with only one variant')
                    ->toggle()
                    ->query(function ($query) {
                        // The slots where rotation is doing nothing — the useful
                        // worklist for someone adding variety.
                        return $query->whereIn('slot', function ($sub) {
                            $sub->from('copy_templates')
                                ->selectRaw('slot')
                                ->where('enabled', true)
                                ->groupBy('surface', 'slot', 'language')
                                ->havingRaw('count(*) = 1');
                        });
                    }),
            ])
            ->headerActions([CreateAction::make()])
            ->recordActions([
                EditAction::make(),

                // The fastest way to add a variant: copy a line that works and
                // change half of it.
                ReplicateAction::make()
                    ->label('New variant')
                    ->icon(Heroicon::OutlinedDocumentDuplicate)
                    ->beforeReplicaSaved(fn (CopyTemplate $replica) => $replica->fill([
                        'note' => 'Copied — rewrite before enabling.',
                        'enabled' => false,
                    ]))
                    ->successNotificationTitle('Variant created, disabled until you enable it'),
            ])
            ->toolbarActions([BulkActionGroup::make([DeleteBulkAction::make()])])
            ->defaultSort('surface')
            ->defaultGroup('slot');
    }

    /**
     * Plausible values for the preview.
     *
     * Deliberately unround: "2,931" and "€ 99,00" show an editor how a real
     * sentence reads, where "10" and "€ 1,00" quietly hide a line that only
     * scans well with small numbers in it.
     *
     * @return array<string, string|int>
     */
    private static function sample(): array
    {
        return [
            'term' => 'koptelefoon',
            'brand' => 'Samsung',
            'shop' => 'Coolblue BE',
            'category' => 'Monitors',
            'count' => '2.931',
            'shown' => 24,
            'shops' => 61,
            'comparable' => 14,
            'reduced' => 137,
            'percent' => 31,
            'low' => '€ 19,99',
            'high' => '€ 1.299,00',
            'brands' => 'Sony, Philips, JBL',
            'terms' => 'draadloos, over-ear, ruisonderdrukking',
        ];
    }

    public static function getPages(): array
    {
        return ['index' => ListCopyTemplates::route('/')];
    }
}
