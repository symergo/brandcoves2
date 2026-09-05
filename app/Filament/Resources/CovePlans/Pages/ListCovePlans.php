<?php

declare(strict_types=1);

namespace App\Filament\Resources\CovePlans\Pages;

use App\Enums\CoveKind;
use App\Enums\Market;
use App\Filament\Concerns\HasMarketTabs;
use App\Filament\Resources\CovePlans\CovePlanResource;
use App\Models\User;
use App\Services\Cove\PlanDrafter;
use App\Services\Cove\PlanState;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;

/**
 * One tab per kind, one per market, and a button that fills the pair you are on.
 *
 * The tabs are the same ones the editorial screen has, for the same reason:
 * "what is planned" is a question about every kind at once, and "why has this
 * market no guides queued" is a question about one. A single filtered table
 * answers both, and the `kind` dropdown filter answered neither — a filter is
 * something you have to think to apply, and the count that makes an empty
 * section obvious cannot appear on one.
 *
 * Market is the second axis, on its own strip beneath. Planning is done a
 * market at a time — every source of ideas is market-specific — so "what is
 * queued for the Netherlands" is the question this screen is opened with at
 * least as often as "what guides are queued". See
 * App\Filament\Concerns\HasMarketTabs.
 *
 * The two screens deliberately look alike. They are the same list at two points
 * in its life, and an editor moving between them should not have to re-learn
 * where things are.
 */
class ListCovePlans extends ListRecords
{
    use HasMarketTabs;

    protected static string $resource = CovePlanResource::class;

    /**
     * Create lives in the table header, next to the calendar it adds to.
     *
     * Drafting does not, and the difference is not cosmetic: Create adds one row
     * that you then fill in, and this fills the whole tab you are standing on.
     * It reads the active tab, which the table header — rendered by the table,
     * not the page — cannot see.
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('draft')
                ->label('Draft some')
                ->icon(Heroicon::OutlinedSparkles)
                ->color('primary')
                ->modalHeading('Draft plans to curate')
                ->modalDescription(
                    'Writes drafts, never approved plans, and calls no model — this costs nothing but rows. '
                    .'Each one arrives with the shortlist the builder would have chosen, for you to react to.'
                )
                ->modalSubmitActionLabel('Draft them')
                ->schema([
                    Select::make('kind')
                        ->label('Kind')
                        ->options($this->draftableKinds())
                        // The tab you are on. Pressing "draft some" while looking
                        // at the guides and being handed Daily Coves is the kind
                        // of thing that gets undone by hand for ten minutes.
                        ->default(fn () => $this->defaultKind())
                        ->required()
                        ->live()
                        ->helperText(fn (?string $state) => $this->sourceFor($state)),

                    Select::make('market')
                        ->label('Market')
                        ->options(collect(Market::cases())
                            ->mapWithKeys(fn (Market $m) => [$m->value => $m->label()])->all())
                        // The market strip you are standing on, for the same
                        // reason the kind follows its tab: drafting ten Belgian
                        // plans while looking at the Dutch ones is ten rows to
                        // undo by hand.
                        ->default(fn () => $this->defaultMarket())
                        ->required()
                        // One at a time. Every source is per market — the topic
                        // queue, the observance titles, the product nouns — and
                        // "all markets" would quietly write five times as many
                        // rows as the number in the box.
                        ->helperText('One market per run. Every source of ideas is market-specific.'),

                    TextInput::make('count')
                        ->label('How many')
                        ->numeric()
                        ->default(10)
                        ->minValue(1)
                        ->maxValue(50)
                        ->required()
                        ->helperText('Up to 50. Fewer than asked for means the source ran out, and it will say so.'),

                    Toggle::make('withProducts')
                        ->label('Suggest products for each')
                        ->default(true)
                        ->helperText('A ranked selection per plan. Turn it off for a large run — it is the slow part.'),
                ])
                ->action(function (array $data, PlanDrafter $drafter): void {
                    $result = $drafter->draft(
                        CoveKind::from($data['kind']),
                        Market::from($data['market']),
                        (int) $data['count'],
                        Auth::user() instanceof User ? Auth::user() : null,
                        (bool) $data['withProducts'],
                    );

                    if ($result->count() === 0) {
                        // Not an error. Nothing was drafted because there was
                        // nothing left to draft, and the reason is the useful
                        // part — it names the command that would produce more.
                        Notification::make()
                            ->title('Nothing to draft')
                            ->body($result->shortfall ?? 'No ideas left for that kind in that market.')
                            ->warning()
                            ->persistent()
                            ->send();

                        return;
                    }

                    Notification::make()
                        ->title($result->count().' draft(s) with '.$result->suggested.' suggested product(s)')
                        ->body($result->shortfall ?? 'Curate them, then approve. Nothing here publishes on its own.')
                        ->success()
                        ->persistent()
                        ->send();
                }),
        ];
    }

    /**
     * One tab per **state**, not per kind.
     *
     * The axis changed when this screen absorbed the editorial list. Kind is
     * what a page *is* and state is what to do to it next, and only the second
     * is a work queue — "three need writing, two are ready to build, one is too
     * thin to publish" is the sentence somebody opens this screen for. Kind
     * stayed as the dropdown filter it already had, because three tab strips is
     * more chrome than any screen can carry.
     *
     * `Thin` is the tab that could not exist before. An approved plan whose
     * catalogue had gone thin was indistinguishable from one whose date had not
     * arrived, so the work it needs was invisible.
     *
     * @return array<string, Tab>
     */
    public function getTabs(): array
    {
        $tabs = ['all' => Tab::make('All')];

        foreach (PlanState::cases() as $state) {
            $tabs[$state->value] = Tab::make($state->label())
                ->modifyQueryUsing(fn ($query) => PlanState::scope($query, $state))
                /*
                 * The count is the point on the empty ones: "nothing needs
                 * writing in the Netherlands" is a fact you want on the tab
                 * rather than one you discover by clicking through to an empty
                 * table. Counted inside the chosen market, or the number belongs
                 * to a view that no click reproduces.
                 */
                ->badge(fn () => $this->scopeToActiveMarket(static::getResource()::getEloquentQuery())
                    ->tap(fn ($q) => PlanState::scope($q, $state))
                    ->count());
        }

        return $tabs;
    }

    /**
     * The kinds that have somewhere to get ideas from.
     *
     * Advice and Shop are left out rather than offered and refused: an option
     * that always fails is a worse answer than an option that is not there, and
     * `PlanDrafter` still explains itself if something else asks for one.
     *
     * @return array<string, string>
     */
    private function draftableKinds(): array
    {
        $drafter = app(PlanDrafter::class);

        return collect(CoveKind::cases())
            ->filter(fn (CoveKind $kind) => $drafter->canDraft($kind))
            ->mapWithKeys(fn (CoveKind $kind) => [$kind->value => $kind->label()])
            ->all();
    }

    /**
     * The kind "Draft some" starts on.
     *
     * It used to read the active tab, which was a kind. The tab is a state now,
     * so it reads the `kind` filter instead — the same idea, from wherever the
     * kind is currently expressed: ten plans drafted into the wrong kind is ten
     * rows to undo by hand.
     */
    private function defaultKind(): string
    {
        $filtered = data_get($this->tableFilters, 'kind.value');

        $kind = CoveKind::tryFrom((string) $filtered);

        return $kind !== null && app(PlanDrafter::class)->canDraft($kind)
            ? $kind->value
            : CoveKind::Daily->value;
    }

    /** The market the current strip is showing, or the first one on "all". */
    private function defaultMarket(): string
    {
        return (Market::tryFrom((string) $this->activeMarket) ?? Market::cases()[0])->value;
    }

    /**
     * Where the ideas for this kind come from.
     *
     * Said on the form rather than in the docs because it is the one thing that
     * decides whether the result is any good: a market whose search log is empty
     * has no guide topics to draft, and knowing that here is the difference
     * between "the button is broken" and "mine some first".
     */
    private function sourceFor(?string $kind): string
    {
        return match (CoveKind::tryFrom((string) $kind)) {
            CoveKind::Daily => 'From the observance calendar: the next themed days that have no plan yet.',
            CoveKind::Guide => 'From the mined topic queue: what people searched for here, most demand first.',
            CoveKind::Seasonal => 'From the seasonal calendar, soonest window first — so a guide is written before its season, not during it.',
            CoveKind::Persona => 'One per gift-wizard interest, with that interest\'s own product words. The titles are placeholders and want renaming.',
            default => '',
        };
    }
}
