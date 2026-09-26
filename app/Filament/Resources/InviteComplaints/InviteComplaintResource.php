<?php

declare(strict_types=1);

namespace App\Filament\Resources\InviteComplaints;

use App\Filament\Resources\InviteComplaints\Pages\ListInviteComplaints;
use App\Models\User;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use UnitEnum;

/**
 * Members whose invitation emails somebody called spam.
 *
 * One row per member, not per complaint: the question an admin has is "who
 * is sending invitations people did not want, and how many", and a row per
 * press would make them count. Complaints hold a hash of the complaining
 * address and nothing else, so there is nobody to show on that side.
 *
 * At `giftcoves.invites.complaint_limit` a member's invitations stop being
 * emailed (still recorded, still connecting people on sign-in). "Clear
 * complaints" lifts that, for when a look shows a misunderstanding rather than
 * a spammer. Nothing is sent to anybody either way.
 *
 * See docs/features/friend-invite-mail.md.
 */
class InviteComplaintResource extends Resource
{
    protected static ?string $model = User::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedEnvelopeOpen;

    protected static string|UnitEnum|null $navigationGroup = 'Community';

    protected static ?string $navigationLabel = 'Invitation complaints';

    protected static ?string $slug = 'invite-complaints';

    protected static ?string $modelLabel = 'member';

    public static function getEloquentQuery(): Builder
    {
        return User::query()
            ->whereHas('inviteComplaints')
            ->withCount('inviteComplaints')
            ->withMin('inviteComplaints', 'created_at')
            ->withMax('inviteComplaints', 'created_at')
            // What they sent in the log's window, for scale: three
            // complaints out of four invitations is not three out of forty.
            ->addSelect(['invites_sent' => DB::table('friend_invite_mails')
                ->selectRaw('count(*)')
                ->whereColumn('friend_invite_mails.inviter_id', 'users.id')]);
    }

    /** Members whose invitation emails are stopped right now. */
    public static function getNavigationBadge(): ?string
    {
        $stopped = User::query()
            ->has('inviteComplaints', '>=', (int) config('giftcoves.invites.complaint_limit', 3))
            ->count();

        return $stopped === 0 ? null : (string) $stopped;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'danger';
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('invite_complaints_max_created_at', 'desc')
            ->columns([
                TextColumn::make('email')->label('Member')->searchable()
                    ->description(fn (User $u) => $u->name),
                TextColumn::make('invite_complaints_count')->label('Complaints')->sortable(),
                IconColumn::make('stopped')
                    ->label('Emails stopped')
                    ->boolean()
                    ->state(fn (User $u) => (int) $u->getAttribute('invite_complaints_count')
                        >= (int) config('giftcoves.invites.complaint_limit', 3)),
                TextColumn::make('invites_sent')->label('Invitations, last 90 days'),
                TextColumn::make('invite_complaints_min_created_at')->label('First')->dateTime(),
                TextColumn::make('invite_complaints_max_created_at')->label('Latest')->dateTime()->sortable(),
                TextColumn::make('created_at')->label('Member since')->date()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->recordActions([
                Action::make('clear')
                    ->label('Clear complaints')
                    ->icon(Heroicon::OutlinedArrowUturnLeft)
                    ->color('gray')
                    ->requiresConfirmation()
                    ->modalDescription('Their invitations will be emailed again. The addresses that asked for no invitations stay on the list.')
                    ->action(fn (User $u) => $u->inviteComplaints()->delete()),
            ]);
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getPages(): array
    {
        return ['index' => ListInviteComplaints::route('/')];
    }
}
