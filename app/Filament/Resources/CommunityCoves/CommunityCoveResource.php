<?php

declare(strict_types=1);

namespace App\Filament\Resources\CommunityCoves;

use App\Enums\Market;
use App\Filament\Resources\CommunityCoves\Pages\ListCommunityCoves;
use App\Models\Wishlist;
use App\Services\Cove\CommunityCoves;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/**
 * Community Coves: lists people published. The safety valve.
 *
 * A list goes on the site the moment its owner publishes it (see
 * docs/features/community-coves.md for why there is no queue in front of it),
 * so this screen is where an admin takes one down: **Hide** removes it from
 * the page, the listing, the Gift Finder and everyone's saved view at once,
 * and the owner cannot republish it. **Show again** undoes a mistake.
 *
 * Reports arrive in Feedback, with the Cove's address as the page they were
 * on; the Cove's owner is never told who reported it.
 *
 * Nothing about the list's private side is shown here either: no recipient
 * name, no notes, no claims. The admin judges what the public sees.
 */
class CommunityCoveResource extends Resource
{
    protected static ?string $model = Wishlist::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUserGroup;

    protected static string|UnitEnum|null $navigationGroup = 'Community';

    protected static ?string $navigationLabel = 'Community Coves';

    protected static ?string $modelLabel = 'Community Cove';

    protected static ?string $pluralModelLabel = 'Community Coves';

    protected static ?string $slug = 'community-coves';

    /** Every list that has ever been published; a hidden one stays in view. */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->whereNotNull('public_slug')
            ->with('owner')
            ->withCount('saves');
    }

    public static function form(Schema $schema): Schema
    {
        // Nothing to edit: an admin hides a Cove, never rewrites somebody's.
        return $schema->components([]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('published_at', 'desc')
            ->columns([
                TextColumn::make('published_at')->dateTime()->sortable()->label('Published')->placeholder('Not published'),
                // `market` is cast to the enum on Wishlist, so the state is a
                // case, not a string; see ModerationStatus for the 500 that
                // taught this.
                TextColumn::make('market')->badge()->sortable()
                    ->formatStateUsing(fn ($state) => $state instanceof Market ? $state->value : (string) $state),
                TextColumn::make('public_title')->label('Public title')->wrap()->searchable(),
                TextColumn::make('owner.email')->label('Owner')->placeholder('—')->searchable()->toggleable(),
                TextColumn::make('saves_count')->label('Saved by')->sortable(),
                TextColumn::make('public_hidden_at')->dateTime()->label('Hidden')->placeholder('—'),
            ])
            ->filters([
                TernaryFilter::make('hidden')
                    ->label('Hidden')
                    ->placeholder('All')
                    ->trueLabel('Hidden')
                    ->falseLabel('On the site')
                    ->queries(
                        true: fn ($query) => $query->whereNotNull('public_hidden_at'),
                        false: fn ($query) => $query->whereNull('public_hidden_at')->whereNotNull('published_at'),
                        blank: fn ($query) => $query,
                    ),
                // From the enum, not from the rows; see FeedbackResource.
                SelectFilter::make('market')
                    ->options(collect(Market::cases())
                        ->mapWithKeys(fn (Market $m) => [$m->value => $m->label()])
                        ->all()),
            ])
            ->recordActions([
                Action::make('open')
                    ->icon(Heroicon::OutlinedArrowTopRightOnSquare)
                    ->color('gray')
                    ->visible(fn (Wishlist $row) => $row->isCommunityCove())
                    ->url(fn (Wishlist $row) => app(CommunityCoves::class)->url($row))
                    ->openUrlInNewTab(),

                Action::make('hide')
                    ->icon(Heroicon::OutlinedEyeSlash)
                    ->color('danger')
                    ->requiresConfirmation()
                    ->visible(fn (Wishlist $row) => $row->public_hidden_at === null)
                    ->action(fn (Wishlist $row) => $row->forceFill(['public_hidden_at' => now()])->save()),

                Action::make('unhide')
                    ->label('Show again')
                    ->icon(Heroicon::OutlinedEye)
                    ->color('gray')
                    ->visible(fn (Wishlist $row) => $row->public_hidden_at !== null)
                    ->action(fn (Wishlist $row) => $row->forceFill(['public_hidden_at' => null])->save()),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => ListCommunityCoves::route('/')];
    }
}
