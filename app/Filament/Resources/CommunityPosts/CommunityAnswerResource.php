<?php

declare(strict_types=1);

namespace App\Filament\Resources\CommunityPosts;

use App\Enums\ModerationStatus;
use App\Filament\Resources\CommunityPosts\Pages\ListCommunityAnswers;
use App\Models\CommunityAnswer;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use UnitEnum;

/**
 * The moderation queue for answers.
 *
 * A separate screen from questions rather than one combined list. They are read
 * differently: a question is judged on its own, and an answer only makes sense
 * against the question it is answering — which is why the question's title is a
 * column here and there is no equivalent going the other way.
 *
 * Publishing an answer moves `community_questions.answers_count`, which is
 * maintained by the model's own events rather than here. One place, so the
 * admin and the triage job cannot disagree about the number on the board.
 */
class CommunityAnswerResource extends Resource
{
    protected static ?string $model = CommunityAnswer::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChatBubbleBottomCenterText;

    protected static string|UnitEnum|null $navigationGroup = 'Community';

    protected static ?string $navigationLabel = 'Answers';

    protected static ?string $modelLabel = 'answer';

    public static function getNavigationBadge(): ?string
    {
        $waiting = CommunityAnswer::query()
            ->where('status', ModerationStatus::Pending->value)
            ->count();

        return $waiting === 0 ? null : (string) $waiting;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('created_at')->dateTime()->sortable()->label('Posted'),

                // What it is answering. An answer read without its question is
                // not judgeable.
                TextColumn::make('question.title')->label('On')->limit(50)->searchable(),

                TextColumn::make('body')->wrap()->limit(240)->searchable(),

                TextColumn::make('author.email')->label('By')->searchable()->toggleable(),

                // Products carried by the answer. A pick cannot be a hostile
                // link — it is a catalogue id — so this is a quality signal
                // rather than a risk: an answer with products is the kind the
                // board exists for.
                TextColumn::make('picks_count')->counts('picks')->label('Picks'),

                // Colour and label both come from the enum, which implements
                // Filament's HasColor and HasLabel. A closure here would receive
                // the enum case rather than a string, because the model casts
                // the column — which is what used to 500 this page.
                TextColumn::make('status')->badge(),

                TextColumn::make('moderation_note')->label('Flagged')->placeholder('—')->toggleable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options(collect(ModerationStatus::cases())
                        ->mapWithKeys(fn (ModerationStatus $s) => [$s->value => $s->label()])->all())
                    ->default(ModerationStatus::Pending->value),
            ])
            ->recordActions([
                Action::make('publish')
                    ->icon(Heroicon::OutlinedCheck)
                    ->color('success')
                    ->visible(fn (CommunityAnswer $r) => ! $r->status->isPublished())
                    ->requiresConfirmation()
                    ->action(fn (CommunityAnswer $r) => $r->publish()),

                Action::make('refuse')
                    ->icon(Heroicon::OutlinedXMark)
                    ->color('danger')
                    ->visible(fn (CommunityAnswer $r) => $r->status !== ModerationStatus::Rejected)
                    ->requiresConfirmation()
                    ->action(fn (CommunityAnswer $r) => $r->refuse('admin')),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => ListCommunityAnswers::route('/')];
    }
}
