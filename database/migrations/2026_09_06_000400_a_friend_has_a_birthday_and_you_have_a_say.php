<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Birthdays, and what a friend gets to see.
 *
 * ## `users.birthday` — the date you tell people
 *
 * The one fact a gift site should know about a person and did not. A full date,
 * with a year, because it is **yours to give**: you are the only person who
 * gets to decide whether this site knows how old you are.
 *
 * ## `friendships.friend_birthday_day` / `_month` — the date you wrote down
 *
 * Yours, about them, and deliberately **no year**.
 *
 * The two-row design makes this free: each direction is one person's own
 * record. You may know your sister's birthday; she has not published one; that
 * is your note, and it must not become her account's answer or a date somebody
 * guessed would start appearing to everybody else as fact.
 *
 * The missing year is the point rather than a simplification. What anybody
 * needs from a friend's birthday is *when to buy something*, and that is a day
 * and a month. A year is somebody's age, recorded about them by a third party
 * who was not asked and may well be wrong, on a page other people read. If she
 * wants the year here she can put it on her own account, which is the only
 * place a year belongs.
 *
 * Two smallints rather than a string or a date with a sentinel year: a sentinel
 * is a lie that leaks the moment somebody formats the column, and "when is the
 * next birthday" is a question this shape can answer later without parsing.
 *
 * ## `users.friends_see_birthday` — your side of the page
 *
 * Being connected discloses your birthday, if you gave one. On by default,
 * because it is half the reason the connection exists — a friend list that
 * shows nothing is a roster of names nobody opens twice — and switchable,
 * because "you shared a link once" is not consent to a standing feed.
 *
 * There is deliberately **no switch for lists**, here or anywhere. Two were
 * tried in a day — an account-wide one, then a per-list one — and both were the
 * wrong shape, because a friendship is made by opening any share link and a
 * boolean cannot express consent to an audience that accumulated by accident.
 * Which of your lists a friend sees is decided by an act: you shared it with
 * them by name, or you sent them the link and they opened it. See
 * `wishlist_shares` and App\Services\Social\ListSharer.
 *
 * Neither switch is a permission. A list is reached by its share token and its
 * visibility, exactly as before, and nothing here revokes a link anybody
 * already has. Turning sharing off is what does that, and it still does.
 *
 * ## `friend_invites` — a connection waiting for an account
 *
 * Adding by email cannot connect two accounts when only one exists yet, and the
 * alternative — telling the sender whether that address has an account — is an
 * enumeration oracle for exactly the addresses a gift site holds lists for. So
 * the answer is the same either way and the intent waits here until they sign
 * in. See App\Services\Social\FriendInvites.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->date('birthday')->nullable();
            $table->boolean('friends_see_birthday')->default(true);
        });

        Schema::table('friendships', function (Blueprint $table): void {
            $table->smallInteger('friend_birthday_day')->nullable();
            $table->smallInteger('friend_birthday_month')->nullable();
        });

        Schema::create('friend_invites', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('inviter_id')->constrained('users')->cascadeOnDelete();

            /*
             * Stored lowercased by the caller, and matched that way.
             *
             * `users.email` is unique and case-sensitive in Postgres, so an
             * invite to "Anna@example.com" would sit unmatched forever beside
             * an account registered as "anna@example.com" — a feature that
             * fails silently and only for people who capitalise.
             */
            $table->string('email');

            // What the inviter knows about them, carried across so it is not
            // retyped once the account exists. Day and month, as above.
            $table->smallInteger('birthday_day')->nullable();
            $table->smallInteger('birthday_month')->nullable();

            $table->timestamps();

            // One invite per person per address. Sending it twice is a nudge,
            // not a second connection.
            $table->unique(['inviter_id', 'email']);
            // The lookup at sign-in: "who was waiting for this address?"
            $table->index('email');
        });

        /*
         * Both halves or neither, and a real day of a real month.
         *
         * 29 February is allowed: it is a birthday people have, and the page
         * formats these against a leap year for exactly that reason. The upper
         * bound is 31 rather than per-month, because "30 February" is a typo
         * nobody makes through a day-and-month picker and a CHECK that encodes
         * the Gregorian calendar is a CHECK somebody will have to read.
         */
        DB::statement(
            'ALTER TABLE friendships ADD CONSTRAINT friendships_birthday_check CHECK (
                (friend_birthday_day IS NULL) = (friend_birthday_month IS NULL)
                AND (friend_birthday_day IS NULL OR friend_birthday_day BETWEEN 1 AND 31)
                AND (friend_birthday_month IS NULL OR friend_birthday_month BETWEEN 1 AND 12)
            )'
        );

        DB::statement(
            'ALTER TABLE friend_invites ADD CONSTRAINT friend_invites_birthday_check CHECK (
                (birthday_day IS NULL) = (birthday_month IS NULL)
                AND (birthday_day IS NULL OR birthday_day BETWEEN 1 AND 31)
                AND (birthday_month IS NULL OR birthday_month BETWEEN 1 AND 12)
            )'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('friend_invites');

        Schema::table('friendships', function (Blueprint $table): void {
            $table->dropColumn(['friend_birthday_day', 'friend_birthday_month']);
        });

        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn(['birthday', 'friends_see_birthday']);
        });
    }
};
