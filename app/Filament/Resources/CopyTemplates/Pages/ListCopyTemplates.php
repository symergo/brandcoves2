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
                /*
                 * The warning matters as much as the description. A seeded row
                 * that only repeats the language file shadows it: rewriting the
                 * shipped copy afterwards changes nothing on this environment.
                 * `2026_08_24_000100_drop_the_seeded_copy_that_only_shadows_the_language_file`
                 * cleared exactly those rows, and this button puts all of them
                 * back in one click.
                 */
                ->modalDescription('Imports the sentences the site currently renders, as the first variant of each slot. A slot that already has any variant is left completely alone, so this cannot overwrite your edits. You rarely need this: the editor already shows every slot, with the shipped sentence as its placeholder. Use it when a new slot has been added.')
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
