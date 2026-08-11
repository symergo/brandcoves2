<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Enums\Market;
use App\Filament\Resources\CopyTemplates\CopyTemplateResource;
use App\Models\CopyTemplate;
use App\Services\Seo\CopyBank;
use App\Services\Seo\CopySlots;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use UnitEnum;

/**
 * Edit a whole page's copy on one screen.
 *
 * ## Why this replaced the table
 *
 * The first version was a resource: one row per variant, 192 of them, edited one
 * at a time in a modal. Every concept in it was ours rather than the editor's —
 * pick a *surface*, pick a *slot*, set a *weight* — and none of it told you where
 * the sentence you were editing actually sat. Writing the third paragraph of the
 * "Prices" section meant finding row 74 of a flat list and trusting the label.
 *
 * This is the same data shaped like the thing it produces. One screen per page
 * per language, sections in the order they are read, every variant of a sentence
 * stacked underneath it, and one Save. Adding an alternative is a button next to
 * the sentence it is an alternative *to*, which is the only place anyone would
 * look for it.
 *
 * The table still exists at `CopyTemplateResource` for the things a form is bad
 * at — searching all languages at once, spotting an orphaned slot, bulk delete —
 * and is linked from the header rather than the navigation, because it is the
 * second thing you want and not the first.
 */
class EditPageCopy extends Page implements HasForms
{
    use InteractsWithForms;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPencilSquare;

    protected static string|UnitEnum|null $navigationGroup = 'Content';

    protected static ?string $navigationLabel = 'Page copy';

    protected static ?string $title = 'Page copy';

    protected string $view = 'filament.pages.edit-page-copy';

    /** @var array<string, mixed> */
    public array $data = [];

    /**
     * The surface the page opens on.
     *
     * `brand_intro` until it was retired — it was the default because it was the
     * copy that rendered on the most pages. `search` inherits that for the same
     * reason: every result page in every market draws from it.
     */
    public string $surface = 'search';

    public string $language = 'nl';

    public function mount(): void
    {
        $this->loadCopy();
    }

    /**
     * Read the current variants into form state.
     *
     * Every slot in the registry gets an entry even when the database has none,
     * so the editor sees the whole page including the sentences nobody has
     * customised — with the shipped line shown as the placeholder underneath.
     * A form that only listed edited slots would hide most of the page.
     */
    public function loadCopy(): void
    {
        $rows = CopyTemplate::query()
            ->where('surface', $this->surface)
            ->where('language', $this->language)
            ->orderByDesc('weight')
            ->orderBy('id')
            ->get();

        $slots = [];

        foreach (CopySlots::forSurface($this->surface) as $definition) {
            $slots[$definition['slot']] = $rows
                ->where('slot', $definition['slot'])
                ->map(fn (CopyTemplate $t) => [
                    // Carried through the form so a save can tell an edit from a
                    // new variant, rather than deleting and recreating every row
                    // on every save and losing the timestamps.
                    'id' => $t->id,
                    'body' => $t->body,
                    'weight' => $t->weight,
                    'enabled' => $t->enabled,
                ])
                ->values()
                ->all();
        }

        $this->form->fill(['slots' => $slots]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make()
                    ->schema([
                        Select::make('surface')
                            ->label('Page')
                            ->options(collect(CopySlots::surfaces())->map(fn (array $s) => $s['label'])->all())
                            ->live()
                            ->afterStateUpdated(fn () => $this->loadCopy())
                            ->selectablePlaceholder(false),

                        Select::make('language')
                            ->options(collect(Market::cases())
                                ->mapWithKeys(fn (Market $m) => [$m->language() => $m->language().' — '.$this->languageName($m->language())])
                                ->all())
                            ->live()
                            ->afterStateUpdated(fn () => $this->loadCopy())
                            ->selectablePlaceholder(false)
                            ->helperText('Language, not market — be-nl and nl-nl share every word here.'),
                    ])
                    ->columns(2)
                    // Bound to the component directly rather than to $data, so
                    // switching either one reloads without going through a save.
                    ->statePath(''),

                ...$this->slotSections(),
            ])
            ->statePath('data');
    }

    /**
     * One tab per section of the page, in reading order.
     *
     * @return list<Tabs>
     */
    private function slotSections(): array
    {
        $groups = [];

        foreach (CopySlots::forSurface($this->surface) as $definition) {
            $groups[$definition['group']][] = $definition;
        }

        $tabs = [];

        foreach ($groups as $group => $definitions) {
            $tabs[] = Tabs\Tab::make($group)
                ->badge(count($definitions))
                ->schema(array_map($this->slotField(...), $definitions));
        }

        return [Tabs::make('sections')->tabs($tabs)->persistTabInQueryString()];
    }

    /**
     * One sentence, with every variant of it stacked underneath.
     *
     * @param  array{slot: string, label: string, guard: string, placeholders: list<string>}  $definition
     */
    private function slotField(array $definition): Repeater
    {
        $shipped = $this->shippedLine($definition['slot']);

        return Repeater::make("slots.{$definition['slot']}")
            ->label($definition['label'])
            /*
             * Three things an editor cannot work out from the sentence itself:
             * when it appears, what it may contain, and what the site says today
             * if they write nothing. All three on one line, because a help text
             * nobody reads is worse than none.
             */
            ->helperText(
                'Shown: '.$definition['guard']
                .' · Placeholders: '.implode(' ', array_map(fn (string $p) => ':'.$p, $definition['placeholders']))
            )
            ->schema([
                Textarea::make('body')
                    ->hiddenLabel()
                    ->required()
                    ->rows(3)
                    ->placeholder($shipped)
                    /*
                     * Wrapped in an outer closure that takes nothing.
                     *
                     * Filament resolves a rule closure by parameter injection,
                     * so a bare `function ($attribute, $value, $fail)` fails with
                     * "[$attribute] was unresolvable". The outer one is evaluated
                     * by Filament and returns the Laravel closure rule untouched.
                     */
                    ->rules([
                        fn (): \Closure => function (string $attribute, mixed $value, callable $fail) use ($definition): void {
                            $bad = CopySlots::disallowedIn($this->surface, $definition['slot'], (string) $value);

                            if ($bad !== []) {
                                $fail('This sentence does not have: :'.implode(', :', $bad)
                                    .'. It would print as text, or claim a number this page cannot back up.');
                            }
                        },
                    ]),

                TextInput::make('weight')
                    ->label('Shown this often')
                    ->numeric()
                    ->minValue(0)
                    ->maxValue(100)
                    ->default(1)
                    ->required()
                    ->helperText('Relative to the others here. 3 appears three times as often as 1.'),

                Toggle::make('enabled')->label('In use')->default(true)->inline(false),
            ])
            ->columns(['default' => 1, 'lg' => 4])
            ->itemLabel(fn (array $state): ?string => Str::limit((string) ($state['body'] ?? ''), 70))
            ->addActionLabel('Add another way of saying this')
            ->collapsed()
            ->collapsible()
            ->cloneable()
            ->reorderable(false)
            ->defaultItems(0)
            // The empty state is the honest one: no variants means the site uses
            // the line below, and saying so stops anyone thinking the page is
            // blank because something is broken.
            ->hint($shipped === '' ? null : 'Currently: '.Str::limit($shipped, 90));
    }

    /**
     * Save the whole page in one transaction.
     *
     * Diffed rather than truncate-and-rewrite: a row that only changed weight
     * should keep its id, its author and its created_at. Rewriting the table on
     * every save would also mean a validation failure halfway through leaving an
     * editor with half a page.
     */
    public function save(): void
    {
        $state = $this->form->getState();
        $submitted = $state['slots'] ?? [];

        $kept = [];
        $written = 0;

        DB::transaction(function () use ($submitted, &$kept, &$written): void {
            foreach ($submitted as $slot => $variants) {
                foreach ($variants as $variant) {
                    $body = trim((string) ($variant['body'] ?? ''));

                    if ($body === '') {
                        continue;
                    }

                    $attributes = [
                        'surface' => $this->surface,
                        'slot' => $slot,
                        'language' => $this->language,
                        'body' => $body,
                        'weight' => (int) ($variant['weight'] ?? 1),
                        'enabled' => (bool) ($variant['enabled'] ?? true),
                        'author_id' => auth()->id(),
                    ];

                    $row = isset($variant['id'])
                        ? CopyTemplate::query()->find($variant['id'])
                        : null;

                    if ($row === null) {
                        $row = CopyTemplate::create($attributes);
                    } else {
                        $row->update($attributes);
                    }

                    $kept[] = $row->id;
                    $written++;
                }
            }

            // Anything for this page and language that the form no longer holds
            // was removed by the editor. Scoped to the surface and language on
            // screen so a save here can never touch another page's copy.
            CopyTemplate::query()
                ->where('surface', $this->surface)
                ->where('language', $this->language)
                ->whereNotIn('id', $kept ?: [0])
                ->delete();
        });

        CopyBank::flush();

        // Reload so newly created rows carry their ids, or the next save would
        // treat them as new again and churn the table.
        $this->loadCopy();

        Notification::make()
            ->title('Saved')
            ->body("{$written} variant(s) live on the next page load.")
            ->success()
            ->send();
    }

    /**
     * What the site renders today with no variant — the fallback line.
     *
     * Shown as the textarea placeholder, so an empty slot is self-explanatory
     * rather than looking like missing data.
     */
    private function shippedLine(string $slot): string
    {
        $namespace = CopySlots::namespaceFor($this->surface);
        $line = __("{$namespace}.{$slot}", [], $this->language);

        return is_string($line) && ! str_contains($line, (string) $namespace) ? $line : '';
    }

    private function languageName(string $language): string
    {
        return match ($language) {
            'nl' => 'Nederlands',
            'fr' => 'Français',
            'es' => 'Español',
            default => 'English',
        };
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('save')
                ->label('Save')
                ->keyBindings(['mod+s'])
                ->action('save'),

            Action::make('seed')
                ->label('Import shipped copy')
                ->icon(Heroicon::OutlinedArrowDownTray)
                ->color('gray')
                ->requiresConfirmation()
                ->modalDescription('Adds the sentences the site currently renders as a first variant of each slot. Slots that already have one are left completely alone, so this cannot overwrite your work.')
                ->action(function (): void {
                    Artisan::call('bc:seed-copy');
                    CopyBank::flush();
                    $this->loadCopy();

                    Notification::make()->title('Imported')->body(trim(Artisan::output()))->success()->send();
                }),

            Action::make('advanced')
                ->label('All variants')
                ->icon(Heroicon::OutlinedTableCells)
                ->color('gray')
                ->url(fn () => CopyTemplateResource::getUrl()),
        ];
    }
}
