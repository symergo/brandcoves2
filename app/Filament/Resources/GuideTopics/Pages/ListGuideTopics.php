<?php

declare(strict_types=1);

namespace App\Filament\Resources\GuideTopics\Pages;

use App\Enums\Market;
use App\Filament\Resources\GuideTopics\GuideTopicResource;
use App\Services\Guides\SeasonalTopics;
use App\Services\Guides\TopicMiner;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Icons\Heroicon;

class ListGuideTopics extends ListRecords
{
    protected static string $resource = GuideTopicResource::class;

    /**
     * Refresh the queue by hand.
     *
     * Both passes run nightly. This exists because a fresh deploy, or a market
     * that has just had its first feed ingested, has an empty queue until the
     * next window — and an empty screen tells an editor nothing about whether the
     * feature works.
     *
     * No topic is created here that the nightly pass would not create, and
     * neither pass overturns a decision made on this screen.
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('refresh')
                ->label('Refresh queue')
                ->icon(Heroicon::OutlinedArrowPath)
                ->requiresConfirmation()
                ->modalDescription('Re-mines the search log and re-reads the seasonal calendar for every market. Existing decisions are left alone.')
                ->action(function (): void {
                    $mined = 0;
                    $seasonal = 0;

                    foreach (Market::cases() as $market) {
                        $mined += app(TopicMiner::class)->mine($market);
                        $seasonal += app(SeasonalTopics::class)->seed($market);
                    }

                    Notification::make()
                        ->title('Queue refreshed')
                        ->body("{$mined} mined candidates, {$seasonal} seasonal topics in season right now.")
                        ->success()
                        ->send();
                }),
        ];
    }
}
