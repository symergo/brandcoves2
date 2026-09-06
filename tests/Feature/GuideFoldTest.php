<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\CoveKind;
use App\Enums\Market;
use App\Enums\PublishStatus;
use App\Models\DailyPickSet;
use App\Models\GuideTopic;
use App\Models\ProductGroup;
use App\Services\Content\GuideFold;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Folding `guides` into the editorial table without losing a sentence.
 *
 * This is the only part of the change that can quietly destroy something.
 * Adding a column either works or fails loudly; moving a hundred published
 * articles into a different table can succeed while dropping the paragraph that
 * made one of them worth reading, and nobody finds out until a reader opens it.
 *
 * So every field is asserted individually rather than by counting rows. A test
 * that only checks "twelve guides in, twelve editions out" passes on a fold that
 * left every body null.
 */
class GuideFoldTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Put the retired tables back for the length of one test.
     *
     * `2026_09_06_000100_the_guides_tables_retire` drops `guides`, `guide_items`
     * and the two `guide_id` columns, so by the time the suite has finished
     * migrating there is nothing here to fold. The fold still has one real
     * execution left — that migration runs it, immediately before the drop, and
     * it is the run that must not lose the hand-written advice drafts — so the
     * coverage is worth more than the tidiness of deleting it.
     *
     * `RefreshDatabase` wraps each test in a transaction and Postgres rolls DDL
     * back with everything else, so this leaves nothing behind.
     */
    protected function setUp(): void
    {
        parent::setUp();

        DB::statement(<<<'SQL'
            CREATE TABLE IF NOT EXISTS guides (
                id bigserial PRIMARY KEY,
                market varchar(255) NOT NULL,
                slug varchar(255) NOT NULL,
                title varchar(255) NOT NULL,
                intro text,
                body_md text,
                source_queries jsonb NOT NULL DEFAULT '[]'::jsonb,
                source_volume integer NOT NULL DEFAULT 0,
                meta_description varchar(255),
                focus_keyphrase varchar(255),
                faq jsonb,
                status varchar(255) NOT NULL DEFAULT 'draft',
                published_at timestamp(0) with time zone,
                last_checked_at timestamp(0) with time zone,
                created_at timestamp(0) without time zone,
                updated_at timestamp(0) without time zone,
                kind varchar(255) NOT NULL DEFAULT 'buying',
                UNIQUE (market, slug)
            )
        SQL);

        DB::statement(<<<'SQL'
            CREATE TABLE IF NOT EXISTS guide_items (
                id bigserial PRIMARY KEY,
                guide_id bigint NOT NULL REFERENCES guides(id) ON DELETE CASCADE,
                group_id bigint NOT NULL REFERENCES product_groups(id) ON DELETE CASCADE,
                rank smallint NOT NULL,
                editorial_copy text,
                verdict varchar(255),
                unavailable boolean NOT NULL DEFAULT false,
                created_at timestamp(0) without time zone,
                updated_at timestamp(0) without time zone,
                UNIQUE (guide_id, rank)
            )
        SQL);

        foreach (['daily_pick_sets', 'guide_topics'] as $table) {
            DB::statement("ALTER TABLE {$table} ADD COLUMN IF NOT EXISTS guide_id bigint REFERENCES guides(id) ON DELETE SET NULL");
        }
    }

    /**
     * A guide row, written straight to the table.
     *
     * The `Guide` and `GuideItem` models are gone — nothing in the application
     * reads these tables any more, which is the whole reason the fold exists.
     * The fold itself works in raw SQL for the same reason, so the test builds
     * its fixtures the way the code under test reads them.
     *
     * @param  array<string, mixed>  $attributes
     * @return int the new guide's id
     */
    private function guide(array $attributes = []): int
    {
        return (int) DB::table('guides')->insertGetId(array_merge([
            'market' => Market::BeNl->value,
            'slug' => 'beste-koptelefoons',
            'title' => 'Beste koptelefoons',
            'kind' => 'buying',
            'intro' => 'Een selectie van [[brand:Sony]] tot budget.',
            'body_md' => "Let op pasvorm.\n\nEn op ruisonderdrukking.",
            'source_queries' => json_encode(['koptelefoon', 'headphones']),
            'source_volume' => 180,
            'meta_description' => 'De beste koptelefoons van dit jaar.',
            'focus_keyphrase' => 'koptelefoons',
            'faq' => json_encode([['q' => 'Bluetooth of kabel?', 'a' => 'Allebei prima.']]),
            'status' => PublishStatus::Published->value,
            'published_at' => now()->subMonths(3),
            'last_checked_at' => now()->subMonth(),
            'created_at' => now(),
            'updated_at' => now(),
        ], $attributes));
    }

    private function itemsOn(int $guideId, int $count = 3): void
    {
        foreach (range(1, $count) as $rank) {
            DB::table('guide_items')->insert([
                'guide_id' => $guideId,
                'group_id' => ProductGroup::factory()->create()->id,
                'rank' => $rank,
                'editorial_copy' => "Waarom nummer {$rank} hier staat.",
                'verdict' => "Beste voor {$rank}",
                'unavailable' => $rank === 3,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    /** @return Collection<int, object> */
    private function itemsOf(int $guideId): Collection
    {
        return DB::table('guide_items')->where('guide_id', $guideId)->orderBy('rank')->get()->values();
    }

    #[Test]
    public function a_guide_keeps_every_word_it_had(): void
    {
        $publishedAt = now()->subMonths(3);
        $guide = $this->guide(['published_at' => $publishedAt]);

        app(GuideFold::class)->run();

        $edition = DailyPickSet::query()->where('folded_from_guide_id', $guide)->firstOrFail();

        $this->assertSame(CoveKind::Guide, $edition->kind);
        $this->assertSame(Market::BeNl->value, $edition->market->value);
        $this->assertSame('beste-koptelefoons', $edition->slug);
        $this->assertNull($edition->drop_date);

        // The prose, field by field. Counting rows would pass on a fold that
        // left all of this null.
        $this->assertSame('Beste koptelefoons', $edition->theme_title);
        $this->assertSame('Een selectie van [[brand:Sony]] tot budget.', $edition->theme_blurb);
        $this->assertSame("Let op pasvorm.\n\nEn op ruisonderdrukking.", $edition->body);
        $this->assertSame('De beste koptelefoons van dit jaar.', $edition->meta_description);
        $this->assertSame('koptelefoons', $edition->focus_keyphrase);
        // assertEquals, not assertSame: `faq` is jsonb, and Postgres stores a
        // json object as a sorted key map rather than as written. The pairs
        // survive; the order they were typed in does not, and never did.
        $this->assertEquals([['q' => 'Bluetooth of kabel?', 'a' => 'Allebei prima.']], $edition->faq);
        $this->assertSame(['koptelefoon', 'headphones'], $edition->source_queries);
        $this->assertSame(180, $edition->source_volume);

        // A published page keeps the date it was published on. Restamping it
        // would re-date every article at once and reshuffle every "newest
        // first" shelf on the site.
        $this->assertSame(
            $publishedAt->toDateTimeString(),
            $edition->published_at->toDateTimeString(),
        );
        $this->assertSame(PublishStatus::Published, $edition->status);
    }

    #[Test]
    public function the_shortlist_keeps_its_order_its_copy_and_its_verdicts(): void
    {
        $guide = $this->guide();
        $this->itemsOn($guide);

        app(GuideFold::class)->run();

        $edition = DailyPickSet::query()->where('folded_from_guide_id', $guide)->firstOrFail();
        $picks = $edition->picks()->get();

        $this->assertCount(3, $picks);

        $original = $this->itemsOf($guide);

        foreach ($picks as $index => $pick) {
            $this->assertSame($original[$index]->group_id, $pick->group_id);
            $this->assertSame($original[$index]->rank, $pick->rank);
            // `editorial_copy` and `blurb` are the same thing under two names.
            $this->assertSame($original[$index]->editorial_copy, $pick->blurb);
            $this->assertSame($original[$index]->verdict, $pick->verdict);
        }

        // Dimmed, not hidden: the third item was out of stock and the guide
        // said so. A Daily would have dropped it.
        $this->assertTrue((bool) $picks[2]->unavailable);
        $this->assertFalse((bool) $picks[0]->unavailable);
    }

    #[Test]
    public function a_guide_that_exists_because_of_a_season_is_folded_as_a_seasonal_cove(): void
    {
        $guide = $this->guide(['slug' => 'beste-halloweenkostuums', 'title' => 'Beste halloweenkostuums']);

        GuideTopic::create([
            'market' => Market::BeNl->value,
            'topic' => 'halloween',
            'origin' => 'seasonal',
            'season_from' => '09-15',
            'season_to' => '10-31',
            'status' => 'published',
            'guide_id' => $guide,
        ]);

        app(GuideFold::class)->run();

        $edition = DailyPickSet::query()->where('folded_from_guide_id', $guide)->firstOrFail();

        /*
         * The distinction was never on the guide — it lived on the topic that
         * commissioned it — so the fold is the one moment it can be recovered.
         */
        $this->assertSame(CoveKind::Seasonal, $edition->kind);
        $this->assertSame('09-15', $edition->season_from);
        $this->assertSame('10-31', $edition->season_to);

        // And the topic now points at what it produced.
        $this->assertSame(
            $edition->id,
            (int) DB::table('guide_topics')->where('guide_id', $guide)->value('edition_id'),
        );
    }

    #[Test]
    public function an_advice_article_stays_an_advice_article(): void
    {
        $guide = $this->guide([
            'slug' => 'hoe-lees-je-een-review',
            'kind' => 'advice',
            'body_md' => 'Een betaalde review leest anders.',
        ]);

        app(GuideFold::class)->run();

        $edition = DailyPickSet::query()->where('folded_from_guide_id', $guide)->firstOrFail();

        // The one kind that may publish with no products at all.
        $this->assertSame(CoveKind::Advice, $edition->kind);
        $this->assertSame(0, $edition->picks()->count());
    }

    #[Test]
    public function a_guide_whose_slug_a_persona_already_holds_is_renamed_not_dropped(): void
    {
        DailyPickSet::create([
            'market' => Market::BeNl->value,
            'kind' => CoveKind::Persona->value,
            'slug' => 'beste-koptelefoons',
            'theme_title' => 'De audiofiel',
            'theme_slug' => 'de-audiofiel',
            'status' => PublishStatus::Published->value,
            'published_at' => now(),
        ]);

        $guide = $this->guide();

        $report = app(GuideFold::class)->run();

        /*
         * The whole reason this runs in PHP. `ON CONFLICT DO NOTHING` would
         * answer a slug collision by deleting a published page.
         */
        $edition = DailyPickSet::query()->where('folded_from_guide_id', $guide)->firstOrFail();
        $this->assertSame('beste-koptelefoons-guide', $edition->slug);
        $this->assertSame(['be-nl/beste-koptelefoons → beste-koptelefoons-guide'], $report['renamed']);

        // And the persona still has its address.
        $this->assertSame(1, DailyPickSet::query()
            ->where('kind', CoveKind::Persona->value)
            ->where('slug', 'beste-koptelefoons')
            ->count());
    }

    #[Test]
    public function the_same_slug_in_two_markets_is_not_a_collision(): void
    {
        $be = $this->guide();
        $nl = $this->guide(['market' => Market::NlNl->value]);

        app(GuideFold::class)->run();

        // Invariant 2: identity is scoped to the market, and so is the slug
        // namespace. Two markets writing about headphones is the normal case.
        foreach ([$be, $nl] as $guide) {
            $this->assertSame(
                'beste-koptelefoons',
                DailyPickSet::query()->where('folded_from_guide_id', $guide)->value('slug'),
            );
        }
    }

    #[Test]
    public function folding_twice_does_not_publish_everything_a_second_time(): void
    {
        $guide = $this->guide();
        $this->itemsOn($guide);

        app(GuideFold::class)->run();
        $report = app(GuideFold::class)->run();

        $this->assertSame(0, $report['editions']);
        $this->assertSame(1, $report['skipped']);

        $this->assertSame(1, DailyPickSet::query()->where('kind', CoveKind::Guide->value)->count());
        $this->assertSame(3, DB::table('daily_picks')->count());
    }

    #[Test]
    public function a_guide_with_no_article_in_it_is_left_where_it_is(): void
    {
        $written = $this->guide(['slug' => 'zo-kies-je-een-koptelefoon', 'body_md' => str_repeat('Echte tekst. ', 40)]);
        $empty = $this->guide(['slug' => 'beste-blauw', 'title' => 'De beste blauw', 'body_md' => '']);
        $null = $this->guide(['slug' => 'beste-jaar', 'title' => 'De beste jaar', 'body_md' => null]);

        $report = app(GuideFold::class)->run(GuideFold::hasAnArticle());

        /*
         * The rule the retire migration folds by, and the reason it exists: the
         * 61 published leftovers on production had no body at all and a title
         * built from one mined search word. Moving those would publish 61 pages
         * that were never worth serving.
         */
        $this->assertSame(1, $report['editions']);
        $this->assertSame(1, DailyPickSet::query()->where('folded_from_guide_id', $written)->count());

        foreach ([$empty, $null] as $abandoned) {
            $this->assertSame(0, DailyPickSet::query()->where('folded_from_guide_id', $abandoned)->count());
        }
    }

    #[Test]
    public function the_daily_that_featured_a_guide_now_points_at_its_cove(): void
    {
        $guide = $this->guide();

        $daily = DailyPickSet::create([
            'market' => Market::BeNl->value,
            'kind' => CoveKind::Daily->value,
            'drop_date' => today()->toDateString(),
            'theme_title' => 'Dinsdag',
            'theme_slug' => 'dinsdag',
            'status' => PublishStatus::Published->value,
            'published_at' => now(),
            'guide_id' => $guide,
        ]);

        app(GuideFold::class)->run();

        $edition = DailyPickSet::query()->where('folded_from_guide_id', $guide)->firstOrFail();

        // The "read this next" link was a foreign reference and is now a
        // self-reference. `guide_id` is left alone until nothing reads it.
        $this->assertSame($edition->id, $daily->fresh()->featured_cove_id);
    }
}
