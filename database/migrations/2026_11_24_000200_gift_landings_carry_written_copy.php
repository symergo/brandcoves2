<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A written introduction and description for a gift landing page (2026-11-24).
 *
 * The 1,700 pages such as /be-nl/gift-ideas/for/partner/mode match "cadeau voor ..."
 * searches exactly, but every one said the same templated sentence, so Google saw
 * near-identical pages. The strongest get words of their own, written in the session
 * and set over the editorial API (POST /gift-landings/copy); a page with written copy
 * is listed in the sitemap. Both nullable: the template stays the fallback. The nightly
 * planner upserts its own columns only, so it never overwrites these.
 *
 * Two nullable columns: a catalogue lookup, no table rewrite, no lock worth the name.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('gift_landings', function (Blueprint $table): void {
            $table->text('intro')->nullable();
            $table->string('meta_description', 160)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('gift_landings', function (Blueprint $table): void {
            $table->dropColumn(['intro', 'meta_description']);
        });
    }
};
