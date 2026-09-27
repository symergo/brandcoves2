<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\ShareCode;
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
                    avatar_url = NULL,
                    -- A real date of birth, and the one column here that is
                    -- special-category-adjacent rather than merely identifying.
                    birthday = NULL
            SQL);

            /*
             * Friendships and the birthdays people wrote down about each other.
             *
             * The graph itself is personal data — who knows whom — and the
             * dates on it are second-hand facts about people who never typed
             * them here. Deleted rather than anonymised: nothing joins to a
             * friendship, and a scrubbed one would be a random pair of test
             * users pretending to know each other.
             */
            DB::statement('DELETE FROM friendships');
            DB::statement('DELETE FROM friend_invites');

            // Invitation emails (2026-09-26): who invited which hashed address,
            // and who complained about whom. Hashes, but linked to real
            // accounts, and nothing on a laptop needs them.
            DB::statement('DELETE FROM friend_invite_mails');
            DB::statement('DELETE FROM invite_complaints');
            DB::statement('DELETE FROM invite_suppressions');
            // The invitation buttons: a real address each, and live ones
            // would sign somebody in on a laptop.
            DB::statement('DELETE FROM friend_invite_tokens');

            /*
             * Feature suggestions no one but their author and the admin has
             * seen (docs/features/contribute.md): free text from a real
             * account, waiting or rejected. Published ideas are already public
             * and stay, so the board looks like production; their votes point
             * at the scrubbed accounts above.
             */
            DB::statement("DELETE FROM feature_ideas WHERE source = 'visitor' AND moderation <> 'published'");

            // Thumbs on Find a gift's ideas (2026-09-27): what an owner said
            // about ideas for a real person, and one-way codes of real
            // visitors. Nothing on a laptop needs either.
            DB::statement('DELETE FROM recipient_feedback');
            DB::statement('DELETE FROM gift_votes');

            DB::statement(<<<'SQL'
                UPDATE recipients
                SET name = 'Recipient ' || left(id::text, 8),
                    notes = NULL,
                    birthday = NULL,
                    share_token = gen_random_uuid()
            SQL);

            DB::statement('UPDATE wishlists SET description = NULL');

            /*
             * A fresh share code per list, minted in PHP.
             *
             * `gen_random_uuid()` used to do this, and the column is not a uuid
             * any more — see App\Support\ShareCode. Rotating them is the point:
             * a production dump on a laptop must not carry links that still
             * open the real lists.
             */
            foreach (DB::table('wishlists')->pluck('id') as $id) {
                DB::table('wishlists')->where('id', $id)->update(['share_token' => ShareCode::make()]);
            }

            DB::statement('UPDATE wishlist_items SET note = NULL');

            /*
             * This or that together links and gift profile cards: their tokens
             * open real pages, and a card may carry the name its maker typed.
             * `left(md5(...))` rather than ShareCode: these only have to stop
             * matching production, not be pleasant to read.
             */
            DB::statement('UPDATE taste_invites SET token = left(md5(random()::text || id::text), 16)');
            DB::statement('UPDATE gift_profile_cards SET token = left(md5(random()::text || id::text), 16), name = NULL');

            /*
             * Secret Santa members: real names and email addresses, typed in by
             * people who never made an account here.
             *
             * The assignment goes too. It is encrypted with the production key,
             * which is not on this machine, so it is undecryptable noise —
             * and a pairing is exactly the kind of thing that should not survive
             * a copy onto a laptop in a synced folder.
             */
            DB::statement(<<<'SQL'
                UPDATE secret_santa_members
                SET email = 'santa-' || id || '@scrubbed.invalid',
                    display_name = 'Member ' || id,
                    assigned_member_id = NULL,
                    exclusions = '[]'::jsonb,
                    join_token = gen_random_uuid()
            SQL);

            // `::text`: the column stopped being a uuid on 2026-09-13.
            DB::statement('UPDATE secret_santa_groups SET invite_token = gen_random_uuid()::text');

            // Raw email addresses for logged-out alert subscribers.
            DB::statement('UPDATE price_alerts   SET email = NULL WHERE email IS NOT NULL');
            DB::statement('UPDATE restock_alerts SET email = NULL WHERE email IS NOT NULL');

            /*
             * Cove subscribers: deleted, not anonymised.
             *
             * Everything else here keeps its row because something joins to it.
             * Nothing joins to a subscriber, and the table is a mailing list —
             * the one shape of data where a laptop copy could actually send mail
             * to real people if a misconfigured MAIL_MAILER ever pointed at a
             * real transport. There is nothing here worth keeping locally.
             */
            DB::statement('DELETE FROM cove_subscribers');

            DB::statement('DELETE FROM notifications');
            DB::statement('DELETE FROM sessions');

            // Encrypted with the production key, which is not on this machine —
            // undecryptable noise here, and a liability if the key ever leaks.
            DB::statement('DELETE FROM connector_settings');

            /*
             * Editorial API keys.
             *
             * Only hashes, so a copy is not a usable credential — but the row is
             * what makes a key work, and leaving production's rows in a laptop
             * database means a production key authenticates against local data
             * and a local key does not exist. Both directions are confusing and
             * neither is useful. Mint a local one with `bc:api-token`.
             */
            DB::statement('DELETE FROM api_tokens');
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
