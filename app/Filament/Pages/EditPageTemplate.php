<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Enums\Market;
use App\Models\PageBlock;
use App\Services\Pages\PageCopy;
use App\Services\Pages\Placeholders\Level;
use App\Services\Pages\Placeholders\PlaceholderFunction;
use App\Services\Pages\Placeholders\PlaceholderRegistry;
use App\Services\Pages\Placeholders\Value;
use App\Services\Pages\Regions\Condition;
use App\Services\Pages\Regions\Region;
use App\Services\Pages\Regions\RegionRegistry;
use BackedEnum;
use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\CheckboxList;
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
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use UnitEnum;

/**
 * Edit a page's template: what it says, where, and when.
 *
 * ## What an editor is doing here
 *
 * Arranging a **region** — a place on a page — as an ordered list of **blocks**.
 * A block is a heading or a paragraph, it can be said several ways, and it can
 * carry conditions that decide which pages it appears on. That is the whole
 * vocabulary; there is nothing underneath it and nothing hardcoded behind it.
 *
 * This replaced a screen that edited ~35 fixed positions declared in PHP. The
 * positions were the problem: their order, their guards and the fact that there
 * were exactly three sections below a results grid were all a deploy. Here the
 * only thing code still owns is *where a region is*, because only code knows
 * where in the markup a paragraph can go and which facts that spot can supply.
 *
 * ## Three axes, and they are scoped differently on purpose
 *
 * **Page** and **region** come from the registry. **Language** is the third, and
 * a block belongs to one: nl, fr, en and es each have their own ordered list.
 * The alternative — one shared list with four bodies per block — forces
 * translation parity of structure, which nobody asked for and which under a
 * no-fallback rule produces silent holes: twelve positions, five empty
 * textareas, a French page quietly missing a third of its copy. Here the screen
 * says "0 blocks" and the guardrail test says it louder.
 *
 * "Copy this region from another language" is what makes that livable, and it is
 * the two-click fix when CI reports a language is empty.
 *
 * ## There is no fallback, and that is the point
 *
 * An empty region renders nothing. No shipped sentence underneath, no language
 * file. What stands in for that safety is `PageRegionsTest`, which fails the
 * build if a region marked as required is empty in any language.
 */
class EditPageTemplate extends Page implements HasForms
{
    use InteractsWithForms;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static string|UnitEnum|null $navigationGroup = 'Content';

    protected static ?string $navigationLabel = 'Page templates';

    protected static ?string $title = 'Page templates';

    protected string $view = 'filament.pages.edit-page-template';

    /** @var array<string, mixed> */
    public array $data = [];

    /**
     * The page the screen opens on.
     *
     * Search, because every results page in every market draws from it and it is
     * the region with the most words in it.
     *
     * `$pageKey` rather than `$page`: this class extends a Filament `Page` and
     * lives in a Livewire component, and `$page` is spoken for in that
     * neighbourhood — `WithPagination` owns it. A property whose name collides
     * with a trait's does not error, it quietly stops behaving.
     */
    public string $pageKey = 'search';

    public string $region = 'below_grid';

    public string $language = 'nl';

    public function mount(): void
    {
        $this->load();
    }

    /*
     * ------------------------------------------------------------------
     * The three selects at the top.
     * ------------------------------------------------------------------
     *
     * They live **inside** the form state, at `data.pageKey` and friends, and the
     * reload hangs off Filament's `afterStateUpdated`.
     *
     * The obvious-looking alternative is what this screen shipped with and it was
     * broken: put them in a `Section(...)->statePath('')` and bind them to public
     * properties, so a Livewire `updatedRegion()` hook can reload. `statePath('')`
     * does **not** reset a child to the root — it contributes nothing to the path,
     * so the children inherit the form's own `data` and the select renders as
     * `wire:model="data.region"`. The property is never touched, the hook never
     * fires, and picking a region in the browser changes the label and nothing
     * else.
     *
     * It passed a Livewire test the whole time, because a test that says
     * `set('region', …)` writes the property directly — the one path the browser
     * never takes. `PageTemplateAdminTest` now drives `data.region`, which is what
     * a person clicking the select actually does, and asserts the binding path
     * itself so this cannot come back quietly.
     */

    /** Sync the selection out of form state and reload. */
    private function selected(string $key, mixed $value): void
    {
        $this->{$key} = (string) $value;

        if ($key === 'pageKey') {
            // A region belongs to a page, so switching page invalidates it.
            // Landing on the first region of the new page beats showing an empty
            // screen with no explanation of why.
            $this->region = RegionRegistry::forPage($this->pageKey)[0]->key ?? '';
        }

        $this->load();
    }

    /**
     * Read this region's blocks into form state.
     *
     * Ordered by `position`, which is renumbered from 1 on every save — so the
     * order on screen is the order on the page, with no gaps to reason about.
     */
    public function load(): void
    {
        $blocks = PageBlock::query()
            ->where('page', $this->pageKey)
            ->where('region', $this->region)
            ->where('language', $this->language)
            ->with(['variants' => fn ($q) => $q->orderByDesc('weight')->orderBy('id')])
            ->orderBy('position')
            ->orderBy('id')
            ->get()
            ->map(fn (PageBlock $block) => [
                // Carried through so a save can tell an edit from an addition,
                // rather than deleting and recreating every row on every save
                // and losing the authorship and the timestamps.
                'id' => $block->id,
                'kind' => $block->kind,
                'enabled' => $block->enabled,
                'conditions' => $block->conditions ?? [],
                'note' => $block->note,
                'variants' => $block->variants
                    ->map(fn ($variant) => [
                        'id' => $variant->id,
                        'body' => $variant->body,
                        'weight' => $variant->weight,
                        'enabled' => $variant->enabled,
                    ])
                    ->values()
                    ->all(),
            ])
            ->values()
            ->all();

        // The three selects are part of this state, so filling has to carry them
        // or picking a region would blank the control that picked it.
        $this->form->fill([
            'pageKey' => $this->pageKey,
            'region' => $this->region,
            'language' => $this->language,
            'blocks' => $blocks,
        ]);
    }

    public function region(): ?Region
    {
        return RegionRegistry::find($this->pageKey, $this->region);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make()
                    ->schema([
                        Select::make('pageKey')
                            ->label('Page')
                            ->options(collect(RegionRegistry::pages())
                                ->mapWithKeys(fn (string $p) => [$p => Str::headline($p)])
                                ->all())
                            ->live()
                            ->afterStateUpdated(fn ($state) => $this->selected('pageKey', $state))
                            ->selectablePlaceholder(false),

                        Select::make('region')
                            ->label('Where on the page')
                            ->options(fn () => collect(RegionRegistry::forPage($this->pageKey))
                                ->mapWithKeys(fn (Region $r) => [$r->key => $r->label])
                                ->all())
                            ->live()
                            ->afterStateUpdated(fn ($state) => $this->selected('region', $state))
                            ->selectablePlaceholder(false)
                            ->helperText(fn (): ?string => $this->region()?->blurb),

                        Select::make('language')
                            ->options(collect(Market::languages())
                                ->mapWithKeys(fn (string $l) => [$l => $l.' — '.$this->languageName($l)])
                                ->all())
                            ->live()
                            ->afterStateUpdated(fn ($state) => $this->selected('language', $state))
                            ->selectablePlaceholder(false)
                            ->helperText('Language, not market — be-nl and nl-nl share every word here.'),
                    ])
                    ->columns(3),

                $this->blockList(),
            ])
            ->statePath('data');
    }

    /**
     * The blocks, in reading order.
     *
     * A repeater rather than one Section per block, because the list is
     * homogeneous in shape even though the kinds differ — and because a repeater
     * gives the move actions somewhere to live without inventing a layout.
     */
    private function blockList(): Repeater
    {
        return Repeater::make('blocks')
            ->label(fn (): string => $this->region()?->label ?? 'Blocks')
            ->schema([
                Select::make('kind')
                    ->options([
                        PageBlock::HEADING => 'Heading — starts a section',
                        PageBlock::PARAGRAPH => 'Paragraph',
                    ])
                    ->default(PageBlock::PARAGRAPH)
                    ->required()
                    ->live()
                    // One "Add" plus a Select beats two add buttons: it also
                    // lets an editor promote a paragraph to a heading without
                    // retyping the sentence.
                    ->helperText('A heading opens a section. The paragraphs after it belong to it.'),

                Toggle::make('enabled')
                    ->label('Shown on the page')
                    ->default(true)
                    ->inline(false)
                    ->helperText('Off keeps the words and the position. Nothing is lost, and this brings it back.'),

                CheckboxList::make('conditions')
                    ->label('Only on pages where')
                    ->options(fn (): array => collect($this->region()?->conditions ?? [])
                        ->mapWithKeys(fn (Condition $c) => [$c->key => $c->label])
                        ->all())
                    ->columns(2)
                    ->columnSpanFull()
                    /*
                     * The automatic half of the guard, said out loud.
                     *
                     * A sentence mentioning a number is making a claim about it,
                     * so one naming :reduced simply does not appear where
                     * nothing is reduced — no tick required. These are for the
                     * other case: a sentence that needs a fact it does not name.
                     */
                    ->helperText('Ticked conditions must all hold. A block that names a value is already hidden where the page has no such value, so most blocks need nothing here.')
                    ->visible(fn (): bool => ($this->region()?->conditions ?? []) !== []),

                $this->variants(),

                Textarea::make('note')
                    ->label('Note')
                    ->rows(1)
                    ->columnSpanFull()
                    ->helperText('Why this block exists, for whoever inherits it.'),
            ])
            ->columns(2)
            ->itemLabel(fn (array $state): ?string => $this->itemLabel($state))
            ->addActionLabel('Add a block')
            ->collapsed()
            ->collapsible()
            ->cloneable()
            /*
             * Filament's own drag handle, rather than the up/down buttons used
             * on the Cove curation screen.
             *
             * The objection there — "a mis-drop silently reorders something a
             * person had already decided" — is about a list that is *saved as
             * you touch it*. Here nothing is written until Save, the order on
             * screen is the order that will be written, and a mis-drop is undone
             * by reloading the page without saving.
             */
            ->reorderable()
            ->reorderableWithButtons()
            ->defaultItems(0);
    }

    /**
     * The ways this block can be said.
     *
     * `reorderable(false)`: the draw is weighted, so ordering these would be a
     * control that does nothing.
     */
    private function variants(): Repeater
    {
        return Repeater::make('variants')
            ->label('Ways of saying it')
            ->columnSpanFull()
            ->schema([
                Textarea::make('body')
                    ->hiddenLabel()
                    ->required()
                    ->rows(3)
                    ->columnSpan(['default' => 1, 'lg' => 2])
                    ->live(onBlur: true)
                    ->rules([$this->bodyRule()]),

                TextInput::make('weight')
                    ->label('Shown this often')
                    ->numeric()
                    ->minValue(0)
                    ->maxValue(100)
                    ->default(1)
                    ->required()
                    ->helperText('Relative to the others here. 3 appears three times as often as 1. Zero retires it.'),

                Toggle::make('enabled')->label('In use')->default(true)->inline(false),
            ])
            ->columns(['default' => 1, 'lg' => 4])
            ->itemLabel(fn (array $state): ?string => Str::limit((string) ($state['body'] ?? ''), 70))
            ->addActionLabel('Add another way of saying this')
            ->defaultItems(1)
            ->reorderable(false)
            ->collapsed()
            ->collapsible()
            ->cloneable()
            ->minItems(1)
            // A block with no phrasing renders nothing and reads as a bug rather
            // than as an empty block.
            ->helperText('At least one. The site draws between them per page and per week, so two pages rarely read the same.');
    }

    /** A block's line in the collapsed list: its kind, its state, and its first words. */
    private function itemLabel(array $state): string
    {
        $body = (string) ($state['variants'][0]['body'] ?? '');
        $kind = ($state['kind'] ?? PageBlock::PARAGRAPH) === PageBlock::HEADING ? '▸ ' : '';
        $off = ($state['enabled'] ?? true) ? '' : '  (not shown)';

        return $kind.Str::limit($body !== '' ? $body : 'Empty block', 80).$off;
    }

    /**
     * Refuse anything this region cannot render.
     *
     * Three rules, and each one prevents a failure that is silent rather than
     * loud:
     *
     *  - a placeholder the region does not offer **renders as literal text** to
     *    a reader, or worse, names a fact the page cannot supply and quietly
     *    hides the whole block for ever;
     *  - a widget inside a sentence draws a `<ul>` inside a `<p>`, which
     *    browsers repair by closing the paragraph early — it looks fine and
     *    breaks a crawler's parse;
     *  - a link in a heading is not a heading.
     *
     * Wrapped in an outer closure that takes nothing. Filament resolves a rule
     * closure by parameter injection, so a bare `function ($attribute, $value,
     * $fail)` fails with "[$attribute] was unresolvable". The outer one is
     * evaluated by Filament and returns the Laravel closure rule untouched.
     */
    private function bodyRule(): Closure
    {
        return fn (): Closure => function (string $attribute, mixed $value, callable $fail): void {
            $region = $this->region();

            if ($region === null) {
                return;
            }

            $body = trim((string) $value);
            $names = PlaceholderRegistry::namesIn($body);

            $unknown = array_values(array_filter(
                $names,
                fn (string $n) => ! $region->offers($n),
            ));

            if ($unknown !== []) {
                $fail('This page cannot supply: :'.implode(', :', $unknown)
                    .'. Available here: :'.implode(' :', $region->placeholders));

                return;
            }

            foreach ($names as $name) {
                $function = PlaceholderRegistry::find($name);

                if ($function?->level() === Level::Block && $body !== ':'.$name) {
                    $fail(':'.$name.' draws a block of its own, so it has to be the only thing in this paragraph. Put the words around it in a paragraph of their own.');

                    return;
                }
            }

            // The kind is on the parent repeater item, so it is reached through
            // the attribute path rather than through Get — which cannot see up
            // a level from inside a nested repeater.
            if ($this->kindOf($attribute) === PageBlock::HEADING) {
                foreach ($names as $name) {
                    if (PlaceholderRegistry::find($name)?->sample()->type !== Value::TEXT) {
                        $fail('A heading cannot contain links or a block of its own. :'.$name.' produces one.');

                        return;
                    }
                }
            }
        };
    }

    /** The kind of the block a nested variant path belongs to. */
    private function kindOf(string $attribute): ?string
    {
        // data.blocks.<uuid>.variants.<uuid>.body
        $segments = explode('.', $attribute);

        return isset($segments[2])
            ? ($this->data['blocks'][$segments[2]]['kind'] ?? null)
            : null;
    }

    /**
     * Save the whole region in one transaction.
     *
     * Diffed rather than truncate-and-rewrite: a block that only moved should
     * keep its id, its author and its created_at, and a validation failure
     * halfway through must not leave an editor with half a region.
     *
     * `position` is renumbered from 1 in the order the form submitted, which is
     * the order on screen. Gaps work right up until somebody inserts between two
     * blocks, and a list that accumulates them becomes one where "move this up"
     * stops meaning anything.
     */
    public function save(): void
    {
        $state = $this->form->getState();

        // `blocks` only: `pageKey`, `region` and `language` share this state and
        // are the *scope* of the save rather than part of what it writes.
        $submitted = $state['blocks'] ?? [];

        $keptBlocks = [];
        $position = 1;

        DB::transaction(function () use ($submitted, &$keptBlocks, &$position): void {
            foreach ($submitted as $item) {
                $attributes = [
                    'page' => $this->pageKey,
                    'region' => $this->region,
                    'language' => $this->language,
                    'kind' => $item['kind'] ?? PageBlock::PARAGRAPH,
                    'position' => $position++,
                    'conditions' => array_values($item['conditions'] ?? []),
                    'enabled' => (bool) ($item['enabled'] ?? true),
                    'note' => $item['note'] ?? null,
                    'author_id' => auth()->id(),
                ];

                $block = isset($item['id']) ? PageBlock::query()->find($item['id']) : null;

                if ($block === null) {
                    $block = PageBlock::query()->create($attributes);
                } else {
                    $block->update($attributes);
                }

                $keptBlocks[] = $block->id;

                $this->saveVariants($block, $item['variants'] ?? []);
            }

            /*
             * Blocks the form no longer holds were removed by the editor.
             *
             * Scoped to the page, region and language on screen, so a save here
             * can never reach another region's work or another language's.
             */
            PageBlock::query()
                ->where('page', $this->pageKey)
                ->where('region', $this->region)
                ->where('language', $this->language)
                ->whereNotIn('id', $keptBlocks ?: [0])
                ->get()
                ->each(fn (PageBlock $block) => $block->delete());
        });

        PageCopy::flush();
        $this->load();

        $count = count($keptBlocks);

        Notification::make()
            ->title('Saved')
            ->body("{$count} block(s) live on the next page load.")
            ->success()
            ->send();
    }

    /**
     * Write a block's phrasings, dropping duplicates.
     *
     * Two identical bodies in a weighted draw are one phrasing pretending to be
     * two — it doubles that wording's odds while looking like variety, which is
     * the opposite of what this feature is for.
     *
     * @param  array<int|string, array<string, mixed>>  $submitted
     */
    private function saveVariants(PageBlock $block, array $submitted): void
    {
        $kept = [];
        $seen = [];

        foreach ($submitted as $item) {
            $body = trim((string) ($item['body'] ?? ''));

            if ($body === '' || isset($seen[$body])) {
                continue;
            }

            $seen[$body] = true;

            $attributes = [
                'block_id' => $block->id,
                'body' => $body,
                'weight' => (int) ($item['weight'] ?? 1),
                'enabled' => (bool) ($item['enabled'] ?? true),
                'author_id' => auth()->id(),
            ];

            $variant = isset($item['id']) ? $block->variants()->find($item['id']) : null;

            if ($variant === null) {
                $variant = $block->variants()->create($attributes);
            } else {
                $variant->update($attributes);
            }

            $kept[] = $variant->id;
        }

        $block->variants()->whereNotIn('id', $kept ?: [0])->delete();
    }

    /**
     * Every placeholder this region offers, for the palette.
     *
     * Rendered from the registry, so a function added in code next year appears
     * here with no admin change — which is the point of the registry. Without
     * the palette, "you can add placeholder functions later" means "and then
     * tell the editors by email".
     *
     * @return list<array{token: string, label: string, help: string, level: string, sample: string}>
     */
    public function palette(): array
    {
        $region = $this->region();

        if ($region === null) {
            return [];
        }

        return array_map(fn (PlaceholderFunction $f) => [
            'token' => ':'.$f->name(),
            'label' => $f->label(),
            'help' => $f->help(),
            'level' => $f->level() === Level::Block ? 'A block of its own' : 'Inside a sentence',
            'sample' => $this->sampleText($f),
        ], $region->functions());
    }

    private function sampleText(PlaceholderFunction $function): string
    {
        $value = $function->sample();

        return $value->type === Value::TEXT
            ? $value->text
            : implode(', ', array_column($value->items, 'label'));
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

            /*
             * The answer to "a block belongs to one language".
             *
             * Writing a region four times is the cost of letting each language
             * have its own shape, and this is what makes that cost bearable: it
             * reproduces the structure and the words, and the editor rewrites
             * from something rather than from a blank screen. It is also the
             * two-click fix when the guardrail test reports a language is empty.
             */
            Action::make('copyFrom')
                ->label('Copy from another language')
                ->icon(Heroicon::OutlinedDocumentDuplicate)
                ->color('gray')
                ->schema([
                    Select::make('from')
                        ->label('Take the blocks from')
                        ->options(fn () => collect(Market::languages())
                            ->reject(fn (string $l) => $l === $this->language)
                            ->mapWithKeys(fn (string $l) => [$l => $this->languageName($l)])
                            ->all())
                        ->required(),
                ])
                ->requiresConfirmation()
                ->modalDescription(fn (): string => 'Every block in this region for '
                    .$this->languageName($this->language)
                    .' is replaced by a copy of the other language\'s, words included. Translate them afterwards.')
                ->action(function (array $data): void {
                    $copied = $this->copyFrom((string) $data['from']);

                    Notification::make()
                        ->title('Copied')
                        ->body("{$copied} block(s) copied. The words are still in the other language.")
                        ->success()
                        ->send();
                }),
        ];
    }

    /**
     * Replace this region's blocks with another language's.
     *
     * A replace rather than a merge, and the confirmation says so: merging two
     * orderings produces one nonsense ordering, and there is no reading of "copy
     * this region" where the result is interleaved.
     */
    private function copyFrom(string $from): int
    {
        $copied = 0;

        DB::transaction(function () use ($from, &$copied): void {
            PageBlock::query()
                ->where('page', $this->pageKey)
                ->where('region', $this->region)
                ->where('language', $this->language)
                ->get()
                ->each(fn (PageBlock $block) => $block->delete());

            $source = PageBlock::query()
                ->where('page', $this->pageKey)
                ->where('region', $this->region)
                ->where('language', $from)
                ->with('variants')
                ->orderBy('position')
                ->get();

            foreach ($source as $original) {
                $block = PageBlock::query()->create([
                    'page' => $this->pageKey,
                    'region' => $this->region,
                    'language' => $this->language,
                    'kind' => $original->kind,
                    'position' => $original->position,
                    'conditions' => $original->conditions ?? [],
                    'enabled' => $original->enabled,
                    'note' => 'Copied from '.$this->languageName($from).' — rewrite before relying on it.',
                    'author_id' => auth()->id(),
                ]);

                foreach ($original->variants as $variant) {
                    $block->variants()->create([
                        'body' => $variant->body,
                        'weight' => $variant->weight,
                        'enabled' => $variant->enabled,
                        'author_id' => auth()->id(),
                    ]);
                }

                $copied++;
            }
        });

        PageCopy::flush();
        $this->load();

        return $copied;
    }
}
