<?php

declare(strict_types=1);

use App\Enums\IdeaPriceBand;
use App\Enums\Market;
use App\Enums\OfflineIdeaStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Things people typed onto their lists by hand, as gift ideas for others.
 *
 * One row per idea per market: a normalised key that at least five different
 * people's offline items fold to (see App\Services\Ideas\IdeaKey), and, once a
 * person has approved it, the wording that is shown. See
 * docs/features/offline-ideas.md.
 *
 * A new table and nothing else, so it is safe on production whatever the size
 * of the others: no existing table is touched.
 *
 * Status and price band are strings with a CHECK rather than Postgres enums,
 * as everywhere here: a value added later is then a plain ALTER that can run
 * inside a transaction.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('offline_ideas', function (Blueprint $table) {
            $table->id();
            $table->string('market', 8);

            // What the typed titles fold to: "Kookworkshop!" and "kook
            // workshop" are both `kookworkshop`. Never shown to a visitor.
            $table->string('key', 120);

            // One way people spelled it, for the reviewer only, so they can
            // write the wording. Emptied once the idea is decided: after that
            // nothing anybody typed is kept here.
            $table->string('sample_title', 160)->nullable();

            // The wording a visitor sees, written or accepted by the reviewer.
            $table->string('title', 120)->nullable();

            $table->string('status', 16)->default(OfflineIdeaStatus::Pending->value);

            // `interest:cooking`, `recipient:father`, `occasion:birthday`: the
            // same strings products are tagged with, so a brief meets both.
            $table->jsonb('tags')->default('[]');
            $table->string('price_band', 8)->nullable();

            // How many different people wrote it at the last count. For the
            // reviewer's ordering; never shown to a visitor.
            $table->unsignedInteger('owners')->default(0);
            $table->timestampTz('last_seen_at', 0)->nullable();

            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('decided_at', 0)->nullable();
            $table->timestamps();

            $table->unique(['market', 'key']);
            $table->index(['status', 'market']);
        });

        $quoted = fn (array $values) => implode(',', array_map(fn (string $v) => "'".$v."'", $values));

        DB::statement('ALTER TABLE offline_ideas ADD CONSTRAINT offline_ideas_market_check CHECK (market IN ('.$quoted(Market::values()).'))');
        DB::statement('ALTER TABLE offline_ideas ADD CONSTRAINT offline_ideas_status_check CHECK (status IN ('.$quoted(OfflineIdeaStatus::values()).'))');
        DB::statement('ALTER TABLE offline_ideas ADD CONSTRAINT offline_ideas_price_band_check CHECK (price_band IS NULL OR price_band IN ('.$quoted(IdeaPriceBand::values()).'))');
        // An approved idea always has wording: it is the only thing shown.
        DB::statement("ALTER TABLE offline_ideas ADD CONSTRAINT offline_ideas_approved_has_title CHECK (status <> 'approved' OR (title IS NOT NULL AND btrim(title) <> ''))");
    }

    public function down(): void
    {
        Schema::dropIfExists('offline_ideas');
    }
};
