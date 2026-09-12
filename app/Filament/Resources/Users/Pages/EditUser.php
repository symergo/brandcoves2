<?php

declare(strict_types=1);

namespace App\Filament\Resources\Users\Pages;

use App\Filament\Resources\Users\UserResource;
use App\Models\User;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

class EditUser extends EditRecord
{
    protected static string $resource = UserResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make()
                // Deleting yourself from inside the panel ends the session
                // mid-request and leaves whoever is next with one admin fewer.
                ->hidden(fn (User $record) => $record->getKey() === Auth::id())
                ->modalDescription('Their lists, recipients, friendships and inbox go with the account. Nothing brings them back.'),
        ];
    }

    /**
     * Save with `forceFill`, not `update`.
     *
     * `is_admin` is guarded against mass assignment on purpose — it is what
     * stops a stray `User::create($request->all())` from minting an
     * administrator — and Filament's default save is a mass assignment. With
     * it, the toggle on this form would flip on screen, save without an error
     * and change nothing, which is the worst kind of bug to hand an
     * administrator who has just granted somebody access.
     *
     * This page is the one place in the app that may write the flag from a
     * request, and it sits behind the panel's own admin gate.
     *
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        $record->forceFill($data)->save();

        return $record;
    }
}
