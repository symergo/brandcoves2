<?php

declare(strict_types=1);

namespace App\Filament\Resources\CommunityPosts\Pages;

use App\Filament\Resources\CommunityPosts\CommunityAnswerResource;
use Filament\Resources\Pages\ListRecords;

class ListCommunityAnswers extends ListRecords
{
    protected static string $resource = CommunityAnswerResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
