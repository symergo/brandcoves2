<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Enums\IdentityKind;
use App\Enums\Market;
use App\Enums\MatchRule;
use App\Enums\MatchStatus;
use App\Models\MatchCandidate;
use App\Models\Product;
use App\Models\ProductGroup;
use App\Models\User;
use App\Services\Identity\GroupMerger;
use App\Services\Identity\MatchFinder;
use BackedEnum;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use InvalidArgumentException;
use UnitEnum;

/**
 * The queue of products that may be one, worked through a pair at a time.
 *
 * Modelled on the Cove curation screen (CuratePlan): Livewire methods and a
 * Blade view rather than a table, because this is a queue somebody sits down
 * and works through, and a table row per pair would hide the one thing the
 * decision needs, the two products side by side. Keyboard shortcuts (M, N, S)
 * for the same reason: a hundred decisions by mouse is a hundred trips across
 * the screen.
 *
 * Every pair waits for a person. No rule merges on its own yet: the owner has
 * not set a bar, and the precision table at the top is how that bar can be set
 * from numbers rather than a feeling. See docs/features/match-review.md.
 */
class MatchReview extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowsRightLeft;

    protected static string|UnitEnum|null $navigationGroup = 'Catalogue';

    protected static ?string $navigationLabel = 'Match review';

    protected static ?string $title = 'Match review';

    protected static ?int $navigationSort = 7;

    protected string $view = 'filament.pages.match-review';

    /** Narrow the queue to one market or one rule; null for all. */
    public ?string $market = null;

    public ?string $rule = null;

    /**
     * Pairs skipped in this sitting, so "Skip" moves on without deciding.
     * Not stored: a skipped pair is back next time, which is the point of
     * skipping rather than rejecting.
     *
     * @var list<int>
     */
    public array $skipped = [];

    /**
     * Which side survives a merge, when the person swapped it. Null means the
     * page's own suggestion (see keeper()).
     */
    public ?int $keep = null;

    /** The last decision, said quietly so a fast reviewer can see it landed. */
    public ?string $last = null;

    /** A count in the sidebar, so a filling queue is noticed. */
    public static function getNavigationBadge(): ?string
    {
        $pending = MatchCandidate::query()->where('status', MatchStatus::Pending->value)->count();

        return $pending === 0 ? null : (string) $pending;
    }

    public function current(): ?MatchCandidate
    {
        return $this->queue()
            ->whereNotIn('id', $this->skipped)
            // The most precise rule first, then the most similar titles: the
            // pairs most likely to be one, so the easy decisions come first
            // and the precision numbers fill quickly.
            ->orderByRaw("CASE rule WHEN 'barcode' THEN 0 WHEN 'model' THEN 1 ELSE 2 END")
            ->orderByDesc('score')
            ->orderBy('id')
            ->with(['groupA', 'groupB'])
            ->first();
    }

    public function remaining(): int
    {
        return $this->queue()->count();
    }

    /** @return array<string, array{decided: int, merged: int, pending: int, precision: float|null}> */
    public function precision(): array
    {
        return app(MatchFinder::class)->precision();
    }

    /**
     * The side that survives: the page's suggestion unless swapped.
     *
     * A barcode product over a title one (its key is the one the site trusts),
     * then the one with more offers (fewer rows move, and it is likelier to be
     * the page people already link to), then the older one.
     */
    public function keeper(MatchCandidate $candidate): ProductGroup
    {
        $a = $candidate->groupA;
        $b = $candidate->groupB;

        if ($this->keep === $a->id || $this->keep === $b->id) {
            return $this->keep === $a->id ? $a : $b;
        }

        $rank = fn (ProductGroup $g) => [
            $g->identity_kind === IdentityKind::Ean ? 0 : 1,
            -$g->offer_count,
            $g->id,
        ];

        return $rank($a) <= $rank($b) ? $a : $b;
    }

    /** @return Collection<int, Product> */
    public function offersOf(ProductGroup $group): Collection
    {
        return $group->offers()->with('merchant')->orderByRaw('price ASC NULLS LAST')->limit(6)->get();
    }

    public function swap(): void
    {
        $candidate = $this->current();

        if ($candidate === null) {
            return;
        }

        $keeper = $this->keeper($candidate);
        $this->keep = $keeper->id === $candidate->group_a ? $candidate->group_b : $candidate->group_a;
    }

    public function merge(): void
    {
        $candidate = $this->current();

        if ($candidate === null) {
            return;
        }

        $winner = $this->keeper($candidate);
        $loser = $winner->id === $candidate->group_a ? $candidate->groupB : $candidate->groupA;

        try {
            app(GroupMerger::class)->merge(
                $loser,
                $winner,
                $this->user(),
                'match review: '.$candidate->rule->value.($candidate->evidence ? ' '.$candidate->evidence : ''),
            );
        } catch (InvalidArgumentException $e) {
            Notification::make()->title('Not merged')->body($e->getMessage())->danger()->send();
            $this->skipped[] = $candidate->id;

            return;
        }

        $this->last = "Merged #{$loser->id} into #{$winner->id}.";
        $this->keep = null;
    }

    public function reject(): void
    {
        $candidate = $this->current();

        if ($candidate === null) {
            return;
        }

        $candidate->update([
            'status' => MatchStatus::Rejected,
            'decided_by' => $this->user()?->id,
            'decided_at' => now(),
        ]);

        $this->last = "Kept #{$candidate->group_a} and #{$candidate->group_b} apart.";
        $this->keep = null;
    }

    public function skip(): void
    {
        $candidate = $this->current();

        if ($candidate === null) {
            return;
        }

        $this->skipped[] = $candidate->id;
        $this->last = null;
        $this->keep = null;
    }

    public function updatedMarket(): void
    {
        $this->resetSitting();
    }

    public function updatedRule(): void
    {
        $this->resetSitting();
    }

    /** @return array<string, string> */
    public function marketOptions(): array
    {
        return collect(Market::cases())->mapWithKeys(fn (Market $m) => [$m->value => $m->value])->all();
    }

    /** @return array<string, string> */
    public function ruleOptions(): array
    {
        return collect([MatchRule::Barcode, MatchRule::Model, MatchRule::Title])
            ->mapWithKeys(fn (MatchRule $r) => [$r->value => $r->label()])
            ->all();
    }

    /**
     * Pending pairs whose two products are both still live. A pair whose
     * product was merged some other way since it was proposed is not
     * a decision anyone can make, so it is not shown.
     *
     * @return Builder<MatchCandidate>
     */
    private function queue(): Builder
    {
        return MatchCandidate::query()
            ->where('status', MatchStatus::Pending->value)
            ->when($this->market, fn (Builder $q, string $m) => $q->where('market', $m))
            ->when($this->rule, fn (Builder $q, string $r) => $q->where('rule', $r))
            ->whereHas('groupA', fn (Builder $q) => $q->whereNull('merged_into_id'))
            ->whereHas('groupB', fn (Builder $q) => $q->whereNull('merged_into_id'));
    }

    private function resetSitting(): void
    {
        $this->skipped = [];
        $this->keep = null;
        $this->last = null;
    }

    private function user(): ?User
    {
        $user = Auth::user();

        return $user instanceof User ? $user : null;
    }
}
