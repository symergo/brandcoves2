<?php

declare(strict_types=1);

namespace App\Filament\Resources\CovePlans\Pages;

use App\Enums\PickMode;
use App\Enums\PlanWriter;
use App\Filament\Resources\CovePlans\CovePlanResource;
use App\Jobs\BuildCove;
use App\Models\CovePlan;
use App\Models\CovePlanItem;
use App\Services\Cove\EditionBuilder;
use App\Services\Curation\CurationSearch;
use App\Services\Curation\PlanCurator;
use App\Services\Curation\ScheduleConflicts;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Resources\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;
use InvalidArgumentException;
use Throwable;

/**
 * Choose the products a Cove is written about, before it is written.
 *
 * The old surface for this was a multi-select on the plan form running
 * `title ILIKE '%term%'` against `product_groups`. It could only find what had
 * already been ingested, showed nothing but a title, could not be ordered, and
 * had nowhere to record *why* a product was on the list — which is the one
 * thing the writer actually needs.
 *
 * This screen is the same decision made properly:
 *
 *   - the search reaches every merchant, because it goes through SearchService,
 *     which calls the live connectors and folds what they return into the
 *     catalogue in the same request. A bol product nobody has ingested can be
 *     found and pinned here in one go;
 *   - the shortlist is ordered, and the order is the order the article follows;
 *   - each item carries a note, handed to the writer as the reason that product
 *     is there.
 *
 * ## What the layout is for
 *
 * The list and the search sit side by side, and the search pane is sticky. The
 * first version stacked them, and curating meant scrolling down to search, then
 * back up to see what you had — once per product, seven times a page. Nothing
 * about that was broken and all of it was tiring.
 *
 * Every destructive action here is reversible rather than confirmed. A modal
 * asking "are you sure" costs a click on every removal to protect against the
 * rare one; an undo costs nothing until it is needed. The exceptions are
 * approve and build, which are not undoable — they put a page in front of
 * readers.
 *
 * The build still happens later, in a queued job, from the plan. Nothing on
 * this page calls a model — see invariant 1.
 */
class CuratePlan extends Page
{
    /*
     * The record comes from the trait, not from a typed property of our own.
     *
     * Declaring `public ?CovePlan $record` looks tidier and breaks hydration:
     * Livewire re-mounts the component with the route parameter — an id — and
     * assigning an int to a model-typed property throws on the second render,
     * inside a Blade view, with a message about a property nobody wrote.
     */
    use InteractsWithRecord;

    protected static string $resource = CovePlanResource::class;

    protected string $view = 'filament.resources.cove-plans.pages.curate-plan';

    public string $term = '';

    /** Euros, as typed. The commonest constraint a curator works under. */
    public ?string $maxPrice = null;

    /** @var list<array<string, mixed>> */
    public array $results = [];

    /** Whether a search has run, so the empty state can say which empty it is. */
    public bool $searched = false;

    /** @var array<int, string|null> Per-item note, keyed by item id. */
    public array $notes = [];

    /** @var array<int, string|null> Per-item verdict, keyed by item id. */
    public array $verdicts = [];

    /**
     * The last removal, kept so it can be put back.
     *
     * @var array<string, mixed>|null
     */
    public ?array $undo = null;

    /** Which item just saved its note, so the row can say so quietly. */
    public ?int $justSaved = null;

    /**
     * What the editor wants said, as opposed to what they want shown.
     *
     * The direction a person gives before the writing starts — "keep it short",
     * "lean on the nostalgia, not the tech". Distinct from the per-item notes,
     * which are about one product each, and from `editorial`, which is the
     * finished article and skips the model entirely.
     */
    public ?string $instructions = null;

    public bool $instructionsSaved = false;

    public function mount(int|string $record): void
    {
        $this->record = $this->resolveRecord($record);
        $this->instructions = $this->plan()->build_instructions;
        $this->syncFields();
    }

    /** The plan being curated, typed, so the rest of the class can rely on it. */
    public function plan(): CovePlan
    {
        /** @var CovePlan */
        return $this->getRecord();
    }

    public function getTitle(): string
    {
        return 'Curate: '.$this->plan()->title;
    }

    // ── Searching ─────────────────────────────────────────────────────────

    /**
     * Run the search that reaches every merchant.
     *
     * Results are held in a property rather than recomputed by the view. A
     * Livewire component re-renders on every interaction — adding an item,
     * typing a note — and a view that searched would call the live connectors
     * again each time, spending a rate-limited budget on a page nobody asked to
     * refresh.
     *
     * Explicitly submitted rather than live-as-you-type for the same reason: a
     * debounced keystroke search reads as friendlier and would put a request to
     * every merchant behind every pause in someone's typing.
     */
    public function runSearch(CurationSearch $search): void
    {
        $this->searched = true;

        $this->results = array_map(fn ($result) => [
            'key' => $result->key,
            'title' => $result->title,
            'brand' => $result->brand,
            'image' => $result->imageUrl,
            'price' => $result->price,
            'merchants' => $result->merchantCount,
            'in_stock' => $result->inStock,
            'sources' => array_map(fn ($source) => $source->value, $result->sources),
            'live_only' => $result->groupId === null,
            'added' => $result->alreadyAdded,
            'conflict' => $result->conflict,
            'url' => $result->groupId === null
                ? null
                : '/'.$this->plan()->market->value.'/p/'.$result->groupId,
        ], $search->search($this->plan(), $this->term, $this->maxPriceCents()));
    }

    public function clearSearch(): void
    {
        $this->term = '';
        $this->maxPrice = null;
        $this->results = [];
        $this->searched = false;
    }

    // ── Building the shortlist ────────────────────────────────────────────

    public function add(string $key, PlanCurator $curator): void
    {
        try {
            $curator->add($this->plan(), $key, Auth::user());
        } catch (InvalidArgumentException $e) {
            Notification::make()->title('Could not add that')->body($e->getMessage())->danger()->send();

            return;
        } catch (Throwable $e) {
            report($e);
            Notification::make()->title('Could not add that')->danger()->send();

            return;
        }

        // Marked added in place rather than by re-running the search: the live
        // half of one costs a request, and nothing about the results changed.
        $this->results = array_map(
            fn (array $row) => $row['key'] === $key ? [...$row, 'added' => true] : $row,
            $this->results,
        );

        $this->refreshPlan();
    }

    /**
     * Fill the rest of the list with what the engine would have chosen.
     *
     * The blank page, solved on the screen rather than only in the planner. A
     * plan created by hand in the panel arrives empty, and asking someone to
     * invent seven products from nothing is the reason the old pinned-products
     * field went unused. This offers the same selection the builder would make
     * on the day, for them to react to.
     *
     * Tops up rather than replaces, and never touches what is already there.
     */
    public function suggest(EditionBuilder $builder, PlanCurator $curator): void
    {
        // The kind's own target, not the Daily's: a guide wants seven and an
        // advice article wants none.
        $wanted = $this->plan()->kind->targetItems() - $this->plan()->items()->count();

        if ($wanted < 1) {
            Notification::make()->title('The list is already full')->send();

            return;
        }

        $exclude = $this->plan()->items()->pluck('group_id')->filter()->all();
        $added = 0;

        foreach ($builder->candidates($this->plan(), $wanted, $exclude) as $group) {
            try {
                $curator->add($this->plan(), 'group:'.$group->id, Auth::user());
                $added++;
            } catch (Throwable) {
                // A candidate that will not attach is one fewer suggestion, not
                // a failed action: the rest of them are still worth having.
                continue;
            }
        }

        $this->refreshPlan();

        Notification::make()
            ->title($added === 0 ? 'Nothing left to suggest' : "Added {$added} suggestion(s)")
            ->body($added === 0
                ? 'The catalogue has nothing scored for this market that is not already on the list. Has bc:refresh-discovery run?'
                : 'These are the engine\'s picks. Remove what does not belong and say why the rest are here.')
            ->send();
    }

    /**
     * Remove an item, and keep enough to put it back.
     *
     * Undo rather than a confirmation dialog. A modal on every removal charges
     * a click for each of the six correct ones to protect the seventh; an undo
     * charges nothing until it is wanted, and it restores the rank and the note
     * as well as the product — which a confirmation could never have done for
     * the removal somebody meant.
     */
    public function removeItem(int $itemId, PlanCurator $curator): void
    {
        $item = $this->item($itemId);

        if ($item === null) {
            return;
        }

        $key = $item->group_id !== null
            ? 'group:'.$item->group_id
            : $item->source->value.':'.$item->external_id;

        $this->undo = [
            'label' => $item->group?->title ?? $key,
            'group_id' => $item->group_id,
            'source' => $item->source?->value,
            'external_id' => $item->external_id,
            'rank' => $item->rank,
            'note' => $item->note,
            'verdict' => $item->verdict,
        ];

        $curator->remove($item);

        $this->results = array_map(
            fn (array $row) => $row['key'] === $key ? [...$row, 'added' => false] : $row,
            $this->results,
        );

        $this->refreshPlan();
    }

    public function undoRemove(PlanCurator $curator): void
    {
        if ($this->undo === null) {
            return;
        }

        $restored = $this->undo;
        $this->undo = null;

        $this->plan()->items()->create([
            'group_id' => $restored['group_id'],
            'source' => $restored['source'],
            'external_id' => $restored['external_id'],
            'rank' => $restored['rank'],
            'note' => $restored['note'],
            'verdict' => $restored['verdict'],
            'added_by' => Auth::id(),
        ]);

        // Renumber, because the rank it went back to is now shared with
        // whatever moved up into the gap.
        $curator->reorder($this->plan(), $this->plan()->items()->pluck('id')->all());

        $this->refreshPlan();
    }

    public function dismissUndo(): void
    {
        $this->undo = null;
    }

    // ── Ordering ──────────────────────────────────────────────────────────

    /**
     * Move one item up or down.
     *
     * Buttons rather than drag-and-drop. The order is the running order of the
     * article, a list of seven is quicker to nudge than to drag, and a mis-drop
     * silently reorders something a person had already decided.
     */
    public function move(int $itemId, int $direction, PlanCurator $curator): void
    {
        $ids = $this->plan()->items()->pluck('id')->all();
        $at = array_search($itemId, $ids, true);

        if ($at === false) {
            return;
        }

        $to = $at + $direction;

        if ($to < 0 || $to >= count($ids)) {
            return;
        }

        [$ids[$at], $ids[$to]] = [$ids[$to], $ids[$at]];

        $curator->reorder($this->plan(), $ids);
        $this->refreshPlan();
    }

    /**
     * Promote an item straight to the front.
     *
     * Because the common edit is "this is the one the article should open
     * with", and expressing it as six presses of an arrow is the interface
     * making the person do the arithmetic.
     */
    public function moveToTop(int $itemId, PlanCurator $curator): void
    {
        $ids = $this->plan()->items()->pluck('id')->all();

        if (! in_array($itemId, $ids, true)) {
            return;
        }

        $curator->reorder($this->plan(), [
            $itemId,
            ...array_values(array_filter($ids, fn (int $id) => $id !== $itemId)),
        ]);

        $this->refreshPlan();
    }

    // ── The brief ─────────────────────────────────────────────────────────

    /** Save the curator's note for one item. */
    public function saveNote(int $itemId): void
    {
        $item = $this->item($itemId);

        if ($item === null) {
            return;
        }

        $note = trim((string) ($this->notes[$itemId] ?? ''));
        $verdict = trim((string) ($this->verdicts[$itemId] ?? ''));

        $item->update([
            'note' => $note === '' ? null : $note,
            'verdict' => $verdict === '' ? null : $verdict,
        ]);

        // A toast per blur turned typing a note into a stream of notifications.
        // The row says "saved" and fades; nothing needs dismissing.
        $this->justSaved = $itemId;
    }

    public function saveInstructions(): void
    {
        $instructions = trim((string) $this->instructions);

        $this->plan()->update([
            'build_instructions' => $instructions === '' ? null : $instructions,
        ]);

        $this->instructionsSaved = true;
        $this->refreshPlan();
    }

    // ── What the plan is ──────────────────────────────────────────────────

    /**
     * Switch between "the engine may fill" and "this list is the page".
     *
     * On the screen rather than only on the edit form, because it is a decision
     * made *while* looking at the list — "these four are the page" is a thought
     * you have with the four in front of you, not one you navigate away to
     * record.
     */
    public function setPickMode(string $mode): void
    {
        $parsed = PickMode::tryFrom($mode);

        if ($parsed === null) {
            return;
        }

        $this->plan()->update(['pick_mode' => $parsed->value]);
        $this->refreshPlan();
    }

    /** @return list<CovePlanItem> */
    public function items(): array
    {
        return $this->plan()->items()->with('group')->get()->all();
    }

    /**
     * Where each item is already spoken for, keyed by item id.
     *
     * @return array<int, string>
     */
    public function conflicts(ScheduleConflicts $conflicts): array
    {
        $items = $this->plan()->items()->get(['id', 'group_id']);

        $byGroup = $conflicts->for(
            $this->plan()->market,
            $items->pluck('group_id')->filter()->all(),
            $this->plan()->id,
        );

        return $items
            ->filter(fn (CovePlanItem $item) => isset($byGroup[$item->group_id]))
            ->mapWithKeys(fn (CovePlanItem $item) => [$item->id => $byGroup[$item->group_id]])
            ->all();
    }

    /**
     * One line saying what this plan will actually publish.
     *
     * The question every curator has and the screen could not previously
     * answer: I have four products — is that the page, or will something else
     * appear next to them?
     */
    public function summary(): string
    {
        $items = $this->plan()->items()->count();
        $target = $this->plan()->kind->targetItems();

        if ($this->plan()->pick_mode === PickMode::Locked) {
            return $items === 1
                ? 'The Cove will show this one product, and nothing else.'
                : "The Cove will show these {$items} products, in this order, and nothing else.";
        }

        $filled = max(0, $target - $items);

        return $filled === 0
            ? "These {$items} products lead the Cove and fill it."
            : "These {$items} lead the Cove; the engine adds {$filled} more to reach {$target}.";
    }

    /**
     * Why this plan cannot be built yet, if it cannot.
     *
     * Said here rather than discovered at 06:00. A locked plan is exactly its
     * shortlist, so one under the floor produces no page at all — and the only
     * signal for that today would be a line in the log.
     */
    public function warning(): ?string
    {
        // Per kind. Judging a buying guide against the column's floor calls a
        // page unbuildable that would publish fine, and vice versa.
        $minimum = $this->plan()->kind->minimumItems();
        $items = $this->plan()->items()->count();

        if ($this->plan()->pick_mode === PickMode::Locked && $items < $minimum) {
            return "Locked, with {$items} product(s). Under {$minimum} the Cove does not publish at all — add more, or set this plan to open so the engine can fill it.";
        }

        $unbuyable = $this->plan()->items()
            ->whereNotNull('group_id')
            ->whereHas('group', fn ($q) => $q->where('in_stock', false))
            ->count();

        if ($unbuyable > 0) {
            return "{$unbuyable} curated product(s) are out of stock and will be left out of the Cove. Replace them, or leave them and accept a shorter page.";
        }

        return null;
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('approve')
                ->icon(Heroicon::OutlinedCheck)
                ->visible(fn () => $this->plan()->status === 'draft')
                // Confirmed, unlike everything else here: approving is what
                // lets the builder use this, and it cannot be taken back once
                // the page has published.
                ->requiresConfirmation()
                ->action(function (): void {
                    $this->plan()->update(['status' => 'approved']);
                    $this->refreshPlan();
                    Notification::make()->title('Approved')->success()->send();
                }),

            Action::make('build')
                ->label('Build now')
                ->icon(Heroicon::OutlinedPlay)
                ->visible(fn () => $this->plan()->status === 'approved')
                ->requiresConfirmation()
                ->modalDescription('Builds this Cove immediately so you can read it before anyone else. Rebuilding is idempotent — it updates in place rather than making a second one.')
                ->action(function (): void {
                    /*
                     * One job, whatever the kind — and it reads the plan's own
                     * date rather than today's. Dispatching without one built
                     * today's edition from a plan written for next Tuesday, so
                     * the button appeared to do nothing. See App\Jobs\BuildCove.
                     */
                    BuildCove::dispatch($this->plan()->id);

                    Notification::make()
                        ->title('Build queued')
                        ->body('Watch Horizon, then open the Cove.')
                        ->success()
                        ->send();
                }),

            Action::make('back')
                ->label('The planner')
                ->icon(Heroicon::OutlinedArrowLeft)
                ->color('gray')
                ->url(fn () => CovePlanResource::getUrl('index')),
        ];
    }

    private function maxPriceCents(): ?int
    {
        $euros = (float) str_replace(',', '.', (string) $this->maxPrice);

        return $euros > 0 ? (int) round($euros * 100) : null;
    }

    private function item(int $itemId): ?CovePlanItem
    {
        // Scoped to this plan: an id arriving through a Livewire call is
        // caller-supplied input, even when the caller is an admin.
        return CovePlanItem::query()
            ->where('plan_id', $this->plan()->id)
            ->find($itemId);
    }

    private function refreshPlan(): void
    {
        $this->plan()->refresh();
        $this->syncFields();
    }

    /**
     * Whether the model will be asked to write at all.
     *
     * Prose an author wrote wins outright and skips the model, so instructions
     * for a build that will not run are a field quietly doing nothing — and the
     * screen says so rather than letting somebody write a brief nobody reads.
     */
    /**
     * Set who writes this Cove.
     *
     * The switch that stops the panel being the one surface where the new model
     * is worse than the old guess. Both API endpoints default `writer` from
     * whether prose was sent; a person typing into a form sends nothing, so
     * without this their article would stay marked `builder` and the next build
     * would replace it.
     */
    public function setWriter(string $writer): void
    {
        $parsed = PlanWriter::tryFrom($writer);

        if ($parsed === null) {
            return;
        }

        $this->plan()->update(['writer' => $parsed->value]);
        $this->refreshPlan();
    }

    /**
     * Will anything be generated for this plan?
     *
     * Asked of the plan rather than of whether a box happens to be empty. It
     * decides whether the build instructions are read by anybody, and a field
     * quietly doing nothing is worse than no field at all.
     */
    public function willBeWritten(): bool
    {
        return $this->plan()->writer->callsModel();
    }

    private function syncFields(): void
    {
        $items = $this->plan()->items()->get(['id', 'note', 'verdict']);

        $this->notes = $items->pluck('note', 'id')->all();
        $this->verdicts = $items->pluck('verdict', 'id')->all();
    }
}
