<?php

declare(strict_types=1);

namespace App\Filament\Resources\Guides;

use App\Enums\PublishStatus;
use App\Filament\Resources\Guides\Pages\EditGuide;
use App\Filament\Resources\Guides\Pages\ListGuides;
use App\Models\Guide;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use UnitEnum;

/**
 * Buying guides, after the builder has written them.
 *
 * Editable rather than read-only on purpose: the shortlist is chosen by us and
 * the prose by a model, and the prose is the part that occasionally needs a
 * human. Publishing generated copy with no way to fix a sentence is how a site
 * ends up with a guide it cannot defend.
 *
 * `source_volume` is shown because it is the honest answer to "why does this
 * guide exist" — it was written because that many people searched for it here.
 */
class GuideResource extends Resource
{
    protected static ?string $model = Guide::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBookOpen;

    protected static string|UnitEnum|null $navigationGroup = 'Content';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Copy')
                ->schema([
                    TextInput::make('title')->required()->maxLength(160),
                    Textarea::make('intro')->rows(3),
                    Textarea::make('body_md')
                        ->rows(8)
                        ->label('How to choose')
                        // Rendered as plain paragraphs, never as Markdown: the
                        // copy comes from a language model, and the one thing
                        // you never do with model output is hand it to
                        // something that interprets markup.
                        ->helperText('Plain text. Blank lines separate paragraphs; markup is not rendered.'),
                ]),

            Section::make('Publishing')
                ->schema([
                    Select::make('status')
                        ->options(collect(PublishStatus::cases())
                            ->mapWithKeys(fn ($c) => [$c->value => $c->value])->all())
                        ->required(),

                    TextInput::make('meta_description')->maxLength(160),
                    TextInput::make('focus_keyphrase')->disabled(),
                ])
                ->columns(3),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('title')->searchable()->limit(50),
                TextColumn::make('market')->badge()->sortable(),
                TextColumn::make('status')->badge()->sortable(),

                TextColumn::make('items_count')
                    ->counts('items')
                    ->label('Products'),

                // Why it exists. A fact no competitor has.
                TextColumn::make('source_volume')
                    ->label('Searches')
                    ->numeric()
                    ->sortable()
                    ->description('30-day volume at generation'),

                TextColumn::make('published_at')->dateTime()->sortable(),
            ])
            ->filters([
                SelectFilter::make('market'),
                SelectFilter::make('status'),
            ])
            ->recordActions([
                EditAction::make(),

                Action::make('view')
                    ->url(fn (Guide $record) => url("/{$record->market->value}/guides/{$record->slug}"))
                    ->openUrlInNewTab()
                    ->icon(Heroicon::OutlinedArrowTopRightOnSquare),

                Action::make('unpublish')
                    ->requiresConfirmation()
                    ->visible(fn (Guide $record) => $record->status === PublishStatus::Published)
                    ->action(function (Guide $record): void {
                        // Draft rather than delete: an unpublished guide keeps
                        // its slug, so re-publishing it later does not mint a
                        // second URL for the same topic.
                        $record->update(['status' => PublishStatus::Draft->value]);

                        Notification::make()->title('Unpublished')->success()->send();
                    }),
            ])
            ->toolbarActions([BulkActionGroup::make([DeleteBulkAction::make()])])
            ->defaultSort('published_at', 'desc');
    }

    public static function getPages(): array
    {
        return [
            'index' => ListGuides::route('/'),
            'edit' => EditGuide::route('/{record}/edit'),
        ];
    }
}
