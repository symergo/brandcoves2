<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Enums\IdeaPriceBand;
use App\Enums\Interest;
use App\Enums\Market;
use App\Enums\OfflineIdeaStatus;
use App\Enums\RecipientType;
use App\Models\OfflineIdea;
use App\Models\User;
use App\Services\Gift\GiftTags;
use BackedEnum;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use UnitEnum;

/**
 * Ideas people typed onto their lists by hand, read by a person before any
 * visitor sees one.
 *
 * The nightly count (OfflineIdeaCounter) proposes an idea only once five
 * different people wrote something that folds to it. This screen is the
 * second half of the promise: a person reads it, writes the wording a visitor
 * will see, tags who and what it suits, and approves or rejects it. Nothing
 * is shown before that. See docs/features/offline-ideas.md.
 *
 * Modelled on Match review: one idea at a time with keyboard shortcuts,
 * because it is a queue somebody sits down and works through.
 */
class OfflineIdeaReview extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedLightBulb;

    protected static string|UnitEnum|null $navigationGroup = 'Content';

    protected static ?string $navigationLabel = 'Offline ideas';

    protected static ?string $title = 'Offline ideas';

    protected string $view = 'filament.pages.offline-idea-review';

    /** Narrow the queue to one market; null for all. */
    public ?string $market = null;

    /** @var list<int> Ideas skipped in this sitting; back next time. */
    public array $skipped = [];

    /** An approved idea opened again to change its wording or tags. */
    public ?int $editing = null;

    /** The idea the form below was filled from. */
    public ?int $loaded = null;

    public string $wording = '';

    /** @var list<string> */
    public array $tags = [];

    public ?string $priceBand = null;

    public ?string $last = null;

    public static function getNavigationBadge(): ?string
    {
        $pending = OfflineIdea::query()->where('status', OfflineIdeaStatus::Pending->value)->count();

        return $pending === 0 ? null : (string) $pending;
    }

    public function mount(): void
    {
        $this->loadForm();
    }

    public function current(): ?OfflineIdea
    {
        if ($this->editing !== null) {
            return OfflineIdea::query()->find($this->editing);
        }

        return $this->queue()
            ->whereNotIn('id', $this->skipped)
            // The most-written first: likeliest to be a real idea, and the
            // one most people would be offered.
            ->orderByDesc('owners')
            ->orderBy('id')
            ->first();
    }

    public function remaining(): int
    {
        return $this->queue()->count();
    }

    /** @return Collection<int, OfflineIdea> */
    public function approved(): Collection
    {
        return OfflineIdea::query()
            ->approved()
            ->when($this->market, fn (Builder $q, string $m) => $q->where('market', $m))
            ->orderBy('market')
            ->orderBy('title')
            ->get();
    }

    public function approve(): void
    {
        $idea = $this->current();

        if ($idea === null) {
            return;
        }

        $title = trim($this->wording);
        $tags = GiftTags::normalise(array_values(array_intersect($this->tags, $this->tagValues())));

        if ($title === '') {
            Notification::make()->title('Write the wording visitors will see first.')->danger()->send();

            return;
        }

        /*
         * An idea with no tag would never show: it is matched to a brief by
         * its tags alone. Refused here rather than approved into nowhere.
         */
        if ($tags === []) {
            Notification::make()->title('Tag at least one interest, person or occasion, or it can never be shown.')->danger()->send();

            return;
        }

        $idea->update([
            'title' => mb_substr($title, 0, 120),
            'tags' => $tags,
            'price_band' => IdeaPriceBand::tryFrom((string) $this->priceBand),
            'status' => OfflineIdeaStatus::Approved,
            // Nothing anybody typed is kept once decided.
            'sample_title' => null,
            'decided_by' => $this->user()?->id,
            'decided_at' => now(),
        ]);

        $this->last = "Approved \"{$idea->title}\".";
        $this->next();
    }

    public function reject(): void
    {
        $idea = $this->current();

        if ($idea === null) {
            return;
        }

        $idea->update([
            'status' => OfflineIdeaStatus::Rejected,
            'sample_title' => null,
            'decided_by' => $this->user()?->id,
            'decided_at' => now(),
        ]);

        $this->last = $this->editing !== null ? 'Withdrawn.' : 'Rejected.';
        $this->next();
    }

    public function skip(): void
    {
        $idea = $this->current();

        if ($idea === null) {
            return;
        }

        if ($this->editing === null) {
            $this->skipped[] = $idea->id;
        }

        $this->last = null;
        $this->next();
    }

    public function edit(int $id): void
    {
        $this->editing = OfflineIdea::query()->approved()->whereKey($id)->exists() ? $id : null;
        $this->loaded = null;
        $this->loadForm();
    }

    public function updatedMarket(): void
    {
        $this->skipped = [];
        $this->editing = null;
        $this->last = null;
        $this->loaded = null;
        $this->loadForm();
    }

    /** @return array<string, string> */
    public function marketOptions(): array
    {
        return collect(Market::cases())->mapWithKeys(fn (Market $m) => [$m->value => $m->value])->all();
    }

    /**
     * The tags a reviewer can set, grouped as Find a gift asks: what they
     * like, who they are, what it is for.
     *
     * @return array<string, array<string, string>>
     */
    public function tagOptions(): array
    {
        $vocabulary = GiftTags::vocabulary();

        return [
            'Interests' => collect(Interest::cases())
                ->mapWithKeys(fn (Interest $i) => [GiftTags::interest($i->value) => $i->label()])
                ->all(),
            'For' => collect(RecipientType::cases())
                ->mapWithKeys(fn (RecipientType $r) => [GiftTags::recipient($r->value) => str_replace('_', ' ', $r->value)])
                ->all(),
            'Occasion' => collect($vocabulary[GiftTags::OCCASION])
                ->mapWithKeys(fn (string $o) => [GiftTags::occasion($o) => str_replace('_', ' ', $o)])
                ->all(),
        ];
    }

    /** @return array<string, string> */
    public function priceOptions(): array
    {
        return collect(IdeaPriceBand::cases())->mapWithKeys(fn (IdeaPriceBand $b) => [$b->value => $b->label()])->all();
    }

    /** Move on, and fill the form from whichever idea is now in front. */
    private function next(): void
    {
        $this->editing = null;
        $this->loaded = null;
        $this->loadForm();
    }

    /**
     * The form follows the idea in front. A waiting idea starts from how most
     * people spelled it, which the reviewer turns into wording; an approved
     * one from its own wording.
     */
    private function loadForm(): void
    {
        $idea = $this->current();

        if ($idea === null || $idea->id === $this->loaded) {
            return;
        }

        $this->loaded = $idea->id;
        $this->wording = (string) ($idea->title ?? $idea->sample_title ?? '');
        $this->tags = array_values((array) $idea->tags);
        $this->priceBand = $idea->price_band?->value;
    }

    /** @return list<string> */
    private function tagValues(): array
    {
        return array_keys(array_merge(...array_values($this->tagOptions())));
    }

    /** @return Builder<OfflineIdea> */
    private function queue(): Builder
    {
        return OfflineIdea::query()
            ->where('status', OfflineIdeaStatus::Pending->value)
            ->when($this->market, fn (Builder $q, string $m) => $q->where('market', $m));
    }

    private function user(): ?User
    {
        $user = Auth::user();

        return $user instanceof User ? $user : null;
    }
}
