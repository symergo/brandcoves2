<?php

declare(strict_types=1);

use App\Enums\Market;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;

/**
 * People who want the Daily Cove in their inbox.
 *
 * ## Double opt-in, not a checkbox
 *
 * A row is created unconfirmed and receives exactly one email: the confirmation.
 * Nothing else is sent until `confirmed_at` is set.
 *
 * Two reasons, and the legal one is the less interesting of them. Under GDPR
 * consent must be freely given and demonstrable, and a confirmation click is the
 * only evidence that survives a complaint. The operational reason matters more
 * day to day: a form anyone can type any address into is a way to send mail to
 * people who never asked, and the first time that happens at volume the domain's
 * sending reputation is gone. Recovering one takes months; not losing it costs a
 * click.
 *
 * ## Separate from `users`
 *
 * A subscriber is not an account. Most will never make one, and requiring signup
 * to receive a daily email is how you lose the subscription — the same reasoning
 * that lets wishlists work before signup.
 *
 * ## Tokens
 *
 * Two, with different lifetimes and different jobs:
 *
 *  - `confirm_token` is single-use and expires. It is cleared on confirmation, so
 *    a leaked link from an old mailbox cannot re-confirm a cancelled address.
 *  - `unsubscribe_token` is permanent and never rotates. It has to keep working
 *    in the footer of an email sent three years ago; an expiring unsubscribe link
 *    is an unsubscribe link that fails exactly when someone is annoyed enough to
 *    use it.
 *
 * ## Personal data
 *
 * Email addresses. `bc:scrub` must clear this table on any production restore —
 * this repository sits in a Synology-synced folder.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cove_subscribers', function (Blueprint $table) {
            $table->id();

            // The edition is per market, so the subscription is too. The same
            // person may legitimately want two.
            $table->string('market');
            $table->string('email');

            $table->timestampTz('confirmed_at')->nullable();
            $table->timestampTz('unsubscribed_at')->nullable();

            $table->string('confirm_token', 64)->nullable()->unique();
            $table->timestampTz('confirm_sent_at')->nullable();
            $table->string('unsubscribe_token', 64)->unique();

            // What we can show a regulator, and what tells us a form is being
            // abused. Not used for anything else.
            $table->string('signup_ip', 45)->nullable();
            $table->string('signup_source')->nullable();

            // Guards against double-sending when a job is retried.
            $table->date('last_sent_on')->nullable();
            $table->integer('sent_count')->default(0);

            $table->timestamps();

            /*
             * One row per address per market.
             *
             * Not globally unique: nl-nl and be-nl are different editions, and a
             * reader near the border may want both. Re-subscribing after
             * unsubscribing updates this row rather than creating a second one,
             * which is what keeps `unsubscribed_at` meaningful.
             */
            $table->unique(['market', 'email']);
            $table->index(['market', 'confirmed_at']);
        });

        $markets = implode(',', array_map(
            fn (string $m) => "'{$m}'",
            Market::values(),
        ));

        // String plus CHECK, never a native PG enum: altering one cannot run
        // inside a transaction, which makes every future value a deploy hazard.
        DB::statement("ALTER TABLE cove_subscribers ADD CONSTRAINT cove_subscribers_market_check CHECK (market IN ({$markets}))");
    }

    public function down(): void
    {
        Schema::dropIfExists('cove_subscribers');
    }
};
