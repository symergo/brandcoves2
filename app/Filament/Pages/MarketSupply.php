<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Enums\Market;
use App\Enums\Source;
use App\Filament\Resources\Feeds\FeedResource;
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
 * ## Read-only, and no network
 *
 * Nothing here fetches, ingests or spends. Every cell comes from the database
 * and `config()`, so the page loads when an upstream is down — which is exactly
 * when somebody opens it. Proving a credential is *accepted* still belongs to
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
