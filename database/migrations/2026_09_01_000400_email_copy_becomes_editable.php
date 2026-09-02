<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The words in an email, editable without a deploy.
 *
 * Every mail this site sends had its copy in `lang/{language}/site.php` and its
 * shape in a Blade view, so changing "Check your inbox" to something warmer was
 * a pull request, a build and a release — for a sentence. Meanwhile the *page*
 * copy has been editable since page templates shipped, which left the strangest
 * split in the product: the words a visitor reads on a screen they chose to
 * open are an editor's, and the words that arrive uninvited in their inbox are
 * a developer's.
 *
 * ## One row per template per language
 *
 * Language on the row, not a column of four bodies, for the reason
 * `page_blocks` records: Dutch and French prose do not decompose the same way,
 * and a shared row with four bodies forces a translation parity nobody asked
 * for. A missing language is not a hole here either — it falls back to the
 * shipped copy, which is always complete.
 *
 * ## `enabled` rather than deleting
 *
 * Turning an override off restores the shipped wording without losing what was
 * written. An editor who has second thoughts at 11pm should not have to
 * reconstruct the old version from a screenshot, and the shipped copy is the
 * thing they are comparing against.
 *
 * ## What is not stored
 *
 * The **structure**: the button, its destination, the fallback URL line, the
 * layout. Those are the parts that break silently — a template that lost its
 * button is an email nobody can act on, and a URL pasted into a body is a link
 * that is wrong the moment the market changes. The editor writes the prose and
 * `App\Services\Mail\MailTemplates` supplies everything that has to work.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mail_templates', function (Blueprint $table) {
            $table->id();

            /*
             * The template's key — 'occasion_reminder', 'magic_link'. Declared
             * in code by `MailTemplates::KEYS`, because only code knows which
             * mailable renders which one and what facts it can supply.
             */
            $table->string('key');

            // The language, not the market: `be-nl` and `nl-nl` read the same
            // words, exactly as the lang files are keyed.
            $table->string('language', 5);

            $table->string('subject');
            $table->text('body');
            $table->boolean('enabled')->default(true);

            $table->timestamps();

            $table->unique(['key', 'language']);
        });

        /*
         * A CHECK rather than a foreign key to a table of languages, which does
         * not exist and should not: the four are an application fact, and the
         * list lives in `App\Enums\Market`. Spelled out here so a stray row
         * cannot introduce a fifth that nothing can render.
         */
        DB::statement(
            "ALTER TABLE mail_templates ADD CONSTRAINT mail_templates_known_language
             CHECK (language IN ('en', 'nl', 'fr', 'es'))"
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('mail_templates');
    }
};
