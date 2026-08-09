<?php

declare(strict_types=1);

namespace App\Filament\Resources\ApiTokens\Pages;

use App\Filament\Resources\ApiTokens\ApiTokenResource;
use App\Models\ApiToken;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;

/**
 * Minting a key, in two steps.
 *
 * The first collects what the key is for; the second shows the plaintext. They
 * have to be two modals rather than a form and a notification, because the
 * secret exists exactly once and a toast that can be dismissed by clicking
 * anywhere is the wrong container for something unrecoverable.
 *
 * `replaceMountedAction` is what makes the hand-off work: the mint action
 * finishes, and instead of closing it swaps itself for the reveal.
 */
class ListApiTokens extends ListRecords
{
    protected static string $resource = ApiTokenResource::class;

    public function getSubheading(): string|Htmlable|null
    {
        return 'Keys for the editorial API — the way an author, or Claude, writes Coves and guides '.
            'without a shell on the server.';
    }

    protected function getHeaderActions(): array
    {
        return [$this->mintAction()];
    }

    public function mintAction(): Action
    {
        return Action::make('mint')
            ->label('Mint a key')
            ->icon(Heroicon::OutlinedKey)
            ->modalHeading('Mint an API key')
            ->modalSubmitActionLabel('Mint')
            ->schema([
                TextInput::make('name')
                    ->label('What is this key for?')
                    ->required()
                    ->maxLength(120)
                    // The only thing that will identify it later. A row called
                    // "test" is a row nobody dares revoke.
                    ->helperText('Name the thing that will hold it — "Claude, daily Coves", "my laptop". You cannot tell them apart any other way.'),

                ApiTokenResource::abilityPicker(),

                DatePicker::make('expires_at')
                    ->label('Expires')
                    ->minDate(today()->addDay())
                    // No expiry is a legitimate choice, not an oversight: a key
                    // that silently stops working at 03:00 means a daily column
                    // that quietly stops appearing.
                    ->helperText('Leave empty for no expiry. Worth setting while you are trying something out.'),
            ])
            ->action(function (array $data, ListApiTokens $livewire): void {
                ['token' => $plaintext, 'model' => $token] = ApiToken::issue(
                    name: $data['name'],
                    abilities: array_values($data['abilities']),
                    expiresAt: filled($data['expires_at'] ?? null)
                        // End of the chosen day, not the start of it: a key
                        // picked to expire "on the 30th" that dies at midnight
                        // on the 29th is a key that expired a day early.
                        ? CarbonImmutable::parse($data['expires_at'])->endOfDay()
                        : null,
                    createdBy: auth()->id(),
                );

                $livewire->replaceMountedAction('revealToken', [
                    'token' => $plaintext,
                    'name' => $token->name,
                    'canPublish' => $token->can(ApiToken::PUBLISH),
                ]);
            });
    }

    public function revealTokenAction(): Action
    {
        return Action::make('revealToken')
            ->modalHeading('Copy this now')
            ->modalIcon(Heroicon::OutlinedKey)
            ->modalIconColor('warning')
            ->modalContent(fn (array $arguments) => view('filament.api-token-reveal', [
                'token' => $arguments['token'],
                'name' => $arguments['name'],
                'canPublish' => (bool) ($arguments['canPublish'] ?? false),
            ]))
            // No submit button: there is nothing to confirm. The only action
            // available is acknowledging that you have the key.
            ->modalSubmitAction(false)
            ->modalCancelActionLabel('I have copied it')
            ->closeModalByClickingAway(false)
            ->closeModalByEscaping(false);
    }
}
