<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Extensions must exist before any migration that indexes with them.
     *
     * - pg_trgm  : typo tolerance on product titles, and the similarity
     *              clustering that turns raw search queries into guide topics.
     * - unaccent : lets "creme" match "crème" — non-negotiable for a catalogue
     *              spanning Dutch, French and Spanish.
     */
    public function up(): void
    {
        DB::statement('CREATE EXTENSION IF NOT EXISTS pg_trgm');
        DB::statement('CREATE EXTENSION IF NOT EXISTS unaccent');
    }

    public function down(): void
    {
        // Deliberately not dropped: other databases on the same cluster may rely
        // on them, and dropping an extension cascades to every dependent index.
    }
};
