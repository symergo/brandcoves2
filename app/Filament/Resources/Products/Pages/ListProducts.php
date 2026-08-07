<?php

declare(strict_types=1);

namespace App\Filament\Resources\Products\Pages;

use App\Filament\Resources\Products\ProductResource;
use Filament\Resources\Pages\ListRecords;

class ListProducts extends ListRecords
{
    protected static string $resource = ProductResource::class;

    /** Offers come from feeds; there is nothing to create by hand. */
    protected function getHeaderActions(): array
    {
        return [];
    }
}
