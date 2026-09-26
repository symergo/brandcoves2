<?php

declare(strict_types=1);

use App\Enums\Market;
use App\Enums\MatchRule;
use App\Enums\MatchStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Matching products that arrived separately (roadmap step 5, engine C in
 * docs/strategy.md; docs/features/product-identity.md and match-review.md).
 *
 * - `identity_aliases`: a merge. Offers whose identity key is `from_key` are
 *   grouped as if their key were `to_key`. The grouper re-derives every offer's
 *   group twice a day from its key, so a merge that only moved `group_id` would
 *   be undone by the next run; it has to live in identity.
 * - `identity_overrides`: a split. One offer is given a key of its own, which
 *   beats both the key it was ingested with and any alias on that key.
 * - `match_candidates`: pairs of products a rule thinks are the same, waiting
 *   for a person. Unique on the ordered pair, so a pair somebody rejected is
 *   never proposed again.
 * - `product_groups.merged_into_id`: the product a merged one now lives in.
 *   The merged row is kept so its old URL and `[[product:N]]` tokens in Cove
 *   prose still resolve.
 * - `products.mpn`: the manufacturer part number, kept from Awin so the model
 *   number rule does not have to guess it from the title.
 *
 * ## Safe on the two largest tables
 *
 * `products` is ~1.9 GB and `product_groups` ~0.7 GB on production, and a
 * failing or slow migration is an outage (Coolify stops the old containers
 * before `migrate` runs). So:
 *
 * - both new columns are nullable with no default: a catalogue-only change,
 *   no table rewrite;
 * - the foreign key on `merged_into_id` is added NOT VALID. Every existing
 *   value is NULL, so it is valid by construction, and skipping VALIDATE
 *   skips a scan of the whole table. New rows are checked all the same;
 * - the one index on `product_groups` is partial (only merged rows, which is
 *   none today) and built CONCURRENTLY, outside a transaction, so it never
 *   blocks writes. No index on `products`: the mpn column is read by a nightly
 *   job that scans the market anyway.
 */
return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        DB::statement('ALTER TABLE products ADD COLUMN IF NOT EXISTS mpn varchar(64)');

        DB::statement('ALTER TABLE product_groups ADD COLUMN IF NOT EXISTS merged_into_id bigint');
        DB::statement(<<<'SQL'
            DO $$
            BEGIN
                IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'product_groups_merged_into_fk') THEN
                    ALTER TABLE product_groups
                        ADD CONSTRAINT product_groups_merged_into_fk
                        FOREIGN KEY (merged_into_id) REFERENCES product_groups (id) ON DELETE SET NULL
                        NOT VALID;
                END IF;
            END $$
        SQL);
        DB::statement('CREATE INDEX CONCURRENTLY IF NOT EXISTS product_groups_merged_into_idx ON product_groups (merged_into_id) WHERE merged_into_id IS NOT NULL');

        $markets = $this->quoted(Market::values());

        // Runs outside a transaction (for the index above), so a run that was
        // interrupted halfway must be able to simply run again. The last table
        // says whether the rest finished; anything before it is a leftover of
        // that same interrupted run and still empty.
        if (Schema::hasTable('match_candidates')) {
            return;
        }
        Schema::dropIfExists('identity_overrides');
        Schema::dropIfExists('identity_aliases');

        Schema::create('identity_aliases', function (Blueprint $table) {
            $table->id();
            $table->string('market', 8);
            $table->string('from_key');
            $table->string('to_key');
            // Free text for the audit trail: "merged in admin", "match review:
            // model number 42125". Not an enum; nothing branches on it.
            $table->string('reason')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['market', 'from_key']);
            // Rewriting aliases that pointed at a product merged away.
            $table->index(['market', 'to_key']);
        });
        DB::statement("ALTER TABLE identity_aliases ADD CONSTRAINT identity_aliases_market_check CHECK (market IN ($markets))");
        // A key aliased to itself would be harmless and confusing; forbid it.
        DB::statement('ALTER TABLE identity_aliases ADD CONSTRAINT identity_aliases_not_self CHECK (from_key <> to_key)');

        Schema::create('identity_overrides', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->unique()->constrained('products')->cascadeOnDelete();
            $table->string('forced_key');
            $table->string('reason')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index('forced_key');
        });

        Schema::create('match_candidates', function (Blueprint $table) {
            $table->id();
            $table->string('market', 8);
            // Always stored with group_a < group_b, so one pair is one row
            // whichever rule found it first.
            $table->foreignId('group_a')->constrained('product_groups')->cascadeOnDelete();
            $table->foreignId('group_b')->constrained('product_groups')->cascadeOnDelete();
            $table->string('rule', 16);
            // Trigram similarity of the two titles, 0..1, for every rule: the
            // review page sorts on it and shows it beside the rule.
            $table->float('score')->default(0);
            // What the rule matched on: the shared barcode or model number.
            $table->string('evidence', 64)->nullable();
            $table->string('status', 16)->default(MatchStatus::Pending->value);
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('decided_at')->nullable();
            $table->timestamps();

            $table->unique(['group_a', 'group_b']);
            $table->index(['status', 'market', 'rule']);
            $table->index('group_b');
        });
        DB::statement("ALTER TABLE match_candidates ADD CONSTRAINT match_candidates_market_check CHECK (market IN ($markets))");
        DB::statement('ALTER TABLE match_candidates ADD CONSTRAINT match_candidates_rule_check CHECK (rule IN ('.$this->quoted(MatchRule::values()).'))');
        DB::statement('ALTER TABLE match_candidates ADD CONSTRAINT match_candidates_status_check CHECK (status IN ('.$this->quoted(MatchStatus::values()).'))');
        DB::statement('ALTER TABLE match_candidates ADD CONSTRAINT match_candidates_ordered CHECK (group_a < group_b)');
    }

    public function down(): void
    {
        Schema::dropIfExists('match_candidates');
        Schema::dropIfExists('identity_overrides');
        Schema::dropIfExists('identity_aliases');
        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS product_groups_merged_into_idx');
        DB::statement('ALTER TABLE product_groups DROP CONSTRAINT IF EXISTS product_groups_merged_into_fk');
        DB::statement('ALTER TABLE product_groups DROP COLUMN IF EXISTS merged_into_id');
        DB::statement('ALTER TABLE products DROP COLUMN IF EXISTS mpn');
    }

    /** @param list<string> $values */
    private function quoted(array $values): string
    {
        return implode(', ', array_map(fn (string $v) => "'".$v."'", $values));
    }
};
