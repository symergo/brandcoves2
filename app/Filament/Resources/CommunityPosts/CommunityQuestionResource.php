<?php

declare(strict_types=1);

namespace App\Filament\Resources\CommunityPosts;

use App\Enums\Market;
use App\Enums\ModerationStatus;
use App\Filament\Resources\CommunityPosts\Pages\ListCommunityQuestions;
use App\Models\CommunityQuestion;
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
 * The moderation queue for questions.
 *
 * `TriageCommunityPost` publishes the clean ones and refuses the obvious abuse;
 * everything it was unsure about, everything the flat screen held, and
 * everything posted while the model was switched off ends up here. So this
 * screen is the fallback that makes "AI decides" safe to say — with
 * `AI_ENABLED=false` it is not a fallback at all, it is the whole moderation
 * system, and the feature still works.
 *
 * Defaults to the pending queue rather than to everything. A moderation screen
 * whose first view is a thousand published rows is one where the eleven waiting
 * ones are invisible.
 *
 * There is no create and no edit. An admin decides yes or no about somebody
 * else's writing; rewriting it for them would put words in their mouth under
 * their name.
 */
class CommunityQuestionResource extends Resource
{
    protected static ?string $model = CommunityQuestion::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChatBubbleLeftRight;

    protected static string|UnitEnum|null $navigationGroup = 'Community';

    protected static ?string $navigationLabel = 'Questions';

    protected static ?string $modelLabel = 'question';

    /** The count that matters is the backlog, not the corpus. */
    public static function getNavigationBadge(): ?string
    {
        $waiting = CommunityQuestion::query()
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
        // Nothing is editable: see the class docblock.
        return $schema->components([]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('created_at')->dateTime()->sortable()->label('Posted'),
                TextColumn::make('market')->badge()->sortable(),

                TextColumn::make('title')
                    ->searchable()
                    ->wrap()
                    // The body underneath, because a question is judged on all
                    // of it and opening a record to read two sentences is how a
                    // queue stops being worked.
                    ->description(fn (CommunityQuestion $r) => $r->body === null
                        ? null
                        : mb_strimwidth($r->body, 0, 240, '…')),

                TextColumn::make('author.email')->label('By')->searchable()->toggleable(),

                // Colour and label both come from the enum, which implements
                // Filament's HasColor and HasLabel. A closure here would receive
                // the enum case rather than a string, because the model casts
                // the column — which is what used to 500 this page.
                TextColumn::make('status')->badge(),

                // Why the job held or refused it. The single most useful column
                // here: it turns "read this from scratch" into "check this one
                // judgement".
                TextColumn::make('moderation_note')->label('Flagged')->placeholder('—')->toggleable(),

                TextColumn::make('answers_count')->label('Answers')->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options(collect(ModerationStatus::cases())
                        ->mapWithKeys(fn (ModerationStatus $s) => [$s->value => $s->label()])->all())
                    // The queue, on arrival.
                    ->default(ModerationStatus::Pending->value),

                SelectFilter::make('market')
                    ->options(collect(Market::cases())
                        ->mapWithKeys(fn (Market $m) => [$m->value => $m->label()])->all()),
            ])
            ->recordActions([
                Action::make('publish')
                    ->icon(Heroicon::OutlinedCheck)
                    ->color('success')
                    ->visible(fn (CommunityQuestion $r) => ! $r->status->isPublished())
                    ->requiresConfirmation()
                    ->action(fn (CommunityQuestion $r) => $r->publish()),

                Action::make('refuse')
                    ->icon(Heroicon::OutlinedXMark)
                    ->color('danger')
                    ->visible(fn (CommunityQuestion $r) => $r->status !== ModerationStatus::Rejected)
                    ->requiresConfirmation()
                    // Kept rather than deleted: a rejected post is the evidence
                    // for why an account was warned, and deleting it makes every
                    // moderation decision unauditable.
                    ->action(fn (CommunityQuestion $r) => $r->refuse('admin')),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => ListCommunityQuestions::route('/')];
    }
}
