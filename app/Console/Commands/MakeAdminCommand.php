<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;

/**
 * Grant or revoke admin access.
 *
 * Exists because `is_admin` is deliberately not mass-assignable — no request
 * payload can ever grant it, which also means `update(['is_admin' => true])`
 * silently does nothing. This is the supported path.
 */
class MakeAdminCommand extends Command
{
    protected $signature = 'bc:make-admin {email} {--revoke : Remove admin access instead}';

    protected $description = 'Grant (or revoke) admin panel access for a user';

    public function handle(): int
    {
        $email = (string) $this->argument('email');

        // Case-insensitive, matching the unique index on lower(email).
        $user = User::query()->whereRaw('lower(email) = ?', [mb_strtolower($email)])->first();

        if ($user === null) {
            $this->error("No user with email \"{$email}\". They must sign in once first.");

            return self::FAILURE;
        }

        $grant = ! $this->option('revoke');
        $user->forceFill(['is_admin' => $grant])->save();

        $this->info($grant
            ? "{$user->email} can now reach /admin."
            : "{$user->email} no longer has admin access.");

        return self::SUCCESS;
    }
}
