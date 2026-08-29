<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\CoveKind;
use App\Enums\Market;
use App\Enums\PublishStatus;
use App\Models\DailyPickSet;
use App\Models\Guide;
use App\Models\GuideItem;
use App\Models\GuideTopic;
use App\Models\ProductGroup;
use App\Services\Content\GuideFold;
use Illuminate\Foundation\Testing\RefreshDatabase;
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

    /** @param array<string, mixed> $attributes */
    private function guide(array $attributes = []): Guide
    {
        return Guide::create(array_merge([
            'market' => Market::BeNl->value,
            'slug' => 'beste-koptelefoons',
            'title' => 'Beste koptelefoons',
            'kind' => 'buying',
            'intro' => 'Een selectie van [[brand:Sony]] tot budget.',
            'body_md' => "Let op pasvorm.\n\nEn op ruisonderdrukking.",
            'source_queries' => ['koptelefoon', 'headphones'],
            'source_volume' => 180,
            'meta_description' => 'De beste koptelefoons van dit jaar.',
            'focus_keyphrase' => 'koptelefoons',
            'faq' => [['q' => 'Bluetooth of kabel?', 'a' => 'Allebei prima.']],
            'status' => PublishStatus::Published->value,
            'published_at' => now()->subMonths(3),
            'last_checked_at' => now()->subMonth(),
        ], $attributes));
    }

    private function itemsOn(Guide $guide, int $count = 3): void
    {
        foreach (range(1, $count) as $rank) {
            GuideItem::create([
                'guide_id' => $guide->id,
                'group_id' => ProductGroup::factory()->create()->id,
                'rank' => $rank,
                'editorial_copy' => "Waarom nummer {$rank} hier staat.",
                'verdict' => "Beste voor {$rank}",
                'unavailable' => $rank === 3,
            ]);
        }
    }

    #[Test]
    public function a_guide_keeps_every_word_it_had(): void
    {
        $guide = $this->guide();

        app(GuideFold::class)->run();

        $edition = DailyPickSet::query()->where('folded_from_guide_id', $guide->id)->firstOrFail();

        $this->assertSame(CoveKind::Guide, $edition->kind);
        $this->assertSame($guide->market->value, $edition->market->value);
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
            $guide->published_at->toDateTimeString(),
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

        $edition = DailyPickSet::query()->where('folded_from_guide_id', $guide->id)->firstOrFail();
        $picks = $edition->picks()->get();

        $this->assertCount(3, $picks);

        $original = $guide->items()->get();

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
            'guide_id' => $guide->id,
        ]);

        app(GuideFold::class)->run();

        $edition = DailyPickSet::query()->where('folded_from_guide_id', $guide->id)->firstOrFail();

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
            (int) DB::table('guide_topics')->where('guide_id', $guide->id)->value('edition_id'),
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

        $edition = DailyPickSet::query()->where('folded_from_guide_id', $guide->id)->firstOrFail();

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
        $edition = DailyPickSet::query()->where('folded_from_guide_id', $guide->id)->firstOrFail();
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
                DailyPickSet::query()->where('folded_from_guide_id', $guide->id)->value('slug'),
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
            'guide_id' => $guide->id,
        ]);

        app(GuideFold::class)->run();

        $edition = DailyPickSet::query()->where('folded_from_guide_id', $guide->id)->firstOrFail();

        // The "read this next" link was a foreign reference and is now a
        // self-reference. `guide_id` is left alone until nothing reads it.
        $this->assertSame($edition->id, $daily->fresh()->featured_cove_id);
    }
}
