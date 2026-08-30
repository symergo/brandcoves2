<?php

declare(strict_types=1);

use App\Enums\Market;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Somewhere for a visitor to say what is wrong.
 *
 * ## Why a table and not an email
 *
 * A mailto: link loses everything the sender did not think to type — which
 * market they were in, which page they were on — and it fails silently for
 * anyone without a mail client configured, which on a phone is most people. It
 * also cannot be rate limited.
 *
 * ## Why the email column is nullable, and why that matters
 *
 * Feedback is worth having anonymously. Requiring an address turns a
 * thirty-second report into a decision about giving a stranger your email, and
 * the reports that get abandoned there are exactly the ones from people who are
 * annoyed enough to be useful.
 *
 * When it *is* given it is personal data with no other purpose than replying,
 * so it is covered by `bc:prune-personal-data` and named in the privacy policy.
 * The message body is retained on the same clock, because a free-text field is
 * whatever the person typed into it — including, sometimes, their own name.
 *
 * ## What is deliberately not stored
 *
 * No IP address and no user agent. Neither answers a question about the
 * feedback; both make this a log of who complained.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('feedback', function (Blueprint $table) {
            $table->id();

            // Which catalogue they were looking at. A report about prices means
            // something different in `be-nl` than in `es`.
            $table->string('market');

            $table->text('message');

            // Null when they did not want a reply, which is most of the time.
            $table->string('email')->nullable();

            // Set when they were signed in. `nullOnDelete` because deleting an
            // account must not delete the bug report it left behind — the
            // report is about the site, and it is anonymous once the row is
            // gone.
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();

            /*
             * The path they were on when they opened the form, not a full URL.
             *
             * "/be-nl/p/1234/sony-wh-1000xm5" is the whole of what makes a
             * report actionable — "the price is wrong" with no page attached is
             * unanswerable. A path rather than a URL because the host adds
             * nothing and a query string can carry anything they typed
             * somewhere else.
             */
            $table->string('path')->nullable();

            // Cleared by whoever reads it. Not a status enum: the only two
            // states worth distinguishing are "somebody has seen this" and not.
            $table->timestamp('handled_at')->nullable();

            $table->timestamps();

            // The queue is read newest-first, per market.
            $table->index(['market', 'created_at']);
        });

        DB::statement(sprintf(
            'ALTER TABLE feedback ADD CONSTRAINT feedback_market_check CHECK (market IN (%s))',
            implode(',', array_map(fn (Market $m) => "'{$m->value}'", Market::cases())),
        ));
    }

    public function down(): void
    {
        Schema::dropIfExists('feedback');
    }
};
