<?php

declare(strict_types=1);

namespace App\Filament\Resources\FeatureIdeas;

use App\Enums\FeatureStatus;
use App\Enums\ModerationStatus;
use App\Filament\Resources\FeatureIdeas\Pages\CreateFeatureIdea;
use App\Filament\Resources\FeatureIdeas\Pages\EditFeatureIdea;
use App\Filament\Resources\FeatureIdeas\Pages\ListFeatureIdeas;
use App\Models\FeatureIdea;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use UnitEnum;

/**
 * The ideas on the contribute page, and the queue of visitors' suggestions
 * (docs/features/contribute.md).
 *
 * ## Moderation is a person pressing Publish
 *
 * A visitor's suggestion arrives `pending` and is invisible to everybody but
 * its author until it is published here. No AI reads it first: the queue sees
 * a handful of rows a week, and the site's rule is that AI never runs in a
 * web request. The navigation badge counts what is waiting.
 *
 * ## Four languages, filled in here
 *
 * A suggestion arrives in its author's language only. The public page falls
 * back to that language until the others are filled in, so publishing does
 * not have to wait for a translation.
 *
 * ## Editing a shipped idea keeps it yours
 *
 * Saving a row that came from resources/content/feature-ideas.php turns its
 * `source` from `seed` to `owner`, and `bc:seed-feature-ideas` never rewrites
 * an `owner` row. To take a shipped idea off the board, reject it rather than
 * deleting it: a deleted row is recreated by the next seed.
 */
class FeatureIdeaResource extends Resource
{
    protected static ?string $model = FeatureIdea::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedLightBulb;

    protected static string|UnitEnum|null $navigationGroup = 'Community';

    protected static ?string $navigationLabel = 'Feature ideas';

    protected static ?string $modelLabel = 'feature idea';

    public static function getNavigationBadge(): ?string
    {
        $waiting = FeatureIdea::query()->where('moderation', ModerationStatus::Pending->value)->count();

        return $waiting === 0 ? null : (string) $waiting;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    public static function form(Schema $schema): Schema
    {
        $languages = [
            'nl' => 'Nederlands',
            'en' => 'English',
            'fr' => 'Français',
            'es' => 'Español',
        ];

        $sections = [
            Section::make('On the board')
                ->schema([
                    Select::make('status')
                        ->options(FeatureStatus::options())
                        ->required()
                        ->default(FeatureStatus::Considering->value),

                    Select::make('moderation')
                        ->label('Visible')
                        ->options([
                            ModerationStatus::Published->value => 'Published: on the page',
                            ModerationStatus::Pending->value => 'Waiting: only its author sees it',
                            ModerationStatus::Rejected->value => 'Rejected: nobody sees it',
                        ])
                        ->required()
                        ->default(ModerationStatus::Published->value),

                    Select::make('language')
                        ->label('Written in')
                        ->options($languages)
                        ->required()
                        ->default('nl')
                        ->live()
                        ->helperText('Shown in this language wherever another is left empty.'),

                    TextInput::make('sort')
                        ->numeric()
                        ->default(0)
                        ->helperText('Lower comes first among ideas with the same status. Votes decide among "considering".'),
                ])
                ->columns(2),
        ];

        foreach ($languages as $code => $name) {
            $sections[] = Section::make($name)
                ->schema([
                    TextInput::make("title.{$code}")
                        ->label('Title')
                        ->maxLength(120)
                        // Required in the language it was written in: that is
                        // the fallback for all the others.
                        ->required(fn ($get): bool => $get('language') === $code),

                    Textarea::make("body.{$code}")
                        ->label('What it is')
                        ->rows(3)
                        ->maxLength(2000),
                ])
                ->collapsible();
        }

        return $schema->components($sections);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->withCount('votes')->with('suggester'))
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('title')
                    ->label('Idea')
                    ->state(fn (FeatureIdea $record): string => $record->text('title', 'nl'))
                    ->description(fn (FeatureIdea $record): string => str($record->text('body', 'nl'))->limit(140)->toString())
                    ->wrap(),

                TextColumn::make('status')->badge()->sortable(),

                TextColumn::make('moderation')->label('Visible')->badge()->sortable(),

                TextColumn::make('votes_count')->label('Votes')->sortable()->numeric(),

                TextColumn::make('source')
                    ->badge()
                    ->color('gray')
                    ->description(fn (FeatureIdea $record): ?string => $record->suggester?->email),

                TextColumn::make('language')->label('Written in')->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('created_at')->dateTime()->sortable()->label('Added'),
            ])
            ->filters([
                SelectFilter::make('moderation')
                    ->label('Visible')
                    ->options([
                        ModerationStatus::Pending->value => 'Waiting',
                        ModerationStatus::Published->value => 'Published',
                        ModerationStatus::Rejected->value => 'Rejected',
                    ]),

                SelectFilter::make('status')->options(FeatureStatus::options()),
            ])
            ->recordActions([
                Action::make('publish')
                    ->icon(Heroicon::OutlinedCheck)
                    ->color('success')
                    ->visible(fn (FeatureIdea $record): bool => $record->moderation !== ModerationStatus::Published)
                    ->action(fn (FeatureIdea $record) => $record->update([
                        'moderation' => ModerationStatus::Published->value,
                        'decided_at' => now(),
                    ])),

                // Kept, not deleted: a rejected suggestion is gone from every
                // page, and bc:prune-personal-data deletes it after a year.
                Action::make('reject')
                    ->icon(Heroicon::OutlinedXMark)
                    ->color('danger')
                    ->visible(fn (FeatureIdea $record): bool => $record->moderation === ModerationStatus::Pending)
                    ->action(fn (FeatureIdea $record) => $record->update([
                        'moderation' => ModerationStatus::Rejected->value,
                        'decided_at' => now(),
                    ])),

                EditAction::make(),

                DeleteAction::make()
                    ->modalDescription('Its votes go with it. A shipped idea comes back with the next bc:seed-feature-ideas; reject it instead to keep it off the page.'),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListFeatureIdeas::route('/'),
            'create' => CreateFeatureIdea::route('/create'),
            'edit' => EditFeatureIdea::route('/{record}/edit'),
        ];
    }
}
