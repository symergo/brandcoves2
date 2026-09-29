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
 * The migration that split the relations by gender (2026-09-29) keeps what
 * was known: a product tagged for "oma of opa" is now tagged for both. Run
 * again on data written the old way, since the suite migrates an empty
 * database. See docs/features/relations-by-gender.md.
 */
class RelationsSplitMigrationTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function a_combined_tag_becomes_both_gendered_tags_and_nothing_else_changes(): void
    {
        $both = ProductGroup::factory()->forMarket(Market::BeNl)->create([
            'gift_tags' => ['interest:gardening', 'recipient:grandparent'],
            'crowd_tags' => ['recipient:host'],
        ]);
        // Already tagged for one of the two: not doubled.
        $already = ProductGroup::factory()->forMarket(Market::BeNl)->create([
            'gift_tags' => ['recipient:grandmother', 'recipient:grandparent', 'recipient:sibling'],
        ]);
        $untouched = ProductGroup::factory()->forMarket(Market::BeNl)->create([
            'gift_tags' => ['interest:cooking', 'recipient:mother'],
        ]);

        $this->migrate();

        $this->assertEqualsCanonicalizing(
            ['interest:gardening', 'recipient:grandmother', 'recipient:grandfather'],
            $both->fresh()->giftTags(),
        );
        $this->assertEqualsCanonicalizing(['recipient:male_host', 'recipient:female_host'], $both->fresh()->crowdTags());
        $this->assertEqualsCanonicalizing(
            ['recipient:grandmother', 'recipient:grandfather', 'recipient:brother', 'recipient:sister'],
            $already->fresh()->giftTags(),
        );
        $this->assertEqualsCanonicalizing(['interest:cooking', 'recipient:mother'], $untouched->fresh()->giftTags());
    }

    #[Test]
    public function pages_plans_and_counts_for_a_combined_relation_go(): void
    {
        DB::table('gift_landings')->insert([
            ['market' => 'be-nl', 'recipient' => 'grandparent', 'interest' => null, 'path' => '/be-nl/gift-ideas/for/oma-of-opa', 'brief' => '{}', 'product_count' => 9, 'checked_at' => now(), 'created_at' => now(), 'updated_at' => now()],
            ['market' => 'be-nl', 'recipient' => 'mother', 'interest' => null, 'path' => '/be-nl/gift-ideas/for/mama', 'brief' => '{}', 'product_count' => 9, 'checked_at' => now(), 'created_at' => now(), 'updated_at' => now()],
        ]);
        $plan = DB::table('cove_plans')->insertGetId([
            'market' => 'be-nl', 'kind' => 'persona', 'slug' => 'voor-oma-en-opa', 'title' => 'Voor oma en opa',
            'status' => 'draft', 'brief' => json_encode(['relationship' => 'grandparent', 'interests' => ['gardening']]),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $group = ProductGroup::factory()->forMarket(Market::BeNl)->create();
        DB::table('crowd_picks')->insert([
            ['market' => 'be-nl', 'context' => 'recipient:child', 'group_id' => $group->id, 'owners' => 5],
            ['market' => 'be-nl', 'context' => 'interest:gaming+recipient:child', 'group_id' => $group->id, 'owners' => 5],
            ['market' => 'be-nl', 'context' => 'recipient:mother', 'group_id' => $group->id, 'owners' => 5],
        ]);

        $this->migrate();

        $this->assertSame(['mother'], DB::table('gift_landings')->pluck('recipient')->all());
        $this->assertSame(['interests' => ['gardening']], json_decode((string) DB::table('cove_plans')->where('id', $plan)->value('brief'), true));
        $this->assertSame(['recipient:mother'], DB::table('crowd_picks')->pluck('context')->all());
    }

    private function migrate(): void
    {
        (require database_path('migrations/2026_09_29_000200_relations_split_by_gender.php'))->up();
    }
}
