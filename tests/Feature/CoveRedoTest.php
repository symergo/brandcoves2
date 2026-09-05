<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\Availability;
use App\Enums\CoveKind;
use App\Enums\Market;
use App\Enums\PlanWriter;
use App\Enums\ProductStatus;
use App\Enums\Reaction;
use App\Enums\Source;
use App\Models\CovePlan;
use App\Models\DailyPickSet;
use App\Models\Merchant;
use App\Models\PickReaction;
use App\Models\Product;
use App\Models\ProductGroup;
use App\Services\Ai\AiClient;
use App\Services\Cove\EditionBuilder;
use App\Services\Cove\RedoOptions;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Doing a Cove again: new products, new words, same URL.
 *
 * Distinct from the rebuild that already existed, which is idempotent by design
 * — it reproduces the page so a scheduler retry and a redeploy are safe. A redo
 * is for the Cove that came out wrong, and it has to actually produce a
 * different one, at an address that has already been linked and indexed.
 *
 * The interesting assertions here are the ones about what does *not* change.
 */
class CoveRedoTest extends TestCase
{
    use RefreshDatabase;

    private Merchant $merchant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::today()->setTime(12, 0));

        $this->merchant = Merchant::create([
            'source' => Source::Awin->value,
            'external_id' => 'shop',
            'name' => 'Shop',
        ]);

        $this->mock(AiClient::class, function ($mock) {
            $mock->shouldReceive('isEnabled')->andReturn(true);
            $mock->shouldReceive('json')->andReturn([
                'title' => 'Koptelefoons',
                'intro' => 'Een selectie.',
                'how_to_choose' => 'Let op pasvorm.',
                'editorial' => 'Iets over koptelefoons.',
            ]);
        });
    }

    #[Test]
    public function a_redone_guide_keeps_its_address_and_changes_its_products(): void
    {
        $this->shelf(12);
        $plan = $this->plan();

        $before = app(EditionBuilder::class)->buildArticle($plan);
        $wasShowing = $before->picks()->pluck('group_id')->all();

        $after = app(EditionBuilder::class)->redo($plan->fresh(), RedoOptions::reselect());

        // Same row, same URL. Everything a reader or a crawler has is unchanged.
        $this->assertSame($before->id, $after->id);
        $this->assertSame('beste-koptelefoons', $after->slug);
        $this->assertSame(1, DailyPickSet::query()->count());

        /*
         * And genuinely different products. The ladder is deterministic, so
         * without passing what is currently on the page as an exclusion this
         * would hand back the identical shortlist and the button would appear
         * to do nothing.
         */
        $nowShowing = $after->picks()->pluck('group_id')->all();
        $this->assertNotEmpty($nowShowing);
        $this->assertEmpty(array_intersect($wasShowing, $nowShowing));
    }

    #[Test]
    public function a_redo_does_not_re_date_a_page_that_has_been_live_for_months(): void
    {
        $this->shelf(12);
        $plan = $this->plan();

        $before = app(EditionBuilder::class)->buildArticle($plan);
        $publishedAt = $before->published_at;

        $this->travel(90)->days();
        $after = app(EditionBuilder::class)->redo($plan->fresh(), RedoOptions::reselect());

        // Restamping would push a months-old guide to the top of every "newest
        // first" shelf on the site and tell a crawler it is new.
        $this->assertSame($publishedAt->toDateTimeString(), $after->published_at->toDateTimeString());
        $this->assertTrue($after->last_checked_at->gt($publishedAt));
    }

    #[Test]
    public function keeping_the_shortlist_rewrites_only_the_words(): void
    {
        $this->shelf(12);
        $plan = $this->plan();

        $chosen = $this->product('Reiskoptelefoon', 24900, 'Bose');
        $plan->items()->create(['group_id' => $chosen->id, 'rank' => 1, 'note' => 'Vouwt plat.']);

        $before = app(EditionBuilder::class)->buildArticle($plan);
        $this->assertSame($chosen->id, $before->picks()->orderBy('rank')->first()->group_id);

        $after = app(EditionBuilder::class)->redo($plan->fresh(), RedoOptions::rewrite());

        // The curator spent an afternoon on this list. "The words are wrong" is
        // not a reason to throw it away.
        $this->assertSame(1, $plan->fresh()->items()->count());
        $this->assertSame('Vouwt plat.', $plan->fresh()->items()->first()->note);
        $this->assertSame($chosen->id, $after->picks()->orderBy('rank')->first()->group_id);
    }

    #[Test]
    public function reselecting_clears_the_shortlist(): void
    {
        $this->shelf(12);
        $plan = $this->plan();
        $plan->items()->create(['group_id' => $this->product('Reiskoptelefoon', 24900, 'Bose')->id, 'rank' => 1]);

        app(EditionBuilder::class)->buildArticle($plan);
        app(EditionBuilder::class)->redo($plan->fresh(), RedoOptions::reselect());

        $this->assertSame(0, $plan->fresh()->items()->count());
    }

    #[Test]
    public function authored_prose_is_discarded_so_the_writer_actually_runs(): void
    {
        $this->shelf(12);
        $plan = $this->plan();
        $plan->update([
            'blurb' => 'Wat je moet weten.',
            'body' => 'Dit heeft iemand zelf geschreven.',
            'writer' => PlanWriter::Authored->value,
        ]);

        $before = app(EditionBuilder::class)->buildArticle($plan->fresh());
        $this->assertSame('planned', $before->editorial_source);

        $after = app(EditionBuilder::class)->redo($plan->fresh(), RedoOptions::reselect());

        /*
         * Authored prose short-circuits the model completely, so leaving it in
         * place would make "rewrite entirely" write nothing at all — the one
         * failure mode where the button reports success and changes no words.
         */
        $this->assertNull($plan->fresh()->body);
        $this->assertSame('ai', $after->editorial_source);
        $this->assertSame('Let op pasvorm.', $after->body);
    }

    #[Test]
    public function a_redo_discards_the_card_copy_but_keeps_the_brief(): void
    {
        $this->shelf(12);
        $plan = $this->plan();
        $plan->update(['writer' => PlanWriter::Authored->value, 'body' => 'Zelf geschreven.']);

        $item = $plan->items()->create([
            'group_id' => $this->product('Reiskoptelefoon', 24900, 'Bose')->id,
            'rank' => 1,
            'note' => 'Vouwt plat.',
            'verdict' => 'Beste voor de trein',
            'copy' => 'Past in elke rugzak.',
        ]);

        // Rewrite, not reselect: the shortlist stays, so this is the case where
        // authored card copy could survive into a page written by somebody else.
        app(EditionBuilder::class)->redo($plan->fresh(), RedoOptions::rewrite());

        $item->refresh();

        /*
         * `copy` is the sentence printed under the card — authored output,
         * exactly like `body`, and redoing the article while keeping last
         * month's captions is the quiet half of "the button changed nothing".
         */
        $this->assertNull($item->copy);

        // The curator's own decisions are not output and are not discarded.
        $this->assertSame('Vouwt plat.', $item->note);
        $this->assertSame('Beste voor de trein', $item->verdict);

        // And the plan is the builder's to write again.
        $this->assertSame(PlanWriter::Builder, $plan->fresh()->writer);
    }

    #[Test]
    public function the_brief_survives_a_redo(): void
    {
        $this->shelf(12);
        $plan = $this->plan();
        $plan->update([
            'build_instructions' => 'Nadruk op reizen.',
            'focus_keyphrase' => 'koptelefoon',
            'queries' => ['koptelefoon'],
        ]);

        app(EditionBuilder::class)->buildArticle($plan->fresh());
        app(EditionBuilder::class)->redo($plan->fresh(), RedoOptions::reselect());

        // Redo discards the output, not the decisions. Making an editor retype
        // the brief to get a second attempt would make the feature useless for
        // the case it exists for.
        $fresh = $plan->fresh();
        $this->assertSame('Nadruk op reizen.', $fresh->build_instructions);
        $this->assertSame('koptelefoon', $fresh->focus_keyphrase);
        $this->assertSame('Beste koptelefoons', $fresh->title);
    }

    #[Test]
    public function a_redo_destroys_the_reader_reactions_it_had_collected(): void
    {
        $this->shelf(12);
        $plan = $this->plan();

        $before = app(EditionBuilder::class)->buildArticle($plan);
        $pick = $before->picks()->first();

        PickReaction::create([
            'pick_id' => $pick->id,
            'reaction' => Reaction::Mindblown->value,
            'identity_hash' => 'abc123',
        ]);

        app(EditionBuilder::class)->redo($plan->fresh(), RedoOptions::reselect());

        /*
         * Pinned rather than lamented. Deleting the picks cascades their
         * reactions and there is no undo, so every surface that offers this has
         * to say so before it runs — which is only enforceable if the fact is
         * written down somewhere that fails when it changes.
         */
        $this->assertSame(0, PickReaction::query()->count());
    }

    // ── Helpers ───────────────────────────────────────────────────────────

    private function plan(): CovePlan
    {
        return CovePlan::create([
            'market' => Market::BeNl->value,
            'kind' => CoveKind::Guide->value,
            'slug' => 'beste-koptelefoons',
            'title' => 'Beste koptelefoons',
            'focus_keyphrase' => 'koptelefoon',
            'status' => 'approved',
        ]);
    }

    /** Twice the shelf a guide needs, so a redo has somewhere else to go. */
    private function shelf(int $count): void
    {
        foreach (range(1, $count) as $i) {
            $this->product("Merk{$i} koptelefoon", 4000 + $i * 2000, "Merk{$i}");
        }
    }

    private function product(string $title, int $price, string $brand): ProductGroup
    {
        $group = ProductGroup::create([
            'market' => Market::BeNl,
            'identity_key' => 'k'.bin2hex(random_bytes(5)),
            'identity_kind' => 'ean',
            'title' => $title,
            'slug' => 'p-'.bin2hex(random_bytes(3)),
            'brand' => $brand,
            'category' => 'audio',
            'image_url' => 'https://img.test/x.jpg',
            'min_price' => $price,
            'merchant_count' => 2,
            'in_stock' => true,
            'giftable' => true,
            'worth_showing' => true,
            'surprise_score' => 50,
        ]);

        Product::create([
            'source' => Source::Awin,
            'market' => Market::BeNl,
            'merchant_id' => $this->merchant->id,
            'group_id' => $group->id,
            'external_id' => 'e'.bin2hex(random_bytes(5)),
            'title' => $title,
            'price' => $price,
            'currency' => 'EUR',
            'affiliate_url' => 'https://example.test/buy',
            'availability' => Availability::InStock,
            'status' => ProductStatus::Active,
            'identity_key' => $group->identity_key,
        ]);

        return $group;
    }
}
