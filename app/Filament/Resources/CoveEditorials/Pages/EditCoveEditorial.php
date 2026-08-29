<?php

declare(strict_types=1);

namespace App\Filament\Resources\CoveEditorials\Pages;

use App\Filament\Resources\CoveEditorials\CoveEditorialResource;
use Filament\Actions\Action;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Icons\Heroicon;

/**
 * Fixing a sentence on a published page.
 *
 * Deliberately the narrow half of the job: what a Cove is *about* and which
 * products it shows are decided on its plan, and a rebuild would overwrite
 * anything decided here. This screen is for the edit you make once, to prose a
 * model wrote, when you are not going to rebuild.
 *
 * The link to the plan is on the page for exactly that reason — it is where the
 * durable version of most edits belongs.
 */
class EditCoveEditorial extends EditRecord
{
    protected static string $resource = CoveEditorialResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('view')
                ->label('Open')
                ->icon(Heroicon::OutlinedArrowTopRightOnSquare)
                ->url(fn () => CoveEditorialResource::publicUrl($this->getRecord()))
                ->openUrlInNewTab(),
        ];
    }
}
