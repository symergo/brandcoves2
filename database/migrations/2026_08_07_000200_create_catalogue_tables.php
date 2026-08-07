<?php

declare(strict_types=1);

use App\Enums\Availability;
use App\Enums\IdentityKind;
use App\Enums\Market;
use App\Enums\ProductStatus;
use App\Enums\Source;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * THE CENTRAL MODELLING DECISION
 *
 * `products` rows are OFFERS, not products: one row = one merchant selling one
 * thing in one market. `product_groups` rows are PHYSICAL PRODUCTS. Search
 * results, product pages and gift picks all operate on groups; offers hang off
 * them. That split is what makes offer comparison possible at all.
 *
 * Enum-ish columns are plain strings with a CHECK constraint rather than native
 * Postgres enums: altering a PG enum cannot run inside a transaction, which
 * turns every future value addition into a deployment hazard.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('merchants', function (Blueprint $table) {
            $table->id();
            $table->string('source');
            // Stable key within the source: the Awin advertiser id, or
            // 'bol' / 'amazon' for the single-merchant integrations.
            $table->string('external_id');
            $table->string('name');
            // Derived from the merchant's own deep link, never from the affiliate
            // tracking URL — a tracking URL points at the network's domain, which
            // yields the wrong name and the wrong favicon.
            $table->string('domain')->nullable();
            $table->string('logo_url', 1024)->nullable();
            // Some merchants inflate the "was" price so everything looks
            // discounted. Those are excluded from the Daily Picks discount lane.
            $table->boolean('trusts_reference_price')->default(true);
            $table->boolean('enabled')->default(true);
            $table->timestamps();

            $table->unique(['source', 'external_id']);
        });

        Schema::create('feeds', function (Blueprint $table) {
            $table->id();
            $table->string('source')->default(Source::Awin->value);
            $table->string('external_feed_id');
            $table->string('market');
            $table->foreignId('merchant_id')->nullable()->constrained()->nullOnDelete();
            $table->string('label');
            $table->boolean('enabled')->default(true);
            // Per-feed column overrides for feeds that deviate from Awin's defaults.
            $table->jsonb('column_map')->nullable();
            $table->timestampTz('last_run_at')->nullable();
            $table->integer('last_row_count')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();

            $table->unique(['source', 'external_feed_id', 'market']);
            $table->index(['enabled', 'market']);
        });

        Schema::create('product_groups', function (Blueprint $table) {
            $table->id();
            // Identity is scoped to the market deliberately. The same product
            // ingested for two markets has different tax, shipping and
            // availability, so those offers are not interchangeable — merging
            // them lets a foreign price masquerade as the cheapest one.
            $table->string('market');
            // Validated GTIN-13, or "brand|normalised-title" for the fallback path.
            $table->string('identity_key');
            $table->string('identity_kind');

            // Denormalised from the best offer so a results page is one query.
            $table->text('title');
            $table->string('slug');
            $table->string('brand')->nullable();
            $table->string('image_url', 1024)->nullable();
            $table->string('category')->nullable();

            $table->unsignedBigInteger('best_offer_id')->nullable();
            $table->integer('offer_count')->default(0);
            $table->integer('merchant_count')->default(0);

            // Cents. Integer because floats accumulate error across the min and
            // median aggregates that drive "cheapest offer" and discount badges.
            $table->integer('min_price')->nullable();
            $table->integer('max_price')->nullable();
            // 30-day median, recomputed nightly; the reference for discount badges.
            $table->integer('median_price')->nullable();

            $table->boolean('in_stock')->default(false);

            // --- Gift Whisperer ---
            $table->boolean('giftable')->nullable();
            // Why a group was rejected — explainable in admin without re-running
            // the classification pass.
            $table->string('giftable_reason')->nullable();
            // 0-100. Ranks for the opposite of what retailers rank for.
            $table->float('surprise_score')->nullable();
            $table->jsonb('surprise_breakdown')->nullable();

            $table->timestampTz('first_seen_at')->useCurrent();
            $table->timestamps();

            $table->unique(['market', 'identity_key']);
            $table->index(['market', 'slug']);
            $table->index(['market', 'surprise_score']);
        });

        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->string('source');
            // Awin aw_product_id, bol product id, Amazon ASIN.
            $table->string('external_id');
            $table->string('market');
            $table->foreignId('merchant_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('feed_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('group_id')->nullable()->constrained('product_groups')->nullOnDelete();

            $table->text('title');
            $table->text('description')->nullable();
            $table->string('brand')->nullable();
            $table->string('merchant_category')->nullable();

            // Cents.
            $table->integer('price')->nullable();
            // Merchant's claimed "was" price. Only trusted per merchants.trusts_reference_price.
            $table->integer('reference_price')->nullable();
            $table->string('currency', 3)->default('EUR');

            $table->string('image_url', 1024)->nullable();
            // Affiliate tracking URL. Scheme-validated before it is ever used in
            // a redirect — these come from third-party feeds and a javascript:
            // URL would otherwise sail straight through HTML escaping.
            $table->text('affiliate_url');
            // The merchant's own product URL — the only reliable source of their domain.
            $table->text('merchant_deep_link')->nullable();

            $table->string('availability')->default(Availability::Unknown->value);
            // Raw feed value; normalised to a GTIN-13 in product_groups.identity_key.
            $table->string('ean')->nullable();
            $table->float('commission_rate')->nullable();

            $table->string('status')->default(ProductStatus::Active->value);

            $table->timestampTz('first_seen_at')->useCurrent();
            $table->timestampTz('last_seen_at')->useCurrent();
            $table->timestamps();

            $table->unique(['source', 'external_id', 'market']);
            $table->index(['group_id', 'price']);
            $table->index(['market', 'status']);
            $table->index('merchant_id');
            // Pruning selector: rows a feed stopped returning.
            $table->index(['status', 'last_seen_at']);
        });

        Schema::create('price_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->integer('price');
            $table->string('availability');
            $table->timestampTz('captured_at')->useCurrent();
            // Explicit UTC date rather than an expression index. date_trunc() on
            // a timestamptz is not IMMUTABLE (it reads the session TimeZone), so
            // Postgres refuses to index it — and a column makes the one-sample-
            // per-day rule visible instead of buried in an index definition.
            $table->date('captured_on');

            $table->index(['product_id', 'captured_at']);
        });

        // Corpus-wide statistics for surprise scoring. Stored because they are
        // far too expensive to recompute per request.
        Schema::create('category_stats', function (Blueprint $table) {
            $table->id();
            $table->string('market');
            $table->string('category');
            $table->integer('product_count')->default(0);
            $table->integer('brand_count')->default(0);
            $table->integer('median_price')->nullable();
            $table->integer('p10_price')->nullable();
            $table->integer('p90_price')->nullable();
            // 0-1. Share of the whole catalogue this category represents.
            $table->float('share')->default(0);
            $table->timestampTz('computed_at')->useCurrent();

            $table->unique(['market', 'category']);
        });

        Schema::create('brand_stats', function (Blueprint $table) {
            $table->id();
            $table->string('market');
            $table->string('brand');
            $table->integer('product_count')->default(0);
            $table->smallInteger('merchant_count')->default(0);
            $table->float('share')->default(0);
            $table->timestampTz('computed_at')->useCurrent();

            $table->unique(['market', 'brand']);
        });

        $this->addChecks();
        $this->addPartialIndexes();
    }

    private function addChecks(): void
    {
        $markets = $this->quoted(Market::values());
        $sources = $this->quoted(Source::values());

        DB::statement("ALTER TABLE merchants ADD CONSTRAINT merchants_source_check CHECK (source IN ($sources))");
        DB::statement("ALTER TABLE feeds ADD CONSTRAINT feeds_source_check CHECK (source IN ($sources))");
        DB::statement("ALTER TABLE feeds ADD CONSTRAINT feeds_market_check CHECK (market IN ($markets))");
        DB::statement("ALTER TABLE products ADD CONSTRAINT products_source_check CHECK (source IN ($sources))");
        DB::statement("ALTER TABLE products ADD CONSTRAINT products_market_check CHECK (market IN ($markets))");
        DB::statement('ALTER TABLE products ADD CONSTRAINT products_availability_check CHECK (availability IN ('.$this->quoted(Availability::values()).'))');
        DB::statement('ALTER TABLE products ADD CONSTRAINT products_status_check CHECK (status IN ('.$this->quoted(ProductStatus::values()).'))');
        DB::statement("ALTER TABLE product_groups ADD CONSTRAINT product_groups_market_check CHECK (market IN ($markets))");
        DB::statement('ALTER TABLE product_groups ADD CONSTRAINT product_groups_identity_kind_check CHECK (identity_kind IN ('.$this->quoted(IdentityKind::values()).'))');
        DB::statement("ALTER TABLE category_stats ADD CONSTRAINT category_stats_market_check CHECK (market IN ($markets))");
        DB::statement("ALTER TABLE brand_stats ADD CONSTRAINT brand_stats_market_check CHECK (market IN ($markets))");

        // Prices are cents and can never be negative. A negative price would
        // sort to the top of every "cheapest offer" query.
        DB::statement('ALTER TABLE products ADD CONSTRAINT products_price_nonneg CHECK (price IS NULL OR price >= 0)');
        DB::statement('ALTER TABLE price_history ADD CONSTRAINT price_history_price_nonneg CHECK (price >= 0)');
    }

    private function addPartialIndexes(): void
    {
        // The gift engine's retrieval predicate, in column order.
        DB::statement('CREATE INDEX product_groups_giftable_idx ON product_groups (market, in_stock, min_price) WHERE giftable = true');

        // Offer comparison only cares about groups carrying more than one merchant.
        DB::statement('CREATE INDEX product_groups_multi_offer_idx ON product_groups (market, merchant_count) WHERE merchant_count > 1');

        DB::statement('CREATE INDEX products_ean_idx ON products (market, ean) WHERE ean IS NOT NULL');

        // One price sample per product per day. Ingestion runs hourly and we do
        // not want 24 identical rows a day across a 60k catalogue.
        DB::statement('CREATE UNIQUE INDEX price_history_daily_idx ON price_history (product_id, captured_on)');
    }

    /** @param list<string> $values */
    private function quoted(array $values): string
    {
        return implode(', ', array_map(fn (string $v) => "'".$v."'", $values));
    }

    public function down(): void
    {
        Schema::dropIfExists('brand_stats');
        Schema::dropIfExists('category_stats');
        Schema::dropIfExists('price_history');
        Schema::dropIfExists('products');
        Schema::dropIfExists('product_groups');
        Schema::dropIfExists('feeds');
        Schema::dropIfExists('merchants');
    }
};
