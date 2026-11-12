<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * What the Secret Friend rework of 2026-11-12 keeps (docs/features/secret-santa.md,
 * "The game around the draw").
 *
 * Groups: a draw that happens by itself (at a moment, or once enough people
 * are in), and the group this one repeats.
 *
 * Members: the reveal, hints for whoever drew them, how often their list was
 * looked at, their guess at who had them, their thanks, and a mute for the
 * mails that are not the assignment.
 *
 * Questions: a Santa asks, the giftee answers. Who is asked is plain; who
 * asked is encrypted text, for the reason `assigned_member_id` is: asker plus
 * giftee is the pairing.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('secret_santa_groups', function (Blueprint $table) {
            $table->timestampTz('draw_at')->nullable();
            $table->unsignedSmallInteger('draw_when_count')->nullable();
            $table->uuid('previous_group_id')->nullable();
            $table->index(['status', 'draw_at']);
        });

        Schema::table('secret_santa_members', function (Blueprint $table) {
            $table->timestampTz('revealed_at')->nullable();
            $table->jsonb('interests')->default(DB::raw("'[]'::jsonb"));
            $table->text('hints')->nullable();
            $table->unsignedInteger('list_views')->default(0);
            $table->timestampTz('list_viewed_at')->nullable();
            $table->unsignedBigInteger('guess_member_id')->nullable();
            $table->timestampTz('guessed_at')->nullable();
            $table->text('thanks')->nullable();
            $table->timestampTz('thanked_at')->nullable();
            $table->timestampTz('muted_at')->nullable();
        });

        Schema::create('secret_santa_questions', function (Blueprint $table) {
            $table->id();
            $table->uuid('group_id');
            $table->foreignId('giftee_member_id')->constrained('secret_santa_members')->cascadeOnDelete();
            // Encrypted, so no foreign key: see the class comment.
            $table->text('asker_member_id');
            $table->text('question');
            $table->text('answer')->nullable();
            $table->timestampTz('answered_at')->nullable();
            $table->timestamps();

            $table->foreign('group_id')->references('id')->on('secret_santa_groups')->cascadeOnDelete();
            $table->index(['group_id', 'giftee_member_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('secret_santa_questions');

        Schema::table('secret_santa_members', function (Blueprint $table) {
            $table->dropColumn([
                'revealed_at', 'interests', 'hints', 'list_views', 'list_viewed_at',
                'guess_member_id', 'guessed_at', 'thanks', 'thanked_at', 'muted_at',
            ]);
        });

        Schema::table('secret_santa_groups', function (Blueprint $table) {
            $table->dropIndex(['status', 'draw_at']);
            $table->dropColumn(['draw_at', 'draw_when_count', 'previous_group_id']);
        });
    }
};
