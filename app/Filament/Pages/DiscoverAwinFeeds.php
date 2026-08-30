<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Enums\Market;
use App\Enums\Source;
use App\Models\Feed;
use App\Services\Catalogue\AwinFeedDiscovery;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use UnitEnum;

/**
 * Every Awin feed this publisher account can reach, to pick from.
 *
 * ## What this replaces
 *
 * A modal with a text box: type advertiser names, comma separated, and hope. It
 * worked for whoever wrote the allowlist and was a wall for anybody else —
 * you had to already know a shop was on Awin, and to spell it the way Awin
 * spells it, which is "Vanden Borre BE" one month and something else the next.
 * Nothing on screen ever told you what was actually on offer.
 *
 * So the list comes first. Everything the accounts are joined to, with the
 * market each one maps to and whether it is already registered, and a search box
 * that narrows it. Registering is then picking rows rather than guessing names.
 *
 * ## The matching rule stays in one place
 *
 * `AwinFeedDiscovery::marketFor()` decides which market a feed belongs to, and
 * this page only labels rows with its answer. A second copy of that rule is how
 * a Belgian feed ends up serving Dutch shoppers Belgian prices, stock and
 * delivery — the same class of error market-scoped product identity exists to
 * prevent. A feed matching no market cannot be registered here at all, rather
 * than being registered somewhere plausible.
 *
 * ## Fetched once, not once per keystroke
 *
 * `available()` is one HTTP request per configured account against Awin, and the
 * table re-evaluates its data source on every search, sort and page. So the
 * result is cached and the search filters the cached list in PHP. **Refresh from
 * Awin** is the only thing that goes back out, and it is a button rather than a
 * timer because a person adding a shop knows when they joined it.
 */
class DiscoverAwinFeeds extends Page implements HasTable
{
    use InteractsWithTable;

    /**
     * Ten minutes.
     *
     * Long enough that a session of searching and registering costs one round
     * trip, short enough that somebody who joined an advertiser this morning is
     * not told to come back tomorrow. The Refresh button covers the impatient
     * case, which is the one that actually happens.
     */
    private const CACHE_TTL = 600;

    private const CACHE_KEY = 'bc:awin-available-feeds';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedMagnifyingGlassCircle;

    protected static string|UnitEnum|null $navigationGroup = 'Catalogue';

    protected static ?string $navigationLabel = 'Discover Awin feeds';

    protected static ?string $title = 'Discover Awin feeds';

    protected static ?int $navigationSort = 2;

    protected string $view = 'filament.pages.discover-awin-feeds';

    /** @var list<string> Accounts that could not be reached on the last fetch. */
    public array $warnings = [];

    public function table(Table $table): Table
    {
        return $table
            /*
             * An array data source, not a query.
             *
             * These rows are Awin's, not ours — they exist only in the response
             * we just fetched. Filament hands the closure the search term and
             * the sort, and the filtering happens here in PHP, which is what
             * makes "searching narrows the list" work against a list that has
             * no table behind it.
             */
            ->records(fn (?string $search, array $sort): Collection => $this->rows($search, $sort))
            ->columns([
                TextColumn::make('advertiser')
                    ->label('Advertiser')
                    ->searchable()
                    ->sortable()
                    ->weight('medium')
                    // Which account reaches it, because an advertiser is only
                    // reachable through the publisher account joined to them.
                    ->description(fn (array $record): string => $record['accountLabel']),

                TextColumn::make('feedName')
                    ->label('Feed')
                    ->toggleable()
                    ->placeholder('—')
                    ->wrap()
                    // A retailer publishes many category feeds. Which one this
                    // is decides whether you get the whole shop or its garden
                    // furniture.
                    ->description(fn (array $record): string => 'feed '.$record['feed_id']
                        .($record['advertiserId'] !== '' ? ' · advertiser '.$record['advertiserId'] : '')),

                TextColumn::make('vertical')
                    ->label('Sector')
                    ->toggleable()
                    ->placeholder('—')
                    ->badge()
                    ->color('gray'),

                TextColumn::make('market')
                    ->label('Market')
                    ->badge()
                    ->color(fn (array $record): string => $record['market'] === null ? 'gray' : 'primary')
                    ->formatStateUsing(fn (?string $state): string => $state ?? 'no market')
                    // The region and language are the *reason* for the market, and
                    // an editor wondering why a Belgian feed is not offered for
                    // the Dutch market should not have to ask.
                    ->description(fn (array $record): string => trim($record['region'].' · '.$record['language']
                        .($record['currency'] !== '' ? ' · '.$record['currency'] : ''))),

                TextColumn::make('products')
                    ->label('Products')
                    ->numeric()
                    ->sortable()
                    ->alignEnd()
                    // Size is the first thing you look at and the second thing
                    // that misleads: a huge feed from a shop that stopped
                    // publishing is a large stale download.
                    ->description(fn (array $record): string => $record['lastImported'] === ''
                        ? ''
                        : 'imported '.$record['lastImported'], position: 'above'),

                TextColumn::make('status')
                    ->label('')
                    ->badge()
                    ->color(fn (array $record): string => match ($record['status']) {
                        'running' => 'success',
                        'registered' => 'warning',
                        default => 'gray',
                    })
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'running' => 'on',
                        'registered' => 'registered, off',
                        default => 'not registered',
                    })
                    ->description(fn (array $record): ?string => $record['registeredIn']),
            ])
            ->filters([
                SelectFilter::make('market')
                    ->label('Market')
                    ->options(collect(Market::cases())
                        ->mapWithKeys(fn (Market $m) => [$m->value => $m->value])
                        ->all()),

                Filter::make('unregistered')
                    ->label('Not registered yet')
                    ->toggle(),

                Filter::make('serviceable')
                    ->label('Hide feeds no market can use')
                    ->toggle()
                    ->default(),

                Filter::make('substantial')
                    // A feed of twelve products is not worth an hourly download,
                    // and there are a great many of them.
                    ->label('At least 100 products')
                    ->toggle()
                    ->default(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    $this->registerAction('register', 'Register', enable: false),
                    $this->registerAction('registerAndEnable', 'Register and switch on', enable: true),
                ]),
            ])
            ->defaultSort('products', 'desc')
            ->emptyStateHeading('Nothing matched')
            ->emptyStateDescription('Awin spells shop names its own way and changes them without warning — try a shorter word, or clear the filters.')
            ->paginated([25, 50, 100]);
    }

    /**
     * Register the picked feeds, each into the market its region and language say.
     *
     * Grouped by market rather than asking, because the market is not a choice:
     * a Belgian-Dutch feed carries Belgian prices, stock and delivery, and
     * offering it for `nl-nl` would be offering somebody a mistake.
     */
    private function registerAction(string $name, string $label, bool $enable): BulkAction
    {
        return BulkAction::make($name)
            ->label($label)
            ->icon($enable ? Heroicon::OutlinedBolt : Heroicon::OutlinedPlus)
            ->color($enable ? 'warning' : 'primary')
            ->requiresConfirmation()
            ->modalDescription($enable
                // Thirty feeds switched on at once is thirty concurrent
                // multi-hundred-megabyte downloads on the next scheduled run.
                ? 'They start downloading on the next scheduled run. Switching on many large feeds at once means many concurrent multi-hundred-megabyte downloads.'
                : 'Registered but left off, so nothing downloads until you switch them on.')
            ->deselectRecordsAfterCompletion()
            ->action(function (Collection $records) use ($enable): void {
                $byMarket = [];
                $skipped = 0;

                foreach ($records as $record) {
                    $market = $record['market'] ?? null;

                    if ($market === null) {
                        // No region-and-language match, so there is no honest
                        // market to put it in. Counted and named rather than
                        // dropped in silence.
                        $skipped++;

                        continue;
                    }

                    $byMarket[$market][] = $record;
                }

                if ($byMarket === []) {
                    Notification::make()
                        ->title('Nothing registered')
                        ->body('None of those feeds matches a market we serve, so there is nowhere to put them.')
                        ->warning()
                        ->send();

                    return;
                }

                $discovery = app(AwinFeedDiscovery::class);
                $totals = ['created' => 0, 'updated' => 0, 'enabled' => 0];

                foreach ($byMarket as $market => $feeds) {
                    $result = $discovery->register($market, $feeds, $enable);

                    foreach ($totals as $key => $value) {
                        $totals[$key] = $value + $result[$key];
                    }
                }

                $body = collect($byMarket)
                    ->map(fn (array $feeds, string $market) => $market.': '.implode(', ', array_column($feeds, 'advertiser')))
                    ->implode(' · ');

                if ($skipped > 0) {
                    $body .= "  ({$skipped} skipped — no market matches their region and language.)";
                }

                Notification::make()
                    ->title("{$totals['created']} registered, {$totals['updated']} already known, {$totals['enabled']} switched on")
                    ->body($body)
                    ->success()
                    ->send();
            });
    }

    /**
     * The rows, filtered the way the table asks for them.
     *
     * @param  array{0: ?string, 1: ?string}  $sort
     * @return Collection<string, array<string, mixed>>
     */
    private function rows(?string $search, array $sort): Collection
    {
        $rows = collect($this->feeds());

        $search = trim((string) $search);

        if ($search !== '') {
            /*
             * Matched on a stripped, lowercased form.
             *
             * Awin writes "Vanden Borre BE" and "Coolblue NL", and a person
             * types "vandenborre" or "coolblue". Comparing the normalised forms
             * is what makes the search feel like it works — the same folding
             * `AwinFeedDiscovery::isWanted()` does for the allowlist, and for
             * the same reason.
             */
            $needle = $this->fold($search);

            $rows = $rows->filter(fn (array $row) => str_contains($this->fold($row['advertiser']), $needle)
                || str_contains($this->fold($row['feedName']), $needle)
                || str_contains($this->fold($row['vertical']), $needle)
                || str_contains($this->fold($row['accountLabel']), $needle)
                // The id is how somebody arrives from a support thread or the
                // feeds table, where the number is all they have.
                || str_contains((string) $row['feed_id'], $search));
        }

        foreach ($this->tableFilters ?? [] as $name => $state) {
            $rows = $this->applyFilter($rows, $name, $state);
        }

        [$column, $direction] = [$sort[0] ?? null, $sort[1] ?? 'asc'];

        if ($column !== null) {
            $rows = $rows->sortBy(
                fn (array $row) => $row[$column] ?? null,
                SORT_NATURAL | SORT_FLAG_CASE,
                $direction === 'desc',
            );
        }

        return $rows->values()->keyBy('key');
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $rows
     * @param  array<string, mixed>  $state
     * @return Collection<int, array<string, mixed>>
     */
    private function applyFilter(Collection $rows, string $name, array $state): Collection
    {
        return match ($name) {
            'market' => filled($state['value'] ?? null)
                ? $rows->where('market', $state['value'])
                : $rows,
            'unregistered' => ($state['isActive'] ?? false)
                ? $rows->where('status', 'new')
                : $rows,
            'serviceable' => ($state['isActive'] ?? false)
                ? $rows->filter(fn (array $row) => $row['market'] !== null)
                : $rows,
            'substantial' => ($state['isActive'] ?? false)
                ? $rows->filter(fn (array $row) => $row['products'] >= 100)
                : $rows,
            default => $rows,
        };
    }

    private function fold(string $value): string
    {
        return strtolower((string) preg_replace('/[^a-z0-9]/i', '', $value));
    }

    /**
     * Everything Awin offers, annotated with what we know about it.
     *
     * @return list<array<string, mixed>>
     */
    private function feeds(): array
    {
        $discovery = app(AwinFeedDiscovery::class);

        $cached = Cache::remember(self::CACHE_KEY, self::CACHE_TTL, function () use ($discovery): array {
            $available = $discovery->available();

            return ['feeds' => array_values($available), 'warnings' => $discovery->warnings];
        });

        $this->warnings = $cached['warnings'];

        // One query rather than one per row: a publisher account can be joined
        // to hundreds of advertisers.
        $registered = Feed::query()
            ->where('source', Source::Awin)
            ->get(['external_feed_id', 'market', 'enabled'])
            ->keyBy(fn (Feed $feed) => $feed->external_feed_id.'|'.$feed->market->value);

        return array_map(function (array $feed) use ($discovery, $registered): array {
            $market = $discovery->marketFor($feed);
            $row = $registered->get($feed['id'].'|'.$market);

            return [
                ...$feed,
                /*
                 * The Awin feed id moves to `feed_id`.
                 *
                 * Filament keys an array record on `__key` and would otherwise
                 * leave `id` alone — but two accounts joined to the same
                 * advertiser share a feed id, and a row keyed on that would make
                 * one of them unselectable. The account is what disambiguates.
                 */
                'feed_id' => $feed['id'],
                'key' => $feed['account'].':'.$feed['id'],
                'market' => $market,
                'status' => match (true) {
                    $row === null => 'new',
                    (bool) $row->enabled => 'running',
                    default => 'registered',
                },
                'registeredIn' => $row === null ? null : 'as '.$row->label,
            ];
        }, $cached['feeds']);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('refresh')
                ->label('Refresh from Awin')
                ->icon(Heroicon::OutlinedArrowPath)
                ->color('gray')
                ->action(function (): void {
                    Cache::forget(self::CACHE_KEY);

                    $count = count($this->feeds());

                    Notification::make()
                        ->title("{$count} feed(s) on offer")
                        ->body($this->warnings === []
                            ? 'Fetched from every configured account.'
                            : implode(' ', $this->warnings))
                        ->color($this->warnings === [] ? 'success' : 'warning')
                        ->send();
                }),
        ];
    }
}
