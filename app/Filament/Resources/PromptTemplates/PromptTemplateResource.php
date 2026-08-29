<?php

declare(strict_types=1);

namespace App\Filament\Resources\PromptTemplates;

use App\Filament\Resources\PromptTemplates\Pages\ListPromptTemplates;
use App\Models\PromptTemplate;
use App\Services\Ai\PromptBank;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;
use InvalidArgumentException;
use UnitEnum;

/**
 * What the writer is told, editable without a deploy.
 *
 * Every prompt used to be a heredoc, so changing the editorial voice was a code
 * change and a redeploy — and the person with an opinion about the voice is not
 * the person with Coolify open.
 *
 * ## The table is empty until somebody writes something
 *
 * A slot with no row uses the prompt the application shipped with. That is why
 * this screen lists *slots* rather than rows, and says which of them are
 * overridden: a list of three rows would suggest the other five prompts do not
 * exist.
 *
 * ## What cannot be edited here, and why the page says so
 *
 * The link-token contract is appended in code after whatever is written here. An
 * edited system prompt that dropped it would stop every `[[product:…]]` being
 * produced, and the only symptom would be articles quietly losing their internal
 * links on a site whose whole argument is comparison.
 */
class PromptTemplateResource extends Resource
{
    protected static ?string $model = PromptTemplate::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChatBubbleBottomCenterText;

    protected static string|UnitEnum|null $navigationGroup = 'Operations';

    protected static ?string $navigationLabel = 'Prompts';

    protected static ?string $modelLabel = 'prompt';

    protected static ?string $recordTitleAttribute = 'slot';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Which prompt')
                ->schema([
                    Select::make('slot')
                        ->options(PromptBank::slots())
                        ->required()
                        ->live()
                        ->unique(ignoreRecord: true)
                        /*
                         * Both fields are filled with the shipped prompt the
                         * moment a slot is chosen.
                         *
                         * An editor handed an empty textarea and asked to write
                         * a system prompt writes a *different* one, not a
                         * modified one — losing the rules that stop the model
                         * inventing prices and naming products that are not on
                         * the page. Starting from the shipped text makes the
                         * small edit the easy one.
                         *
                         * Only when the field is empty, so re-picking a slot
                         * while editing cannot silently discard someone's work.
                         */
                        ->afterStateUpdated(function ($state, $set, $get): void {
                            $shipped = PromptBank::shipped((string) $state);

                            foreach (['system', 'user_template'] as $field) {
                                if (blank($get($field))) {
                                    $set($field, $shipped[$field]);
                                }
                            }
                        })
                        ->helperText('One override per slot. A slot with no row uses the prompt the site shipped with.'),

                    Toggle::make('enabled')
                        ->default(true)
                        ->helperText('Off means the shipped prompt is used, without losing what is written here.'),
                ])
                ->columns(2),

            Section::make('The rules and the voice')
                ->description('Appended to, never replacing, the link-token contract — that is added in code so an edit here cannot stop the writer producing internal links.')
                ->schema([
                    Textarea::make('system')
                        ->label('System prompt')
                        ->rows(16)
                        ->columnSpanFull()
                        ->default(fn ($get) => PromptBank::shipped((string) $get('slot'))['system'])
                        ->helperText('Pre-filled with the prompt the site ships with — edit it. Clear it entirely to go back to that prompt.'),
                ])
                ->footerActions([
                    Action::make('resetSystem')
                        ->label('Start again from the shipped prompt')
                        ->icon(Heroicon::OutlinedArrowUturnLeft)
                        ->color('gray')
                        ->action(fn ($set, $get) => $set('system', PromptBank::shipped((string) $get('slot'))['system'])),
                ]),

            Section::make('The brief')
                ->description(fn ($get) => 'Composed from named blocks. Available here: '
                    .self::placeholderHelp((string) $get('slot')))
                ->schema([
                    Textarea::make('user_template')
                        ->label('User prompt')
                        ->rows(14)
                        ->columnSpanFull()
                        ->default(fn ($get) => PromptBank::shipped((string) $get('slot'))['user_template'])
                        /*
                         * The most valuable thing on the screen.
                         *
                         * A template is assembled from data, so one that has
                         * lost {finds} asks the model to write about nothing —
                         * and a model asked to write about nothing writes a
                         * plausible article about products that are not on the
                         * page. Rejected at save rather than discovered at
                         * 06:00.
                         */
                        ->rule(fn ($get) => function (string $attribute, mixed $value, callable $fail) use ($get): void {
                            try {
                                PromptBank::validate((string) $get('slot'), is_string($value) ? $value : null);
                            } catch (InvalidArgumentException $e) {
                                $fail($e->getMessage());
                            }
                        })
                        ->helperText('Pre-filled with the shipped layout. An empty block leaves no gap behind; clear the field entirely to go back to the shipped one.'),

                    Textarea::make('notes')
                        ->label('Why this was changed')
                        ->rows(2)
                        ->columnSpanFull(),
                ])
                ->footerActions([
                    Action::make('resetUser')
                        ->label('Start again from the shipped layout')
                        ->icon(Heroicon::OutlinedArrowUturnLeft)
                        ->color('gray')
                        ->action(fn ($set, $get) => $set('user_template', PromptBank::shipped((string) $get('slot'))['user_template'])),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('slot')
                    ->badge()
                    ->sortable()
                    ->description(fn (PromptTemplate $r) => PromptBank::slots()[$r->slot] ?? 'no longer used'),

                IconColumn::make('enabled')->boolean(),

                TextColumn::make('system')
                    ->label('Rules')
                    ->formatStateUsing(fn (?string $state) => filled($state) ? 'overridden' : 'shipped')
                    ->badge()
                    ->color(fn (?string $state) => filled($state) ? 'warning' : 'gray'),

                TextColumn::make('user_template')
                    ->label('Brief')
                    ->formatStateUsing(fn (?string $state) => filled($state) ? 'overridden' : 'shipped')
                    ->badge()
                    ->color(fn (?string $state) => filled($state) ? 'warning' : 'gray'),

                TextColumn::make('author.email')->label('By')->toggleable(),
                TextColumn::make('updated_at')->dateTime()->sortable(),
            ])
            ->headerActions([
                CreateAction::make()
                    ->label('Override a prompt')
                    ->mutateDataUsing(function (array $data): array {
                        $data['author_id'] = Auth::id();

                        return $data;
                    }),
            ])
            ->recordActions([
                EditAction::make(),

                Action::make('reset')
                    ->label('Reset to shipped')
                    ->icon(Heroicon::OutlinedArrowUturnLeft)
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalDescription('Deletes this override. The slot goes back to the prompt the site shipped with, and what is written here is not recoverable.')
                    ->action(function (PromptTemplate $record): void {
                        // Deleting *is* the undo, exactly as clearing a value in
                        // AI settings is. There is no third state between "the
                        // shipped prompt" and "mine".
                        $record->delete();

                        Notification::make()->title('Back to the shipped prompt')->success()->send();
                    }),
            ])
            ->emptyStateHeading('Every prompt is the one the site shipped with')
            ->emptyStateDescription('Override one only when you want it to say something different. An empty table is the normal state.')
            ->defaultSort('slot');
    }

    private static function placeholderHelp(string $slot): string
    {
        if (! array_key_exists($slot, PromptBank::slots())) {
            return 'pick a slot first.';
        }

        ['allowed' => $allowed, 'required' => $required] = PromptBank::placeholders($slot);

        return '{'.implode('}, {', $allowed).'}. Required: {'.implode('}, {', $required).'}.';
    }

    public static function getPages(): array
    {
        return ['index' => ListPromptTemplates::route('/')];
    }
}
