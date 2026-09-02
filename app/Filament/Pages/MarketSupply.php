<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Enums\Market;
use App\Enums\Source;
use App\Filament\Resources\Feeds\FeedResource;
use App\Services\Connectors\SourceSwitch;
use App\Services\Ops\MarketSupply as Supply;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

/**
 * What supplies each market, feed and live sources side by side.
 *
 * ## The gap this fills
 *
 * The Feeds table answers *which market is this feed for* and nothing answered
 * the reverse. Worse, it could only ever answer it for Awin: a `feeds` row is a
 * feed source, and bol, eBay and Tradedoubler have no rows at all. Their market
 * coverage is computed from config at request time, so the panel — which is
 * where somebody goes to ask "why is nothing showing for Spain" — held none of
 * it. The answer lived on the console, in the `bc:check-*` commands.
 *
 * Putting both kinds in one grid is the point. "This market has supply" is one
 * question whichever mechanism provides it, and splitting it across a table of
 * rows and a set of shell commands is what let a market go dark unnoticed.
 *
 * ## One writable thing, and still no network
 *
 * This page was read-only until it grew the source switches, and the promise
 * that actually mattered is the one that survives: nothing here fetches,
 * ingests or spends. Every cell still comes from the database and `config()`,
 * so the page loads when an upstream is down — which is exactly when somebody
 * opens it, and exactly when they want to switch the failing source off.
 *
 * Putting the switch here rather than on a settings page of its own is the
 * whole point. This grid already *is* source x market; a second identical grid
 * elsewhere would mean reading the diagnosis in one screen and acting on it in
 * another, with nothing keeping the two in step. See
 * {@see SourceSwitch} for what a switch does and,
 * more importantly, what it does not. Proving a credential is *accepted* still belongs to
 * `bc:check-bol`, `bc:check-ebay` and `bc:check-tradedoubler`, and the page
 * names them rather than pretending to replace them. The one button re-counts
 * the database; it calls nobody.
 *
 * The service holds the rules — see {@see Supply} for why they are not methods
 * on this class.
 */
class MarketSupply extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedGlobeEuropeAfrica;

    protected static string|UnitEnum|null $navigationGroup = 'Catalogue';

    protected static ?string $navigationLabel = 'Market supply';

    protected static ?string $title = 'Market supply';

    /** Above the feed screens: this is the question they are answers to. */
    protected static ?int $navigationSort = 0;

    protected string $view = 'filament.pages.market-supply';

    /**
     * A count of dark markets in the sidebar, or nothing.
     *
     * The only number worth interrupting somebody with. A market no source
     * serves is a market whose pages render, whose sitemap is submitted and
     * whose search returns nothing at all — and it has no other symptom in the
     * panel.
     */
    public static function getNavigationBadge(): ?string
    {
        $dark = count(app(Supply::class)->darkMarkets());

        return $dark === 0 ? null : (string) $dark;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'danger';
    }

    /** @return list<array<string, mixed>> */
    public function rows(): array
    {
        return app(Supply::class)->rows();
    }

    /** @return list<Source> */
    public function sources(): array
    {
        return app(Supply::class)->sources();
    }

    /**
     * The feeds list, already narrowed to this market.
     *
     * The whole reason to show a feed count is to go and look at what is behind
     * it, and re-applying the filter by hand on arrival is where that intent
     * gets lost.
     */
    public function feedsUrl(Market $market): string
    {
        return FeedResource::getUrl('index', [
            'tableFilters' => ['market' => ['value' => $market->value]],
        ]);
    }

    /**
     * Is this source on in this market, for the switch grid.
     *
     * Read through the service rather than cached on the page: Livewire
     * re-renders this component on every toggle, and a value captured in mount()
     * would show the old state until a full page load.
     */
    public function isEnabled(Source $source, Market $market): bool
    {
        return app(SourceSwitch::class)->isEnabled($source, $market);
    }

    /**
     * Flip one source in one market.
     *
     * No confirmation on the way *off*, deliberately. It is one click to undo,
     * it destroys nothing — the catalogue a feed source already built stays
     * exactly where it is — and a modal on a switch somebody is using to stop a
     * misbehaving source during an incident is a modal in the way. The
     * destructive neighbour, `bc:withdraw-source`, is a console command with its
     * own dry run for precisely the opposite reason.
     */
    public function toggle(string $source, string $market): void
    {
        $source = Source::from($source);
        $market = Market::from($market);

        $switch = app(SourceSwitch::class);
        $enabled = ! $switch->isEnabled($source, $market);

        $switch->set($source, $market, $enabled);

        // The cells read counts through the cached aggregate; the switch state
        // is read live. Forgetting it keeps the two from disagreeing in the
        // same render.
        app(Supply::class)->forget();

        Notification::make()
            ->title($source->label().' '.($enabled ? 'switched on' : 'switched off').' for '.$market->value)
            ->body($enabled
                ? 'Searches and ingestion will use it again from the next request.'
                : 'Nothing will be asked of it in this market. Offers it already stored stay in search — bc:withdraw-source suppresses those.')
            ->success()
            ->send();
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('refresh')
                ->label('Re-count')
                ->icon(Heroicon::OutlinedArrowPath)
                ->color('gray')
                // Only the row counts are cached, and only for a minute — this
                // is for the person who has just triggered an ingestion and
                // wants to watch it land, not a workaround for staleness.
                ->action(function (): void {
                    app(Supply::class)->forget();

                    Notification::make()->title('Counts refreshed')->success()->send();
                }),
        ];
    }
}
