<?php

declare(strict_types=1);

namespace App\Filament\Resources\Users\Schemas;

use App\Enums\Market;
use App\Models\User;
use Closure;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Auth;

class UserForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Account')
                    ->schema([
                        TextInput::make('name')
                            ->maxLength(255)
                            // Most accounts have none: the magic-link flow
                            // only ever asked for an address.
                            ->placeholder('Not given'),

                        TextInput::make('email')
                            ->email()
                            ->required()
                            ->maxLength(255)
                            // The database index is on lower(email), so the
                            // check has to fold case too or the form accepts
                            // an address the save then rejects with a 500.
                            ->rule(fn (?User $record) => function (string $attribute, mixed $value, Closure $fail) use ($record): void {
                                $taken = User::query()
                                    ->whereRaw('lower(email) = ?', [mb_strtolower(trim((string) $value))])
                                    ->when($record !== null, fn ($q) => $q->whereKeyNot($record->getKey()))
                                    ->exists();

                                if ($taken) {
                                    $fail('Another account already uses this address.');
                                }
                            })
                            ->dehydrateStateUsing(fn (?string $state) => $state === null ? null : mb_strtolower(trim($state))),

                        Select::make('preferred_market')
                            ->label('Preferred market')
                            ->options(collect(Market::cases())->mapWithKeys(fn (Market $m) => [$m->value => $m->label()])->all())
                            ->placeholder('Not chosen'),

                        Toggle::make('email_opt_in')
                            ->label('Receives digests and price-drop emails')
                            ->helperText('Their choice to make. Turn it off on request; never on.'),
                    ])
                    ->columns(2),

                Section::make('Panel access')
                    ->description('Administrators see connector credentials, AI spend, every account and the whole catalogue.')
                    ->schema([
                        Toggle::make('is_admin')
                            ->label('Administrator')
                            ->live()
                            // Nobody removes their own access: the last admin
                            // locking themselves out is fixable only from a
                            // shell, and one mis-click is all it takes.
                            ->disabled(fn (?User $record) => self::isSelf($record))
                            ->helperText(fn (?User $record) => self::isSelf($record)
                                ? 'You cannot take away your own access. Ask another administrator.'
                                : null),

                        TextInput::make('password')
                            ->label('Panel password')
                            ->password()
                            ->revealable()
                            ->autocomplete('new-password')
                            ->minLength(8)
                            // The site is passwordless; the panel is not. An
                            // admin without one can be granted the flag and
                            // still never get in, which reads as a broken
                            // login rather than a missing password.
                            ->visible(fn (Get $get) => (bool) $get('is_admin'))
                            ->required(fn (Get $get, ?User $record) => (bool) $get('is_admin') && $record?->password === null)
                            ->placeholder(fn (?User $record) => $record?->password === null ? 'Required before they can sign in' : 'Leave empty to keep the current one')
                            // Only ever written when something was typed: an
                            // empty field must not blank the existing hash.
                            ->dehydrated(fn (?string $state) => filled($state)),
                    ]),
            ]);
    }

    /** The row being edited is the administrator editing it. */
    private static function isSelf(?User $record): bool
    {
        return $record !== null && $record->getKey() === Auth::id();
    }
}
