<?php

declare(strict_types=1);

namespace App\Filament\Resources\ApiTokens;

use App\Filament\Resources\ApiTokens\Pages\ListApiTokens;
use App\Models\ApiToken;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Forms\Components\CheckboxList;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use UnitEnum;

/**
 * Keys for the editorial API.
 *
 * Deliberately not a normal CRUD resource. A key is not edited into existence:
 * it is minted, its plaintext is shown exactly once, and from then on the row
 * is a record of something that already exists elsewhere. A standard create
 * form would imply the secret can be looked at again, which is the one thing
 * that is not true.
 *
 * So: minting is a modal action that ends in a reveal, and the only fields that
 * remain editable afterwards are the ones that are not the secret.
 *
 * See docs/features/editorial-api.md.
 */
class ApiTokenResource extends Resource
{
    protected static ?string $model = ApiToken::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedKey;

    // With the AI settings and usage screens: this is access management, not
    // content, even though what it grants access to is writing.
    protected static string|UnitEnum|null $navigationGroup = 'Operations';

    protected static ?string $navigationLabel = 'API keys';

    protected static ?string $modelLabel = 'API key';

    /**
     * The ability picker, shared by minting and by changing an existing key.
     *
     * One list in one place, because the descriptions are the part that
     * actually decides what someone ticks, and two copies would drift.
     */
    public static function abilityPicker(): CheckboxList
    {
        return CheckboxList::make('abilities')
            ->label('What this key may do')
            ->options([
                ApiToken::READ => 'Read',
                ApiToken::WRITE => 'Write drafts',
                ApiToken::PUBLISH => 'Publish',
            ])
            ->descriptions([
                ApiToken::READ => 'Look up products, guide topics, plans and published editions. Reads nothing personal.',
                ApiToken::WRITE => 'Create and rewrite drafts. Nothing written with this alone can reach a reader.',
                ApiToken::PUBLISH => 'Approve a plan, publish a guide, queue a build. Anything this key writes can go live without anyone seeing it first.',
            ])
            ->default([ApiToken::READ, ApiToken::WRITE])
            ->required();
    }

    public static function form(Schema $schema): Schema
    {
        // Nothing is created through a form here. Minting is an action on the
        // list page, because it ends in a reveal rather than in a saved record.
        return $schema->components([]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->searchable()
                    ->description(fn (ApiToken $r) => $r->creator?->email),

                TextColumn::make('abilities')
                    ->badge()
                    // Stripped of the prefix: every row would otherwise read
                    // "editorial.editorial.editorial" and the differences —
                    // which are the whole point of the column — get lost in it.
                    ->formatStateUsing(fn (string $state) => str_replace('editorial.', '', $state))
                    ->color(fn (string $state) => $state === ApiToken::PUBLISH ? 'warning' : 'gray'),

                TextColumn::make('state')
                    ->badge()
                    ->state(fn (ApiToken $r) => match (true) {
                        $r->revoked_at !== null => 'revoked',
                        ! $r->isUsable() => 'expired',
                        default => 'live',
                    })
                    ->color(fn (string $state) => match ($state) {
                        'live' => 'success',
                        'revoked' => 'danger',
                        default => 'gray',
                    }),

                /*
                 * The column that answers the only question anyone asks about
                 * an old key: is something still using this?
                 *
                 * Written at most once a minute, so a key hammering the API and
                 * a key called twice an hour look the same here — which is
                 * correct, because the question is aliveness, not volume.
                 */
                TextColumn::make('last_used_at')
                    ->label('Last used')
                    ->since()
                    ->placeholder('never')
                    ->sortable(),

                TextColumn::make('expires_at')
                    ->label('Expires')
                    ->date()
                    ->placeholder('never'),

                TextColumn::make('created_at')->label('Created')->date()->toggleable(),
            ])
            ->recordActions([
                Action::make('abilities')
                    ->label('Abilities')
                    ->icon(Heroicon::OutlinedAdjustmentsHorizontal)
                    ->visible(fn (ApiToken $r) => $r->isUsable())
                    ->fillForm(fn (ApiToken $r) => ['abilities' => $r->abilities])
                    ->schema([self::abilityPicker()])
                    ->modalSubmitActionLabel('Save')
                    // Changing abilities rather than re-minting, because the
                    // realistic path is a key that drafted for a fortnight and
                    // has earned the right to publish — and rotating the secret
                    // to say so means editing it wherever it is deployed.
                    ->action(function (ApiToken $record, array $data): void {
                        $record->update(['abilities' => array_values($data['abilities'])]);

                        Notification::make()
                            ->title('Abilities updated')
                            ->body('Takes effect on the very next request.')
                            ->success()
                            ->send();
                    }),

                Action::make('revoke')
                    ->icon(Heroicon::OutlinedNoSymbol)
                    ->color('danger')
                    ->visible(fn (ApiToken $r) => $r->revoked_at === null)
                    ->requiresConfirmation()
                    ->modalHeading('Revoke this key')
                    ->modalDescription('It stops working immediately and cannot be un-revoked. Whatever is using it will start getting 401s.')
                    ->action(function (ApiToken $record): void {
                        $record->forceFill(['revoked_at' => now()])->save();

                        Notification::make()->title('Revoked')->success()->send();
                    }),

                /*
                 * Deleting is for tidying up, and only after revocation.
                 *
                 * A revoked row is the answer to "when did this stop working",
                 * which is the first thing anyone wants during an incident. A
                 * live key deleted outright would stop working *and* leave no
                 * trace that it had ever existed.
                 */
                DeleteAction::make()
                    ->visible(fn (ApiToken $r) => $r->revoked_at !== null)
                    ->modalDescription('The key is already revoked. This only removes the record of it.'),
            ])
            ->defaultSort('id', 'desc');
    }

    public static function getPages(): array
    {
        return ['index' => ListApiTokens::route('/')];
    }
}
