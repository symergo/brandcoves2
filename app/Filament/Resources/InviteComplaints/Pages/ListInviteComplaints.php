<?php

declare(strict_types=1);

namespace App\Filament\Resources\InviteComplaints\Pages;

use App\Filament\Resources\InviteComplaints\InviteComplaintResource;
use Filament\Resources\Pages\ListRecords;

class ListInviteComplaints extends ListRecords
{
    protected static string $resource = InviteComplaintResource::class;

    /** Nothing is created here: every row arrives from an email's spam link. */
    protected function getHeaderActions(): array
    {
        return [];
    }
}
