<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Enums\CoveKind;
use App\Enums\Market;
use App\Services\Settings\AutomationSettingsStore;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

/**
 * Which editorial stages run unattended, as a grid per market.
 *
 * Five stages × six kinds × five markets is 150 switches. As a list that is
 * unreadable; as **one grid per market** — kinds down, stages across — it is
 * thirty cells that fit on a screen, and the shape of the grid shows the shape
 * of the domain: the disabled cells are exactly where a kind has no automatic
 * source.
 *
 * ## The one switch that removes a person
 *
 * `approve` is not another checkbox. Everything else fills the planner, chooses
 * products or writes words, and none of it can reach a reader — `buildArticle()`
 * refuses a plan nobody approved. Turning `approve` on for a kind is what
 * `PlanDrafter`'s docblock calls a content farm with a nicer interface, and it
 * is the only cell on this page that changes who decides what publishes.
 *
 * So it is coloured differently, it asks before it saves, and the planner
 * carries a marker naming every market and kind it is on for. A setting that
 * reaches readers and is visible only on the screen that sets it is a setting
 * somebody forgets is on.
 *
 * ## It writes, unlike the read-only panels
 *
 * `Market trends` is deliberately read-only because its buttons would spend a
 * rate-limited API budget. Nothing here does: these are rows, read by two
 * scheduled jobs and one walk.
 */
class Automation extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBolt;

    protected static string|UnitEnum|null $navigationGroup = 'Operations';

    protected static ?string $navigationLabel = 'Automation';

    protected static ?string $title = 'What runs without you';

    protected string $view = 'filament.pages.automation';

    /** The market whose grid is on screen. */
    public string $market = '';

    /** @var array<string, array<string, string>> */
    public array $grid = [];

    public function mount(): void
    {
        $this->market = Market::cases()[0]->value;
        $this->load();
    }

    public function updatedMarket(): void
    {
        $this->load();
    }

    private function load(): void
    {
        $this->grid = app(AutomationSettingsStore::class)->grid($this->marketEnum());
    }

    public function marketEnum(): Market
    {
        return Market::tryFrom($this->market) ?? Market::cases()[0];
    }

    /** @return list<string> */
    public function stages(): array
    {
        return AutomationSettingsStore::STAGES;
    }

    /** @return list<CoveKind> */
    public function kinds(): array
    {
        return CoveKind::cases();
    }

    public function applies(string $stage, CoveKind $kind): bool
    {
        return AutomationSettingsStore::applies($stage, $kind);
    }

    public function whyNot(string $stage, CoveKind $kind): ?string
    {
        return AutomationSettingsStore::whyNot($stage, $kind);
    }

    /** @return array<string, string> */
    public function markets(): array
    {
        return collect(Market::cases())
            ->mapWithKeys(fn (Market $m) => [$m->value => $m->label()])
            ->all();
    }

    /**
     * Toggle one cell.
     *
     * `write` cycles rather than toggles, because it is a three-way: off, then
     * the builder writing on this server under the daily cap, then an outside
     * agent writing for nothing. Cycling keeps one control per cell in a grid
     * where every other cell is a checkbox.
     */
    public function toggle(string $stage, string $kindValue): void
    {
        $kind = CoveKind::tryFrom($kindValue);

        if ($kind === null || ! AutomationSettingsStore::applies($stage, $kind)) {
            return;
        }

        $current = $this->grid[$kindValue][$stage] ?? '0';

        if ($stage === 'write') {
            $order = AutomationSettingsStore::WRITERS;
            $next = $order[(array_search($current, $order, true) + 1) % count($order)];
        } else {
            $next = $current === '1' ? '0' : '1';
        }

        /*
         * Approving without a person is the one change worth interrupting for.
         *
         * Filament cannot confirm a `wire:click` on a grid cell, so this is said
         * as a notification the moment it is switched on rather than as a modal
         * before. It is persistent: a warning that fades is a warning nobody
         * read.
         */
        if ($stage === 'approve' && $next === '1') {
            Notification::make()
                ->title('That publishes without a person')
                ->body("Approved {$kind->label()}s in {$this->marketEnum()->label()} will now go live on their "
                    .'own, with nobody reading them first. Everything else on this page only prepares work.')
                ->warning()
                ->persistent()
                ->send();
        }

        $this->grid[$kindValue][$stage] = $next;
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('save')
                ->label('Save this market')
                ->icon(Heroicon::OutlinedCheck)
                ->action(function (): void {
                    app(AutomationSettingsStore::class)->putGrid($this->marketEnum(), $this->grid);

                    Notification::make()
                        ->title('Saved')
                        ->body('The next scheduled run uses these.')
                        ->success()
                        ->send();
                }),
        ];
    }

    /**
     * Every market and kind that publishes with nobody reading it.
     *
     * @return array<string, list<string>>
     */
    public function publishing(): array
    {
        return app(AutomationSettingsStore::class)->publishingMarkets();
    }
}
