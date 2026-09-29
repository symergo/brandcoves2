<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\Market;
use App\Models\ProductGroup;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Undoing the gender split of the relations (2026-09-29): both of a pair
 * becomes the combined tag, one of a pair becomes the combined tag and a
 * gender. Run on data written the split's way, since the suite migrates an
 * empty database. See docs/features/gift-gender.md.
 */
class RelationsMergedMigrationTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function both_of_a_pair_merge_and_one_of_a_pair_keeps_its_gender(): void
    {
        $both = ProductGroup::factory()->forMarket(Market::BeNl)->create([
            'gift_tags' => ['interest:gardening', 'recipient:grandmother', 'recipient:grandfather'],
            'crowd_tags' => ['recipient:male_host', 'recipient:female_host'],
        ]);
        $omaOnly = ProductGroup::factory()->forMarket(Market::BeNl)->create([
            'gift_tags' => ['interest:home', 'recipient:grandmother'],
        ]);
        $sonOnly = ProductGroup::factory()->forMarket(Market::BeNl)->create([
            'gift_tags' => ['recipient:son', 'recipient:mother'],
        ]);

        $this->migrate();

        $this->assertEqualsCanonicalizing(['interest:gardening', 'recipient:grandparent'], $both->fresh()->giftTags());
        $this->assertEqualsCanonicalizing(['recipient:host'], $both->fresh()->crowdTags());
        $this->assertEqualsCanonicalizing(['interest:home', 'recipient:grandparent', 'gender:female'], $omaOnly->fresh()->giftTags());
        $this->assertEqualsCanonicalizing(['recipient:child', 'gender:male', 'recipient:mother'], $sonOnly->fresh()->giftTags());
    }

    #[Test]
    public function gendered_pages_counts_and_the_idea_go_and_the_coves_get_their_relation_back(): void
    {
        DB::table('gift_landings')->insert([
            ['market' => 'be-nl', 'recipient' => 'grandmother', 'interest' => null, 'path' => '/be-nl/gift-ideas/for/oma', 'brief' => '{}', 'product_count' => 9, 'checked_at' => now(), 'created_at' => now(), 'updated_at' => now()],
            ['market' => 'be-nl', 'recipient' => 'mother', 'interest' => null, 'path' => '/be-nl/gift-ideas/for/mama', 'brief' => '{}', 'product_count' => 9, 'checked_at' => now(), 'created_at' => now(), 'updated_at' => now()],
        ]);
        $plan = DB::table('cove_plans')->insertGetId([
            'market' => 'be-nl', 'kind' => 'persona', 'slug' => 'voor-oma-en-opa', 'title' => 'Voor oma en opa',
            'status' => 'draft', 'brief' => json_encode(['interests' => ['gardening']]),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $group = ProductGroup::factory()->forMarket(Market::BeNl)->create();
        DB::table('crowd_picks')->insert([
            ['market' => 'be-nl', 'context' => 'recipient:son', 'group_id' => $group->id, 'owners' => 5],
            ['market' => 'be-nl', 'context' => 'recipient:mother', 'group_id' => $group->id, 'owners' => 5],
        ]);
        DB::table('feature_ideas')->insert([
            'seed_key' => 'relations-by-gender', 'title' => json_encode(['nl' => 'Oma of opa']), 'body' => json_encode(['nl' => '…']),
            'language' => 'nl', 'status' => 'done', 'moderation' => 'published', 'source' => 'seed', 'sort' => 97,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->migrate();

        $this->assertSame(['mother'], DB::table('gift_landings')->pluck('recipient')->all());
        $this->assertSame(
            ['interests' => ['gardening'], 'relationship' => 'grandparent'],
            json_decode((string) DB::table('cove_plans')->where('id', $plan)->value('brief'), true),
        );
        $this->assertSame(['recipient:mother'], DB::table('crowd_picks')->pluck('context')->all());
        $this->assertFalse(DB::table('feature_ideas')->where('seed_key', 'relations-by-gender')->exists());
    }

    /**
     * Runs the data half again. The suite has already added the column, so
     * the schema half is skipped by dropping and re-adding it around the run.
     */
    private function migrate(): void
    {
        DB::statement('ALTER TABLE recipients DROP CONSTRAINT IF EXISTS recipients_gender_check');
        DB::statement('ALTER TABLE recipients DROP COLUMN IF EXISTS gender');
        DB::statement('ALTER TABLE user_tastes DROP CONSTRAINT IF EXISTS user_tastes_gender_check');
        DB::statement('ALTER TABLE user_tastes DROP COLUMN IF EXISTS gender');

        (require database_path('migrations/2026_09_29_000300_relations_merged_gender_asked.php'))->up();

        $this->assertTrue(DB::getSchemaBuilder()->hasColumn('recipients', 'gender'));
    }
}
