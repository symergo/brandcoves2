<?php

declare(strict_types=1);

namespace App\Filament\Resources\CommunityPosts\Pages;

use App\Filament\Resources\CommunityPosts\CommunityQuestionResource;
use Filament\Resources\Pages\ListRecords;

class ListCommunityQuestions extends ListRecords
{
    protected static string $resource = CommunityQuestionResource::class;

    // Nothing is created from here: an admin decides about other people's
    // writing rather than posting on their behalf.
    protected function getHeaderActions(): array
    {
        return [];
    }
}
