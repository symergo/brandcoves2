<?php

namespace App\Filament\Resources\Feeds\Pages;

use App\Filament\Resources\Feeds\FeedResource;
use App\Services\Catalogue\AwinFeedDiscovery;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Icons\Heroicon;

class ListFeeds extends ListRecords
{
    protected static string $resource = FeedResource::class;

    protected function getHeaderActions(): array
    {
        return [
            $this->discoverAction(),
            CreateAction::make(),
        ];
    }

    /**
     * Ask Awin what it has, and register it.
     *
     * Adding a shop was an SSH session and `bc:awin-feeds` — which is fine for
     * the person who wrote it and a wall for anyone else. The rules stay in
     * {@see AwinFeedDiscovery}, shared with the command, so the two cannot drift
     * apart about which feed belongs to which market.
     */
    private function discoverAction(): Action
    {
        return Action::make('discover')
            ->label('Discover feeds')
            ->icon(Heroicon::OutlinedMagnifyingGlass)
            ->modalSubmitActionLabel('Register')
            ->modalDescription(
                'Asks every configured Awin account which advertisers it is joined to, '
                .'matches them to a market by region and language, and registers them.'
            )
            ->form([
                TextInput::make('only')
                    ->label('Advertisers')
                    ->placeholder('vandenborre, dreamland')
                    ->helperText('Comma separated. Leave empty for the configured allowlist.'),

                TextInput::make('min_products')
                    ->label('Minimum products')
                    ->numeric()
                    ->default(100)
                    ->helperText('A feed of twelve products is not worth an hourly download.'),

                Checkbox::make('enable')
                    ->label('Switch them on straight away')
                    ->helperText(
                        'Off by default: enabling thirty feeds at once means thirty concurrent '
                        .'multi-hundred-megabyte downloads on the next scheduled run.'
                    ),

                Checkbox::make('dry_run')
                    ->label('Dry run — show what it finds and write nothing')
                    ->default(true),
            ])
            ->action(function (array $data, AwinFeedDiscovery $discovery): void {
                $available = $discovery->available();

                if ($available === []) {
                    Notification::make()
                        ->title('Awin returned nothing')
                        ->body(implode(' ', $discovery->warnings) ?: 'No account has an API token configured.')
                        ->danger()
                        ->send();

                    return;
                }

                $only = array_values(array_filter(array_map(
                    trim(...),
                    explode(',', (string) ($data['only'] ?? '')),
                )));

                $perMarket = $discovery->perMarket(
                    $available,
                    (int) ($data['min_products'] ?: 100),
                    null,
                    false,
                    $only,
                );

                $lines = [];
                $totals = ['created' => 0, 'updated' => 0, 'enabled' => 0];

                foreach ($perMarket as $market => $feeds) {
                    if ($feeds === []) {
                        continue;
                    }

                    $lines[] = $market.': '.implode(', ', array_column($feeds, 'advertiser'));

                    if ($data['dry_run'] ?? false) {
                        continue;
                    }

                    $result = $discovery->register($market, $feeds, (bool) ($data['enable'] ?? false));

                    foreach ($totals as $key => $value) {
                        $totals[$key] = $value + $result[$key];
                    }
                }

                if ($lines === []) {
                    Notification::make()
                        ->title('Nothing matched')
                        ->body('No advertiser matched, in any market. Check the spelling, or lower the minimum.')
                        ->warning()
                        ->send();

                    return;
                }

                Notification::make()
                    ->title($data['dry_run'] ?? false
                        ? 'Found, and wrote nothing'
                        : "{$totals['created']} registered, {$totals['updated']} updated, {$totals['enabled']} switched on")
                    ->body(implode(' · ', $lines))
                    ->success()
                    ->send();
            });
    }
}
