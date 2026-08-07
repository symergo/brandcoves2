<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Anonymise a restored production dump.
 *
 * MANDATORY after restoring production data onto a laptop. `users`,
 * `recipients` and `wishlists` hold real email addresses and personal notes
 * about real people's gifts, and this repo lives inside a Synology-synced
 * folder — that data has no business sitting there.
 *
 * Refuses to run against anything that is not demonstrably local.
 */
class ScrubDatabase extends Command
{
    protected $signature = 'bc:scrub {--force : Skip the confirmation prompt}';

    protected $description = 'Anonymise personal data in a locally restored production dump';

    public function handle(): int
    {
        try {
            $this->assertLocal();
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        if (! $this->option('force') && ! $this->confirm('This permanently destroys personal data in the local database. Continue?')) {
            return self::FAILURE;
        }

        DB::transaction(function (): void {
            // Deterministic per-id addresses: still unique (the lower(email)
            // index holds), still joinable, but not deliverable to a real person.
            DB::statement(<<<'SQL'
                UPDATE users
                SET email = 'user-' || id || '@scrubbed.invalid',
                    name  = 'Test User ' || id,
                    avatar_url = NULL
            SQL);

            DB::statement(<<<'SQL'
                UPDATE recipients
                SET name = 'Recipient ' || left(id::text, 8),
                    notes = NULL,
                    birthday = NULL,
                    share_token = gen_random_uuid()
            SQL);

            DB::statement(<<<'SQL'
                UPDATE wishlists
                SET description = NULL,
                    share_token = gen_random_uuid()
            SQL);

            DB::statement('UPDATE wishlist_items SET note = NULL');

            // Raw email addresses for logged-out alert subscribers.
            DB::statement('UPDATE price_alerts   SET email = NULL WHERE email IS NOT NULL');
            DB::statement('UPDATE restock_alerts SET email = NULL WHERE email IS NOT NULL');

            DB::statement('DELETE FROM notifications');
            DB::statement('DELETE FROM sessions');

            // Encrypted with the production key, which is not on this machine —
            // undecryptable noise here, and a liability if the key ever leaks.
            DB::statement('DELETE FROM connector_settings');
        });

        $this->info(sprintf(
            'Scrubbed: %d users, %d recipients, %d wishlists.',
            DB::table('users')->count(),
            DB::table('recipients')->count(),
            DB::table('wishlists')->count(),
        ));

        return self::SUCCESS;
    }

    private function assertLocal(): void
    {
        if (app()->environment('production')) {
            throw new RuntimeException('Refusing to scrub: APP_ENV=production.');
        }

        $host = (string) config('database.connections.pgsql.host');
        $local = ['localhost', '127.0.0.1', '::1', 'postgres', 'host.docker.internal'];

        if (! in_array($host, $local, true)) {
            throw new RuntimeException(
                "Refusing to scrub: DB host \"{$host}\" is not local. ".
                'This command destroys data and must never touch a remote database.'
            );
        }
    }
}
