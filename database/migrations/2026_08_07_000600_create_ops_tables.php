<?php

declare(strict_types=1);

use App\Enums\JobStatus;
use App\Enums\Market;
use App\Enums\Source;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        /**
         * Every search, deduplicated per clock-hour.
         *
         * Not just analytics — this table is the input to the buying-guide
         * builder. What people search for here is the demand signal that decides
         * which guides get written.
         */
        Schema::create('search_log', function (Blueprint $table) {
            $table->id();
            $table->text('query');
            // SHA-256 of the normalised query + market.
            $table->string('query_hash', 64);
            $table->string('market');
            // Truncated to the hour — the upsert key, so one query per hour is
            // one row rather than one row per visitor.
            $table->timestampTz('hour_bucket');
            $table->integer('search_count')->default(1);
            $table->integer('result_count')->default(0);
            // How many searches in this bucket returned nothing — a content gap
            // signal, and often a better guide topic than a popular query.
            $table->integer('zero_result_count')->default(0);
            $table->timestamps();

            $table->unique(['query_hash', 'hour_bucket']);
            // The guide-topic aggregation scan.
            $table->index(['market', 'hour_bucket']);
        });

        /**
         * Chunked job cursor state.
         *
         * A 60k-row feed cannot be ingested in one pass and a redeploy must not
         * lose the work. Each job keeps its position here and resumes rather
         * than restarting.
         */
        Schema::create('ingestion_jobs', function (Blueprint $table) {
            $table->id();
            // e.g. "awin:18755:be-nl" — stable per feed per market.
            $table->string('job_key')->unique();
            $table->string('source');
            $table->string('market')->nullable();
            $table->string('status')->default(JobStatus::Pending->value);
            // Opaque per-connector resume point (byte offset, page token, row index).
            $table->jsonb('cursor')->nullable();
            $table->integer('processed')->default(0);
            $table->integer('total')->nullable();
            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('finished_at')->nullable();
            $table->text('last_error')->nullable();
            $table->integer('attempts')->default(0);
            $table->timestamps();

            $table->index(['status', 'updated_at']);
        });

        /**
         * AI spend, per feature, per day.
         *
         * Two jobs: enforce a per-feature daily cap so a retry loop cannot run up
         * a bill, and make usage visible in admin. A feature with no key here is
         * invisible, so every AI caller must register one.
         */
        Schema::create('ai_usage', function (Blueprint $table) {
            $table->id();
            $table->string('feature_key');
            $table->date('day');
            $table->integer('calls')->default(0);
            $table->bigInteger('input_tokens')->default(0);
            $table->bigInteger('output_tokens')->default(0);
            $table->integer('errors')->default(0);
            $table->timestamps();

            $table->unique(['feature_key', 'day']);
        });

        /**
         * Append-only interaction log: searches, click-outs, gift swaps,
         * shortlists, rejections, reactions.
         *
         * Nothing reads this yet. It exists from day one because the learning
         * loop it enables — what *this* audience finds surprising — needs months
         * of history to be worth anything, and history cannot be backfilled.
         */
        Schema::create('events', function (Blueprint $table) {
            $table->id();
            $table->string('kind');
            $table->string('market')->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->uuid('anon_id')->nullable();
            // Loose on purpose — the shape differs per kind and this is a firehose.
            $table->jsonb('payload')->nullable();
            $table->timestampTz('created_at')->useCurrent();

            $table->index(['kind', 'created_at']);
            $table->index(['user_id', 'created_at']);
        });

        /**
         * Connector credentials, AES-256-GCM encrypted at rest. Editable from
         * admin without a redeploy.
         */
        Schema::create('connector_settings', function (Blueprint $table) {
            $table->id();
            $table->string('source');
            $table->string('key');
            $table->text('encrypted_value');
            $table->timestamps();

            $table->unique(['source', 'key']);
        });

        $this->addChecks();
    }

    private function addChecks(): void
    {
        $markets = $this->quoted(Market::values());
        $sources = $this->quoted(Source::values());

        DB::statement("ALTER TABLE search_log ADD CONSTRAINT search_log_market_check CHECK (market IN ($markets))");
        DB::statement("ALTER TABLE ingestion_jobs ADD CONSTRAINT ingestion_jobs_source_check CHECK (source IN ($sources))");
        DB::statement("ALTER TABLE ingestion_jobs ADD CONSTRAINT ingestion_jobs_market_check CHECK (market IS NULL OR market IN ($markets))");
        DB::statement('ALTER TABLE ingestion_jobs ADD CONSTRAINT ingestion_jobs_status_check CHECK (status IN ('.$this->quoted(JobStatus::values()).'))');
        DB::statement("ALTER TABLE events ADD CONSTRAINT events_market_check CHECK (market IS NULL OR market IN ($markets))");
        DB::statement("ALTER TABLE connector_settings ADD CONSTRAINT connector_settings_source_check CHECK (source IN ($sources))");
    }

    /** @param list<string> $values */
    private function quoted(array $values): string
    {
        return implode(', ', array_map(fn (string $v) => "'".$v."'", $values));
    }

    public function down(): void
    {
        Schema::dropIfExists('connector_settings');
        Schema::dropIfExists('events');
        Schema::dropIfExists('ai_usage');
        Schema::dropIfExists('ingestion_jobs');
        Schema::dropIfExists('search_log');
    }
};
