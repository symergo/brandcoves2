<?php

declare(strict_types=1);

namespace App\Filament\Resources\Users;

use App\Filament\Resources\Users\Pages\EditUser;
use App\Filament\Resources\Users\Pages\ListUsers;
use App\Filament\Resources\Users\Schemas\UserForm;
use App\Filament\Resources\Users\Tables\UsersTable;
use App\Models\User;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

/**
 * The people who have an account.
 *
 * ## What this screen is for
 *
 * Three things, in the order they come up: finding a person by their address
 * when they write in, deciding who may open this panel, and deleting an account
 * when somebody asks for that. Everything else about an account — lists,
 * recipients, friends, birthdays — belongs to the person and stays out of the
 * form. An administrator can see how much of it there is, and nothing more.
 *
 * ## No create page
 *
 * An account here is a proven email address: the magic link or Google sign-in
 * is the proof. A row typed into a form is an address nobody has demonstrated
 * they own, which is also how a typo becomes an account that receives somebody
 * else's reminders. The one account that has to exist before anybody can sign
 * in is the first admin, and `bc:make-admin` makes that one from the server.
 *
 * ## Admin rights are set here and nowhere else in the app
 *
 * `is_admin` is deliberately not mass-assignable — AdminPanelTest pins that —
 * so the edit page writes it with `forceFill`. Two guards on top: nobody can
 * take their own admin flag away (the last admin locking themselves out is
 * recoverable only from a shell), and turning it on asks for a panel password,
 * because the site signs people in without one and the panel does not.
 */
class UserResource extends Resource
{
    protected static ?string $model = User::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUsers;

    // With the API keys: who may open this panel is access management, the
    // same concern as which key may publish.
    protected static string|UnitEnum|null $navigationGroup = 'Operations';

    protected static ?string $navigationLabel = 'Accounts';

    protected static ?string $modelLabel = 'account';

    protected static ?string $recordTitleAttribute = 'email';

    public static function form(Schema $schema): Schema
    {
        return UserForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return UsersTable::configure($table);
    }

    /** See the class docblock: an account is a proven address, not a form. */
    public static function canCreate(): bool
    {
        return false;
    }

    public static function getPages(): array
    {
        return [
            'index' => ListUsers::route('/'),
            'edit' => EditUser::route('/{record}/edit'),
        ];
    }
}
