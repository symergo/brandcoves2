<?php

declare(strict_types=1);

namespace App\Filament\Resources\ProductGroups\Pages;

use App\Enums\MatchStatus;
use App\Filament\Resources\ProductGroups\ProductGroupResource;
use App\Models\IdentityOverride;
use App\Models\MatchCandidate;
use App\Models\Product;
use App\Models\ProductGroup;
use App\Models\User;
use App\Services\Identity\GroupMerger;
use App\Services\Identity\GroupSplitter;
use Filament\Actions\Action;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Resources\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use InvalidArgumentException;

/**
 * One product, its offers, and the two corrections: merge and split.
 *
 * A plain page with header actions rather than an edit form, because nothing
 * on a product is editable by hand (the grouper rewrites it from the offers)
 * and both corrections act on more than this one row.
 */
class ManageProductGroup extends Page
{
    /*
     * The record through the trait, not a typed property: Livewire re-mounts
     * with the id, and a model-typed property throws on the second render.
     * The same trap CuratePlan documents.
     */
    use InteractsWithRecord;

    protected static string $resource = ProductGroupResource::class;

    protected string $view = 'filament.resources.product-groups.pages.manage-product-group';

    public function mount(int|string $record): void
    {
        $this->record = $this->resolveRecord($record);
    }

    public function group(): ProductGroup
    {
        /** @var ProductGroup */
        return $this->getRecord();
    }

    public function getTitle(): string
    {
        return '#'.$this->group()->id.' '.$this->group()->title;
    }

    /** @return Collection<int, Product> */
    public function offers(): Collection
    {
        return $this->group()->offers()->with('merchant')->orderByRaw('price ASC NULLS LAST')->orderBy('id')->get();
    }

    /** @return array<int, string> offer id => forced key, for the offers a person split */
    public function overrides(): array
    {
        return IdentityOverride::query()
            ->whereIn('product_id', $this->offers()->pluck('id'))
            ->pluck('forced_key', 'product_id')
            ->all();
    }

    /** @return Collection<int, MatchCandidate> */
    public function candidates(): Collection
    {
        $id = $this->group()->id;

        return MatchCandidate::query()
            ->where(fn ($q) => $q->where('group_a', $id)->orWhere('group_b', $id))
            ->with(['groupA', 'groupB'])
            ->orderByRaw("status = 'pending' DESC")
            ->orderByDesc('score')
            ->limit(20)
            ->get();
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('merge')
                ->label('Merge into...')
                ->icon(Heroicon::OutlinedArrowsPointingIn)
                ->visible(fn () => $this->group()->merged_into_id === null)
                ->modalHeading('Merge this product into another')
                ->modalDescription('Its offers, list items, alerts and Cove places move to the product you choose, and its page redirects there. Kept for good: the next grouping run follows the merge. To undo, split the offers back out on the other product.')
                ->schema([
                    Select::make('winner')
                        ->label('The product it is the same as')
                        ->searchable()
                        ->required()
                        ->getSearchResultsUsing(fn (string $search): array => $this->searchProducts($search))
                        ->getOptionLabelUsing(fn ($value): ?string => $this->label(ProductGroup::query()->find($value))),
                ])
                ->action(function (array $data): void {
                    $winner = ProductGroup::query()->findOrFail((int) $data['winner']);

                    try {
                        app(GroupMerger::class)->merge($this->group(), $winner, $this->user(), 'merged in admin');
                    } catch (InvalidArgumentException $e) {
                        Notification::make()->title('Not merged')->body($e->getMessage())->danger()->send();

                        return;
                    }

                    Notification::make()->title("Merged into #{$winner->id}")->success()->send();
                    $this->redirect(ProductGroupResource::getUrl('manage', ['record' => $winner]));
                }),

            Action::make('split')
                ->label('Split offers...')
                ->icon(Heroicon::OutlinedArrowsPointingOut)
                ->visible(fn () => $this->group()->merged_into_id === null && $this->offers()->count() > 1)
                ->modalHeading('Split offers into a new product')
                ->modalDescription('The ticked offers become one new product of their own and stay apart through every grouping run. The rest stay here.')
                ->schema([
                    CheckboxList::make('offers')
                        ->label('Offers that are a different product')
                        ->required()
                        ->options(fn (): array => $this->offers()->mapWithKeys(fn (Product $o) => [
                            $o->id => sprintf(
                                '%s: %s (%s)',
                                $o->merchant?->name ?? $o->source->value,
                                $o->title,
                                $o->price === null ? '-' : number_format($o->price / 100, 2).' '.$o->currency,
                            ),
                        ])->all()),
                ])
                ->action(function (array $data): void {
                    try {
                        $new = app(GroupSplitter::class)->split(
                            $this->group(),
                            array_map('intval', (array) $data['offers']),
                            $this->user(),
                            'split in admin',
                        );
                    } catch (InvalidArgumentException $e) {
                        Notification::make()->title('Not split')->body($e->getMessage())->danger()->send();

                        return;
                    }

                    Notification::make()->title("Split into #{$new->id}")->success()->send();
                    $this->redirect(ProductGroupResource::getUrl('manage', ['record' => $new]));
                }),

            Action::make('open')
                ->label('On the site')
                ->icon(Heroicon::OutlinedArrowTopRightOnSquare)
                ->color('gray')
                ->url(fn () => $this->group()->path())
                ->openUrlInNewTab(),
        ];
    }

    /**
     * Live products in the same market, by id or by title.
     *
     * Same market only: identity is per market (invariant 2) and the merger
     * refuses anything else, so offering it would only produce an error.
     *
     * @return array<int, string>
     */
    private function searchProducts(string $search): array
    {
        $group = $this->group();
        $search = trim($search);

        return ProductGroup::query()
            ->forMarket($group->market)
            ->whereNull('merged_into_id')
            ->whereKeyNot($group->id)
            ->when(
                ctype_digit($search),
                fn ($q) => $q->whereKey((int) $search),
                fn ($q) => $q->where('title', 'ilike', '%'.addcslashes($search, '%_\\').'%'),
            )
            ->orderByDesc('offer_count')
            ->limit(20)
            ->get()
            ->mapWithKeys(fn (ProductGroup $g) => [$g->id => $this->label($g)])
            ->all();
    }

    private function label(?ProductGroup $group): ?string
    {
        return $group === null ? null : "#{$group->id} {$group->title} ({$group->offer_count} offers)";
    }

    private function user(): ?User
    {
        $user = Auth::user();

        return $user instanceof User ? $user : null;
    }

    public function statusColor(MatchStatus $status): string
    {
        return match ($status) {
            MatchStatus::Pending => 'warning',
            MatchStatus::Merged => 'success',
            MatchStatus::Rejected => 'gray',
        };
    }
}
