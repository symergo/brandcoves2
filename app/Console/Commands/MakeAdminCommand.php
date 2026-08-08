<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;

/**
 * Create or promote an admin.
 *
 * The public site is passwordless — magic link or Google — but the Filament
 * panel authenticates with a password, so an admin needs one even though a
 * shopper never does. That asymmetry is why this exists rather than a row in a
 * seeder: an admin is created deliberately, one at a time, by someone who
 * already has the server.
 *
 * `is_admin` is guarded against mass assignment (see AdminPanelTest), so it is
 * set explicitly here. That guard is the reason a stray `User::create($request)`
 * cannot mint an administrator, and it should stay.
 */
class MakeAdminCommand extends Command
{
    protected $signature = 'bc:make-admin
        {email : The address to create or promote}
        {--password= : Read from BC_ADMIN_PASSWORD, or prompted for, if omitted}
        {--demote : Remove admin rights instead of granting them}';

    protected $description = 'Create or promote an administrator for /admin.';

    public function handle(): int
    {
        $email = (string) $this->argument('email');

        if (Validator::make(['email' => $email], ['email' => 'required|email'])->fails()) {
            $this->components->error('That is not a valid email address.');

            return self::FAILURE;
        }

        if ($this->option('demote')) {
            $user = User::query()->whereRaw('lower(email) = ?', [mb_strtolower($email)])->first();

            if ($user === null) {
                $this->components->error('No such user.');

                return self::FAILURE;
            }

            $user->forceFill(['is_admin' => false])->save();
            $this->components->info("{$email} is no longer an admin.");

            return self::SUCCESS;
        }

        /*
         * Never accept the password as a command-line argument by default.
         *
         * Anything in argv is visible in `ps` to every user on the box and
         * lands in shell history. An env var or a hidden prompt keeps it out of
         * both.
         */
        $password = $this->option('password')
            ?: env('BC_ADMIN_PASSWORD')
            ?: ($this->input->isInteractive() ? $this->secret('Password') : null);

        if (blank($password)) {
            $this->components->error('No password given. Set BC_ADMIN_PASSWORD or run interactively.');

            return self::FAILURE;
        }

        if (mb_strlen((string) $password) < 8) {
            $this->components->error('Use at least 8 characters.');

            return self::FAILURE;
        }

        // Case-insensitive lookup: the users table has a lower(email) unique
        // index, so matching any other way would let this create a duplicate
        // that the database then rejects.
        $user = User::query()->whereRaw('lower(email) = ?', [mb_strtolower($email)])->first()
            ?? new User(['email' => $email]);

        $user->forceFill([
            'email' => $email,
            // A name so the panel has something to show. The model falls back
            // to the email's local part anyway, but an admin created here is a
            // person somebody knows, and a real name beats a derived one.
            'name' => $user->name ?? Str::of($email)->before('@')->headline()->toString(),
            // Cast to `hashed` on the model, so this is not stored in the clear.
            'password' => $password,
            'is_admin' => true,
            'email_verified_at' => $user->email_verified_at ?? now(),
        ])->save();

        $this->components->info("{$email} can now sign in at /admin.");

        return self::SUCCESS;
    }
}
