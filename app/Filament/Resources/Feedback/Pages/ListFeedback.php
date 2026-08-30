<?php

declare(strict_types=1);

namespace App\Filament\Resources\Feedback\Pages;

use App\Filament\Resources\Feedback\FeedbackResource;
use Filament\Resources\Pages\ListRecords;

class ListFeedback extends ListRecords
{
    protected static string $resource = FeedbackResource::class;

    /** Nothing is created here — every row arrives from the public form. */
    protected function getHeaderActions(): array
    {
        return [];
    }
}
