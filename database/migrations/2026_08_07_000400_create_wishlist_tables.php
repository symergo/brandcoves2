<?php

declare(strict_types=1);

use App\Enums\AlertState;
use App\Enums\CollaboratorRole;
use App\Enums\ListVisibility;
use App\Enums\Market;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        /**
         * A person you might buy for. This is the Gift Whisperer's input and the
         * thing a gift list is bound to.
         */
        Schema::create('recipients', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('owner_user_id')->nullable()->constrained('users')->cascadeOnDelete();
            $table->uuid('owner_anon_id')->nullable();

            $table->string('name');
            $table->string('relationship')->nullable();
            $table->string('occasion')->nullable();
            $table->string('age_band')->nullable();
            $table->date('birthday')->nullable();

            $table->jsonb('interests')->default(DB::raw("'[]'::jsonb"));
            // practical / playful / beautiful
            $table->string('vibe')->nullable();
            $table->jsonb('values')->default(DB::raw("'[]'::jsonb"));
            // Free-text "avoid" list and things they already own.
            $table->jsonb('avoid')->default(DB::raw("'[]'::jsonb"));
            $table->text('notes')->nullable();

            // Cents.
            $table->integer('budget_min')->nullable();
            $table->integer('budget_max')->nullable();

            // Lets the recipient fill in their own tastes without ever seeing
            // what has been picked for them.
            $table->uuid('share_token')->unique();
            $table->timestamps();

            $table->foreign('owner_anon_id')->references('id')->on('anonymous_identities')->cascadeOnDelete();
            $table->index('owner_user_id');
            $table->index('owner_anon_id');
        });

        Schema::create('wishlists', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('owner_user_id')->nullable()->constrained('users')->cascadeOnDelete();
            $table->uuid('owner_anon_id')->nullable();
            // Null means "a list for myself".
            $table->uuid('recipient_id')->nullable();

            $table->string('title');
            $table->text('description')->nullable();
            $table->string('market');
            $table->string('visibility')->default(ListVisibility::Private->value);
            // Gift lists support claiming; personal lists do not.
            $table->boolean('is_gift_list')->default(false);
            $table->uuid('share_token')->unique();
            $table->timestamps();

            $table->foreign('owner_anon_id')->references('id')->on('anonymous_identities')->cascadeOnDelete();
            $table->foreign('recipient_id')->references('id')->on('recipients')->nullOnDelete();
            $table->index('owner_user_id');
            $table->index('owner_anon_id');
        });

        Schema::create('wishlist_items', function (Blueprint $table) {
            $table->id();
            $table->uuid('wishlist_id');
            $table->foreignId('group_id')->nullable()->constrained('product_groups')->nullOnDelete();

            // Snapshot taken at add time. A feed can drop a product tomorrow;
            // the list must still render what the person actually chose.
            $table->text('snapshot_title');
            $table->string('snapshot_image_url', 1024)->nullable();
            $table->integer('snapshot_price')->nullable();
            $table->text('snapshot_url')->nullable();

            $table->text('note')->nullable();
            $table->smallInteger('priority')->default(0);

            // One-way HMAC of the claimer's identity. The list owner's API
            // response never includes this — otherwise the surprise is spoiled,
            // which is the entire point of a gift list.
            $table->string('claimed_by_hash')->nullable();
            $table->timestampTz('claimed_at')->nullable();
            $table->timestamps();

            $table->foreign('wishlist_id')->references('id')->on('wishlists')->cascadeOnDelete();
            $table->index(['wishlist_id', 'priority']);
            $table->index('group_id');
        });

        Schema::create('wishlist_collaborators', function (Blueprint $table) {
            $table->id();
            $table->uuid('wishlist_id');
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('role')->default(CollaboratorRole::Viewer->value);
            $table->timestamps();

            $table->foreign('wishlist_id')->references('id')->on('wishlists')->cascadeOnDelete();
            $table->unique(['wishlist_id', 'user_id']);
        });

        Schema::create('price_alerts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('group_id')->constrained('product_groups')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('email')->nullable();
            // Cents. Null means "any drop".
            $table->integer('target_price')->nullable();
            // Price when the alert was created — the baseline a drop is measured from.
            $table->integer('baseline_price');
            $table->string('state')->default(AlertState::Active->value);
            $table->timestampTz('notified_at')->nullable();
            $table->timestamps();

            $table->index(['group_id', 'state']);
            $table->index('user_id');
        });

        Schema::create('restock_alerts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('group_id')->constrained('product_groups')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('email')->nullable();
            $table->string('state')->default(AlertState::Active->value);
            $table->timestampTz('notified_at')->nullable();
            $table->timestamps();

            $table->index(['group_id', 'state']);
        });

        Schema::create('notifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('kind');
            $table->string('title');
            $table->text('body')->nullable();
            $table->text('url')->nullable();
            $table->jsonb('payload')->nullable();
            $table->timestampTz('read_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'read_at', 'created_at']);
        });

        $this->addChecks();
    }

    private function addChecks(): void
    {
        $markets = $this->quoted(Market::values());

        DB::statement("ALTER TABLE wishlists ADD CONSTRAINT wishlists_market_check CHECK (market IN ($markets))");
        DB::statement('ALTER TABLE wishlists ADD CONSTRAINT wishlists_visibility_check CHECK (visibility IN ('.$this->quoted(ListVisibility::values()).'))');
        DB::statement('ALTER TABLE wishlist_collaborators ADD CONSTRAINT wishlist_collaborators_role_check CHECK (role IN ('.$this->quoted(CollaboratorRole::values()).'))');
        DB::statement('ALTER TABLE price_alerts ADD CONSTRAINT price_alerts_state_check CHECK (state IN ('.$this->quoted(AlertState::values()).'))');
        DB::statement('ALTER TABLE restock_alerts ADD CONSTRAINT restock_alerts_state_check CHECK (state IN ('.$this->quoted(AlertState::values()).'))');

        // A list and a recipient must belong to someone — exactly one of the two
        // owner columns. Without this an orphaned row is readable by nobody and
        // deletable by nobody.
        DB::statement('ALTER TABLE wishlists ADD CONSTRAINT wishlists_one_owner CHECK (num_nonnulls(owner_user_id, owner_anon_id) = 1)');
        DB::statement('ALTER TABLE recipients ADD CONSTRAINT recipients_one_owner CHECK (num_nonnulls(owner_user_id, owner_anon_id) = 1)');

        // An alert has to have somewhere to send to.
        DB::statement('ALTER TABLE price_alerts ADD CONSTRAINT price_alerts_has_target CHECK (num_nonnulls(user_id, email) >= 1)');
        DB::statement('ALTER TABLE restock_alerts ADD CONSTRAINT restock_alerts_has_target CHECK (num_nonnulls(user_id, email) >= 1)');

        // A product may only appear once per list.
        DB::statement('CREATE UNIQUE INDEX wishlist_items_list_group_idx ON wishlist_items (wishlist_id, group_id) WHERE group_id IS NOT NULL');
    }

    /** @param list<string> $values */
    private function quoted(array $values): string
    {
        return implode(', ', array_map(fn (string $v) => "'".$v."'", $values));
    }

    public function down(): void
    {
        Schema::dropIfExists('notifications');
        Schema::dropIfExists('restock_alerts');
        Schema::dropIfExists('price_alerts');
        Schema::dropIfExists('wishlist_collaborators');
        Schema::dropIfExists('wishlist_items');
        Schema::dropIfExists('wishlists');
        Schema::dropIfExists('recipients');
    }
};
