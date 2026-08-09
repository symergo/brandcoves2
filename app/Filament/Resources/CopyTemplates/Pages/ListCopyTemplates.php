<?php

declare(strict_types=1);

namespace App\Filament\Resources\CopyTemplates\Pages;

use App\Filament\Resources\CopyTemplates\CopyTemplateResource;
use App\Services\Seo\CopyBank;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Artisan;

class ListCopyTemplates extends ListRecords
{
    protected static string $resource = CopyTemplateResource::class;

    /**
     * The cached draw set is dropped after any write on this screen.
     *
     * Two minutes is a short TTL, and it is still long enough for an editor to
     * save a line, reload the site, see the old one and conclude the admin does
     * not work. Flushing here makes the change appear on the next request, which
     * is the only behaviour anyone will believe.
     */
    protected function afterSave(): void
    {
        CopyBank::flush();
    }

    protected function afterCreate(): void
    {
        CopyBank::flush();
    }

    protected function afterDelete(): void
    {
        CopyBank::flush();
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('seed')
                ->label('Import shipped copy')
                ->icon(Heroicon::OutlinedArrowDownTray)
                ->requiresConfirmation()
                ->modalDescription('Imports the sentences the site currently renders, as the first variant of each slot. A slot that already has any variant is left completely alone, so this cannot overwrite your edits.')
                ->action(function (): void {
                    Artisan::call('bc:seed-copy');

                    CopyBank::flush();

                    Notification::make()
                        ->title('Imported')
                        ->body(trim(Artisan::output()))
                        ->success()
                        ->send();
                }),
        ];
    }
}
